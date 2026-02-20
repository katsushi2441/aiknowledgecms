#!/usr/bin/env python3
import subprocess
import requests
from datetime import datetime, timedelta
import time
import os

BASE_URL = "https://aiknowledgecms.exbridge.jp/data"
RTMP_URL = "rtmp://localhost/live/airadio"
MAX_DAYS = 10

def get_latest_urls():
    urls = []
    today = datetime.now().date()

    for i in range(MAX_DAYS):
        d = today - timedelta(days=i)
        filename = f"{d}_daily_summary.wav"
        url = f"{BASE_URL}/{filename}"

        try:
            r = requests.head(url, timeout=5)
            if r.status_code == 200:
                urls.append(url)
        except:
            pass

    return urls

def stream(url):
    filename = os.path.basename(url)
    print(f"\n=== NOW STREAMING: {filename} ===\n")

    cmd = [
        "ffmpeg",
        "-re",
        "-i", url,
        "-c:a", "aac",
        "-b:a", "128k",
        "-f", "flv",
        RTMP_URL
    ]

    subprocess.run(cmd)

def main():
    print("=== DAILY REMOTE LOOP START ===")

    while True:
        urls = get_latest_urls()

        if not urls:
            print("No remote files found. Waiting 60s...")
            time.sleep(60)
            continue

        for u in urls:
            stream(u)

        print("\n=== LOOP COMPLETE. RESTARTING FROM LATEST ===\n")

if __name__ == "__main__":
    main()

