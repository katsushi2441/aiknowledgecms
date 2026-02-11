#!/usr/bin/env python3
import requests
import json
from pathlib import Path
import sys
from email.utils import parsedate_to_datetime

DATA_BASE = "https://aiknowledgecms.exbridge.jp/data"
VOICE_PROFILE_URL = "https://airadio.exbridge.jp/voice_profile.json"
TTS_API = "http://exbridge.ddns.net:8002/tts_sample"
UPLOAD_API = "https://aiknowledgecms.exbridge.jp/aiknowledgecms.php"

TIMEOUT = 120


def get_voice_profile():
    r = requests.get(VOICE_PROFILE_URL, timeout=10)
    r.raise_for_status()
    return r.json()


def list_json_files():
    url = (
        "https://aiknowledgecms.exbridge.jp/aiknowledgecms.php"
        "?list_json=1&token=秘密の文字列"
    )
    r = requests.get(url, timeout=10)
    r.raise_for_status()

    data = r.json()
    if not isinstance(data, list):
        return []

    return data


def head_last_modified(url):
    r = requests.head(url, timeout=5)
    if r.status_code != 200:
        return None
    lm = r.headers.get("Last-Modified")
    if not lm:
        return None
    return parsedate_to_datetime(lm)


def needs_regenerate(json_name):
    wav_name = json_name.replace(".json", ".wav")

    json_url = f"{DATA_BASE}/{json_name}"
    wav_url  = f"{DATA_BASE}/{wav_name}"

    json_time = head_last_modified(json_url)
    wav_time  = head_last_modified(wav_url)

    if wav_time is None:
        return True

    if json_time is None:
        return False

    # 5秒以内の差は「同じ生成」とみなす
    if abs((json_time - wav_time).total_seconds()) <= 5:
        return False

    return json_time > wav_time


def load_json(name):
    r = requests.get(f"{DATA_BASE}/{name}", timeout=10)
    r.raise_for_status()
    return r.json()


def tts_generate(text, profile):
    payload = {
        "text": text,
        "speaker": profile["speaker"],
        "speed": profile["speed"],
        "pitch": profile["pitch"],
        "intonation": profile["intonation"],
        "volume": profile["volume"]
    }

    r = requests.post(TTS_API, json=payload, timeout=TIMEOUT)
    r.raise_for_status()
    return r.json()["audio_url"]


def upload_audio(audio_url, json_file):
    url = (
        "https://aiknowledgecms.exbridge.jp/aiknowledgecms.php"
        "?upload_audio=1&token=秘密の文字列"
    )
    r = requests.post(
        url,
        json={
            "audio_url": audio_url,
            "json_file": json_file
        },
        timeout=TIMEOUT
    )
    r.raise_for_status()


def main():
    print("▶ voice profile loading")
    profile = get_voice_profile()

    print("▶ json listing")
    files = list_json_files()

    for jf in files:

        # ★ daily_summary 以外は処理しない
        if not jf.endswith("_daily_summary.json"):
            continue

        if not needs_regenerate(jf):
            continue

        print(f"▶processing {jf}")
        data = load_json(jf)

        script = data.get("summary_text", "").strip()

        if not script:
            print("  - skip (no summary_text)")
            continue

        audio_url = tts_generate(script, profile)
        upload_audio(audio_url, jf)

        print(f"  ✔ regenerated {jf}")

    print("DONE")


if __name__ == "__main__":
    main()

