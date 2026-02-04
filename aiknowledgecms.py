#!/usr/bin/env python3
import argparse
import datetime
import requests

# =====================
# 設定
# =====================
PHP_URL = "https://aiknowledgecms.exbridge.jp/aiknowledgecms.php"
TOKEN   = "秘密の文字列"   # PHP側と合わせる

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
# PHP 実行
# =====================
params = {
    "generate": "1",
    "date": target_date,
    "token": TOKEN,
}

try:
    r = requests.get(PHP_URL, params=params, timeout=300)
    r.raise_for_status()
    print(r.text.strip())
except Exception as e:
    print("ERROR:", e)

