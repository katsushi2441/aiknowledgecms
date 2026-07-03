"""http_health センサ — 監視対象ページの死活・応答時間を観測する。"""
from __future__ import annotations

import time
import urllib.request

from core import state

NAME = "http_health"
UA = "AIKnowledgeCMS-Loop/0.1 (+https://aiknowledgecms.exbridge.jp/)"


def sense(cfg: dict, conn, tick_id: int) -> dict:
    base = cfg["site"].rstrip("/")
    pages = cfg.get("http_health", {}).get("watch_pages", ["/"])
    healthy = 0
    results = []
    for page in pages:
        url = base + page
        status, ms, err = 0, 0.0, ""
        t0 = time.time()
        try:
            req = urllib.request.Request(url, headers={"User-Agent": UA})
            with urllib.request.urlopen(req, timeout=20) as r:
                r.read(65536)
                status = r.status
        except urllib.error.HTTPError as e:
            status = e.code
        except Exception as e:
            err = str(e)[:200]
        ms = round((time.time() - t0) * 1000)
        ok = 200 <= status < 400
        if ok:
            healthy += 1
        state.record(conn, tick_id, NAME, f"page_status:{page}", status,
                     {"ms": ms, "error": err})
        results.append({"page": page, "status": status, "ms": ms, "error": err})
    state.record(conn, tick_id, NAME, "pages_healthy", healthy,
                 {"total": len(pages)})
    return {"pages": results, "healthy": healthy, "total": len(pages)}
