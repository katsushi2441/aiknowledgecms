#!/usr/bin/env python3
import time
import datetime
import requests
import os

# =====================
# CONFIG
# =====================
BASE_URL = "https://aiknowledgecms.exbridge.jp"
KEYWORD_JSON_URL = BASE_URL + "/keyword.json"
AIKNOWLEDGE_API = BASE_URL + "/aiknowledgecms.php"

DATA_DIR = "/var/www/html/aiknowledgecms/data"   # ★ PHP側と同じ
CHECK_INTERVAL = 1
MAX_DAILY_KEYWORDS = 50
TOKEN = "秘密の文字列"

# =====================
# UTIL
# =====================
def today():
    return datetime.date.today().isoformat()

def load_keyword_json():
    r = requests.get(KEYWORD_JSON_URL, timeout=10)
    r.raise_for_status()
    return r.json()

def count_today_created(meta):
    return sum(1 for d in meta.values() if d == today())

def json_exists(keyword):
    path = f"{DATA_DIR}/{today()}_{keyword}.json"
    return os.path.exists(path)

def request_seed(keyword):
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
    return r.json()

# =====================
# MAIN LOOP
# =====================
print("[AIKnowledgeCMS] worker started")

while True:
    try:
        data = load_keyword_json()
        keywords = data.get("keywords", [])
        created  = data.get("created", {})
        counts   = data.get("counts", {})

        today_created = count_today_created(created)

        for kw in keywords:
            if today_created >= MAX_DAILY_KEYWORDS:
                break

            # json の存在はログ用途のみ
            if json_exists(kw):
                print(f"[INFO] json exists {kw}")

            # ★ 判断軸は counts のみ
            if counts.get(kw, 0) > 0:
                print(f"[SKIP] counts>0 {kw}")
                continue

            # counts == 0 → 必ず関連キーワード生成を試みる
            print(f"[SEED] request {kw}")
            result = request_seed(kw)
            status = result.get("status")

            if status == "ok":
                print(f"[OK] seeded {kw}")
                today_created += len(result.get("added", []))

            elif status == "fail":
                print(f"[FAIL] seed failed {kw}", result)

            else:
                print(f"[WARN] unexpected response {kw}", result)

            # keyword.json 再取得
            data = load_keyword_json()
            created = data.get("created", {})
            counts  = data.get("counts", {})
            today_created = count_today_created(created)

    except Exception as e:
        print("[ERROR]", e)

    time.sleep(CHECK_INTERVAL)

