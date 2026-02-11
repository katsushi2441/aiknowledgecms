#!/usr/bin/env python3
import argparse
import datetime
import requests
import json

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
    target_date = datetime.date.today().isoformat()

# =====================
# 1. キーワードリスト取得
# =====================
try:
    r = requests.get(KEYWORD_JSON_URL, timeout=10)
    r.raise_for_status()
    data = r.json()
    keywords_dict = data.get("keywords", {})
except Exception as e:
    print("[ERROR] keyword.json:", e)
    exit(1)

# =====================
# 2. 各キーワードで生成（必要なもののみ）
# =====================
for kw in keywords_dict.keys():
    # ここで既存チェックをしたい場合は追加
    try:
        r = requests.post(
            AIKNOWLEDGE_API,
            data={
                "api_seed": "1",
                "keyword": kw,
                "token": TOKEN
            },
            timeout=300
        )
        r.raise_for_status()
        print(f"[OK] Generated: {kw}")
    except Exception as e:
        print(f"[ERROR] {kw}:", e)

# =====================
# 3. daily_summary 生成
# =====================
try:
    r = requests.get(DAILY_SUMMARY_URL, params={"date": target_date}, timeout=300)
    r.raise_for_status()
    print("[OK] daily_summary.php")
except Exception as e:
    print("[ERROR] daily_summary.php:", e)
