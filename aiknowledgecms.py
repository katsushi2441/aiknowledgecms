#!/usr/bin/env python3
import argparse
import datetime
import requests
import json
import time

# =====================
# LOG UTIL
# =====================
def log(msg):
    now = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    print(f"[{now}] {msg}", flush=True)

# =====================
# 設定
# =====================
AIKNOWLEDGE_API = "https://aiknowledgecms.exbridge.jp/aiknowledgecms.php"
KEYWORD_JSON_URL = "https://aiknowledgecms.exbridge.jp/keyword.json"
DAILY_SUMMARY_URL = "https://aiknowledgecms.exbridge.jp/daily_summary.php"
TOKEN = "秘密の文字列"

# =====================
# 引数処理
# =====================
parser = argparse.ArgumentParser()
parser.add_argument("--date", help="YYYY-MM-DD（省略時は今日）")
args = parser.parse_args()

if args.date:
    target_date = args.date
else:
    target_date = (datetime.date.today() - datetime.timedelta(days=1)).isoformat()

log("===== aiknowledgecms.py started =====")
log(f"[INFO] target_date = {target_date}")

# =====================
# 0. cleanup keywords
# =====================
log("[STEP] cleanup keywords start")

try:
    start = time.time()

    r = requests.post(
        AIKNOWLEDGE_API,
        data={
            "api_cleanup_keywords": "1",
            "token": TOKEN
        },
        timeout=60
    )
    r.raise_for_status()

    result = r.json()
    elapsed = time.time() - start

    log(f"[API] cleanup response ({elapsed:.2f}s)")

    if result.get("status") == "ok":
        deleted = result.get("deleted", [])
        if deleted:
            log(f"[CLEANUP] Deleted {len(deleted)} keywords")
            for kw in deleted:
                log(f" - {kw}")
        else:
            log("[CLEANUP] No keywords deleted")
    else:
        log(f"[CLEANUP] Failed response: {result}")

except Exception as e:
    log(f"[ERROR] cleanup: {e}")

# =====================
# 1. キーワードリスト取得
# =====================
log("[STEP] load keyword.json")

try:
    start = time.time()

    r = requests.get(KEYWORD_JSON_URL, timeout=10)
    r.raise_for_status()
    data = r.json()

    keywords_dict = data.get("keywords", {})
    elapsed = time.time() - start

    log(f"[INFO] Loaded {len(keywords_dict)} keywords ({elapsed:.2f}s)")

except Exception as e:
    log(f"[ERROR] keyword.json: {e}")
    exit(1)

# =====================
# 2. 各キーワードで生成
# =====================
log("[STEP] generate daily json start")

for kw in keywords_dict.keys():

    log(f"[GENERATE] keyword: {kw}")

    try:
        start = time.time()

        r = requests.post(
            AIKNOWLEDGE_API,
            data={
                "api_generate_daily": "1",
                "keyword": kw,
                "token": TOKEN
            },
            timeout=300
        )
        r.raise_for_status()

        result = r.json()
        elapsed = time.time() - start

        status = result.get("status")

        log(f"[API] generate_daily ({elapsed:.2f}s) status={status}")

    except Exception as e:
        log(f"[ERROR] generate {kw}: {e}")

# =====================
# 3. daily_summary 生成
# =====================
log("[STEP] daily_summary start")

try:
    start = time.time()

    r = requests.get(
        DAILY_SUMMARY_URL,
        params={"date": target_date},
        timeout=300
    )
    r.raise_for_status()

    elapsed = time.time() - start

    log(f"[OK] daily_summary generated ({elapsed:.2f}s)")

except Exception as e:
    log(f"[ERROR] daily_summary: {e}")


# =====================
# 4. news2audio 実行
# =====================
import subprocess

log("[STEP] news2audio start")

try:
    start = time.time()

    result = subprocess.run(
        ["python3", "news2audio.py", "--date", target_date],
        capture_output=True,
        text=True,
        check=True
    )

    elapsed = time.time() - start

    log(f"[OK] news2audio finished ({elapsed:.2f}s)")
    if result.stdout:
        log("[news2audio stdout]")
        log(result.stdout.strip())

except subprocess.CalledProcessError as e:
    log("[ERROR] news2audio failed")
    log(e.stderr)
except Exception as e:
    log(f"[ERROR] news2audio: {e}")


log("===== aiknowledgecms.py finished =====")

