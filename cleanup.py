#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import requests
import sys
import json
from datetime import datetime

PHP_URL = "https://aiknowledgecms.exbridge.jp/aiknowledgecms.php"   # ←実URL
TOKEN   = "秘密の文字列"                             # ←AIKNOWLEDGE_TOKEN

def log(msg):
    now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    print(f"[{now}] {msg}")
    sys.stdout.flush()

def call_api_cleanup_keywords():

    payload = {
        "api_cleanup_keywords_all": "1",
        "token": TOKEN
    }

    log("Sending request to PHP...")
    log(f"URL: {PHP_URL}")
    log(f"Payload: {payload}")

    try:
        r = requests.post(PHP_URL, data=payload, timeout=180)
        log(f"HTTP status: {r.status_code}")
        r.raise_for_status()
    except Exception as e:
        log(f"REQUEST ERROR: {e}")
        return None

    log("Raw response received:")
    print(r.text)

    try:
        parsed = r.json()
        log("Parsed JSON:")
        print(json.dumps(parsed, indent=2, ensure_ascii=False))
    except Exception:
        log("Response is not valid JSON")

    return r.text

if __name__ == "__main__":
    log("cleanup.py started")
    result = call_api_cleanup_keywords()
    log("cleanup.py finished")

