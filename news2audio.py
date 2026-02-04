#!/usr/bin/env python3
import requests
import json
from pathlib import Path
import sys

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


def audio_exists(name):
    wav = name.replace(".json", ".wav")
    r = requests.head(f"{DATA_BASE}/{wav}", timeout=5)
    return r.status_code == 200


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
        if audio_exists(jf):
            continue

        print(f"▶ processing {jf}")
        data = load_json(jf)

        script = data.get("radio_script", "").strip()
        if not script:
            print("  - skip (no script)")
            continue

        audio_url = tts_generate(script, profile)

        upload_audio(audio_url, jf)

        print(f"  ✔ uploaded {jf}")

    print("DONE")


if __name__ == "__main__":
    main()

