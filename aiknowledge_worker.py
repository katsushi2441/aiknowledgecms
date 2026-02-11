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
DATA_DIR = "/var/www/html/aiknowledgecms/data"
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

def count_today_created(keywords_dict):
    """keywords辞書から今日作成されたキーワードの数を数える"""
    return sum(1 for data in keywords_dict.values() if data.get("created") == today())

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

def wait_until_next_day():
    """次の日の0時まで待機"""
    now = datetime.datetime.now()
    tomorrow = now + datetime.timedelta(days=1)
    next_midnight = datetime.datetime.combine(tomorrow.date(), datetime.time.min)
    wait_seconds = (next_midnight - now).total_seconds()
    print(f"[INFO] Waiting until next day ({wait_seconds:.0f} seconds)...")
    time.sleep(wait_seconds)

# =====================
# MAIN LOOP
# =====================
print("[AIKnowledgeCMS] worker started")

while True:
    try:
        data = load_keyword_json()
        keywords_dict = data.get("keywords", {})
        
        today_created = count_today_created(keywords_dict)
        print(f"[AIKnowledgeCMS] Today created: {today_created}")
        
        # ★ 上限チェックをループの外で
        if today_created >= MAX_DAILY_KEYWORDS:
            print(f"[INFO] MAX_DAILY_KEYWORDS ({MAX_DAILY_KEYWORDS}) reached")
            wait_until_next_day()
            continue  # 次の日になったら再度チェック
        
        for kw, kw_data in keywords_dict.items():
            # ★ ループ内でも上限チェック
            if today_created >= MAX_DAILY_KEYWORDS:
                print(f"[INFO] MAX_DAILY_KEYWORDS ({MAX_DAILY_KEYWORDS}) reached")
                break
            
            # JSONファイルの存在確認（ログ用）
            if json_exists(kw):
                print(f"[INFO] json exists: {kw}")
            
            # ★ count が 0 より大きければスキップ
            if kw_data.get("count", 0) > 0:
                print(f"[SKIP] count>0: {kw}")
                continue
            
            # count == 0 → 関連キーワード生成を試みる
            print(f"[SEED] request: {kw}")
            result = request_seed(kw)
            status = result.get("status")
            
            if status == "ok":
                print(f"[OK] seeded: {kw}")
                today_created += len(result.get("added", []))
            elif status == "fail":
                print(f"[FAIL] seed failed: {kw}", result)
            else:
                print(f"[WARN] unexpected response: {kw}", result)
            
            # keyword.json 再取得
            data = load_keyword_json()
            keywords_dict = data.get("keywords", {})
            today_created = count_today_created(keywords_dict)
        
        # ★ forループを抜けた後、上限達成なら次の日まで待機
        if today_created >= MAX_DAILY_KEYWORDS:
            wait_until_next_day()
            continue
            
    except Exception as e:
        print("[ERROR]", e)
    
    time.sleep(CHECK_INTERVAL)
