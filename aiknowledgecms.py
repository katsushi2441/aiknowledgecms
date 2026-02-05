#!/usr/bin/env python3
import argparse
import datetime
import requests

# =====================
# 設定
# =====================
AIKNOWLEDGE_URL = "https://aiknowledgecms.exbridge.jp/aiknowledgecms.php"
DAILY_SUMMARY_URL = "https://aiknowledgecms.exbridge.jp/daily_summary.php"
TOKEN = "秘密の文字列"   # PHP側と合わせる

# =====================
# 引数処理
# =====================
parser = argparse.ArgumentParser()
parser.add_argument(
    "--date",
    help="YYYY-MM-DD（省略時は今日）"
)
args = parser.parse_args()

if args.date:
    target_date = args.date
else:
    target_date = datetime.date.today().isoformat()

# =====================
# 1. 各キーワード知識生成
# =====================
params_knowledge = {
    "generate": "1",
    "date": target_date,
    "token": TOKEN,
}

try:
    r = requests.get(AIKNOWLEDGE_URL, params=params_knowledge, timeout=300)
    r.raise_for_status()
    print("[OK] aiknowledgecms.php")
except Exception as e:
    print("[ERROR] aiknowledgecms.php:", e)
    exit(1)

# =====================
# 2. daily_summary 生成
# =====================
params_summary = {
    "date": target_date,
}

try:
    r = requests.get(DAILY_SUMMARY_URL, params=params_summary, timeout=300)
    r.raise_for_status()
    print("[OK] daily_summary.php")
except Exception as e:
    print("[ERROR] daily_summary.php:", e)

