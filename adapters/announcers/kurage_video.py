"""kurage_video アナウンサ — 公開記事から約2分のKurage動画生成を依頼する。

kurage backend (:18303) の /generate_from_blog_url に記事URLを渡すだけ。
生成は非同期(job_id)。動画ページは kuragev.php?id=<job_id>。
"""
from __future__ import annotations

import json
import urllib.request

KURAGE_API = "http://localhost:18303"
VIDEO_PAGE = "https://kurage.exbridge.jp/kuragev.php?id="


def announce(cfg: dict, title: str, url: str, body_md: str) -> dict:
    req = urllib.request.Request(
        f"{KURAGE_API}/generate_from_blog_url",
        data=json.dumps({"url": url}).encode(),
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=30) as r:
        data = json.loads(r.read())
    job_id = data.get("job_id", "")
    return {"job_id": job_id,
            "video_page": VIDEO_PAGE + job_id if job_id else ""}
