#!/usr/bin/env python3
import time
import datetime
import requests
import os
import subprocess

# =====================
# CONFIG
# =====================
BASE_URL = "https://aiknowledgecms.exbridge.jp"
KEYWORD_JSON_URL = BASE_URL + "/keyword.json"
AIKNOWLEDGE_API = BASE_URL + "/aiknowledgecms.php"
DATA_DIR = "/var/www/html/aiknowledgecms/data"
CHECK_INTERVAL = 1
MAX_DAILY_KEYWORDS = 50
TOKEN = "秘密の文字列"

# =====================
# LOG UTIL
# =====================
def log(msg):
    now = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    print(f"[{now}] {msg}", flush=True)

# =====================
# UTIL
# =====================
def today():
    return datetime.date.today().isoformat()

def load_keyword_json():
    r = requests.get(KEYWORD_JSON_URL, timeout=10)
    r.raise_for_status()
    return r.json()

def count_today_created(keywords_dict):
    return sum(1 for data in keywords_dict.values() if data.get("created") == today())

def json_exists(keyword):
    path = f"{DATA_DIR}/{today()}_{keyword}.json"
    return os.path.exists(path)

def request_seed(keyword):
    start = time.time()
    r = requests.post(
        AIKNOWLEDGE_API,
        data={
            "api_seed": "1",
            "keyword": keyword,
            "token": TOKEN
        },
        timeout=300
    )
    r.raise_for_status()
    elapsed = time.time() - start
    log(f"[API] seed response received ({elapsed:.2f}s)")
    return r.json()

def wait_until_next_day():
    now = datetime.datetime.now()
    tomorrow = now + datetime.timedelta(days=1)
    next_midnight = datetime.datetime.combine(tomorrow.date(), datetime.time.min)
    wait_seconds = (next_midnight - now).total_seconds()
    log(f"[WAIT] Sleeping until next day ({wait_seconds:.0f}s)")
    time.sleep(wait_seconds)

# =====================
# MAIN LOOP
# =====================
log("===== AIKnowledgeCMS worker started =====")

while True:
    try:
        log("----- LOOP START -----")

        data = load_keyword_json()
        keywords_dict = data.get("keywords", {})

        log(f"[INFO] Loaded keywords: {len(keywords_dict)}")

        today_created = count_today_created(keywords_dict)
        log(f"[INFO] Today created count: {today_created}")

        if today_created >= MAX_DAILY_KEYWORDS:
            log(f"[LIMIT] MAX_DAILY_KEYWORDS reached ({MAX_DAILY_KEYWORDS})")
            log("[RUN] Executing aiknowledgecms.py")

            result = subprocess.run(
                ["python3", "aiknowledgecms.py"],
                capture_output=True,
                text=True
            )

            log("[aiknowledgecms.py STDOUT]")
            if result.stdout:
                for line in result.stdout.strip().splitlines():
                    log(line)

            if result.stderr:
                log("[aiknowledgecms.py STDERR]")
                for line in result.stderr.strip().splitlines():
                    log(line)

            wait_until_next_day()
            continue

        for kw, kw_data in keywords_dict.items():

            if today_created >= MAX_DAILY_KEYWORDS:
                log(f"[LIMIT] MAX_DAILY_KEYWORDS reached ({MAX_DAILY_KEYWORDS})")
                break

            log(f"[CHECK] keyword: {kw}")

            if json_exists(kw):
                log(f"[INFO] JSON exists for today: {kw}")

            if kw_data.get("count", 0) > 0:
                log(f"[SKIP] count>0 → {kw}")
                continue

            log(f"[SEED] Requesting seed for: {kw}")

            result = request_seed(kw)
            status = result.get("status")
            added = result.get("added", [])

            log(f"[API RESULT] status={status} added={added}")

            if status == "ok":
                log(f"[OK] Seeded: {kw}")
                today_created += len(added)
                log(f"[INFO] Updated today_created: {today_created}")
            elif status == "fail":
                log(f"[FAIL] Seed failed: {kw}")
            else:
                log(f"[WARN] Unexpected response: {result}")

            data = load_keyword_json()
            keywords_dict = data.get("keywords", {})
            today_created = count_today_created(keywords_dict)

        if today_created >= MAX_DAILY_KEYWORDS:
            log("[RUN] Executing aiknowledgecms.py")
            subprocess.run(["python3", "aiknowledgecms.py"])
            wait_until_next_day()
            continue

    except Exception as e:
        log(f"[ERROR] {e}")

    time.sleep(CHECK_INTERVAL)

