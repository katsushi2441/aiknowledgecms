#!/usr/bin/env python3
import requests
import json
import re

AIK_API = "https://aiknowledgecms.exbridge.jp/aitrend.php"
TOKEN   = "秘密の文字列"

OLLAMA_URL = "http://localhost:11434/api/generate"
MODEL_NAME = "gemma3:12b"


def log(msg):
    print("[aitrend]", msg, flush=True)


# =========================
# Liveトレンド取得（共通ロジックAPI）
# =========================
def get_trend_keywords():

    log("Requesting live trend keywords...")

    r = requests.get(
        AIK_API,
        params={
            "api_get_trend_keywords": 1
        },
        timeout=60
    )

    log("HTTP Status: " + str(r.status_code))
    log("Response: " + r.text)

    if r.status_code != 200:
        return []

    try:
        return r.json()
    except:
        return []


# =========================
# description存在確認
# =========================
def get_keyword_info(keyword):

    try:
        r = requests.get(
            "https://aiknowledgecms.exbridge.jp/aiknowledgecms.php",
            params={
                "api_get_keyword_info": 1,
                "token": TOKEN,
                "keyword": keyword
            },
            timeout=60
        )

        if r.status_code != 200:
            return None

        return r.json()

    except:
        return None


# =========================
# Ollama生成
# =========================
def generate_description(keyword):

    log("Generating description for: " + keyword)

    prompt = f"""
次のキーワードを技術辞典向けに1文で簡潔に説明してください。
80文字以内。改行しない。

キーワード: {keyword}
"""

    payload = {
        "model": MODEL_NAME,
        "prompt": prompt,
        "stream": False
    }

    try:
        r = requests.post(OLLAMA_URL, json=payload, timeout=120)

        if r.status_code != 200:
            return ""

        data = r.json()

        if "response" not in data:
            return ""

        text = data["response"]
        text = re.sub(r"\s+", " ", text)
        text = text.strip()

        log("Generated: " + text)

        return text

    except Exception as e:
        log("ERROR in generate_description: " + str(e))
        return ""


# =========================
# description更新API
# =========================
def update_description(keyword, description):

    payload = {
        "api_update_keyword_description": 1,
        "token": TOKEN,
        "keyword": keyword,
        "description": description
    }

    try:
        r = requests.post(
            "https://aiknowledgecms.exbridge.jp/aiknowledgecms.php",
            data=payload,
            timeout=60
        )

        log("Update status: " + str(r.status_code))
        log("Update response: " + r.text)

    except Exception as e:
        log("ERROR in update_description: " + str(e))


# =========================
# メイン
# =========================
def main():

    log("=== START aitrend.py ===")

    keywords = get_trend_keywords()

    if not keywords:
        log("No trend keywords found.")
        return

    for kw in keywords:

        log("Checking keyword: " + kw)

        info = get_keyword_info(kw)

        if not info:
            log("Cannot get keyword info. Skipping.")
            continue

        if info.get("description"):
            log("Already has description. Skipping.")
            continue

        desc = generate_description(kw)

        if desc:
            update_description(kw, desc)
        else:
            log("Skip update (empty description)")

    log("=== DONE ===")


if __name__ == "__main__":
    main()
