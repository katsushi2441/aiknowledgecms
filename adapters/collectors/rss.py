"""rss コレクタ — RSS/AtomフィードからニッチにマッチするアイテムをDBに蓄積する。

外部依存なし(xml.etree)。URLで重複排除し、niche_keywordsのヒット数をscoreにする。
"""
from __future__ import annotations

import urllib.request
import xml.etree.ElementTree as ET

from core import state

NAME = "rss"
UA = "AIKnowledgeCMS-Loop/0.1 (+https://aiknowledgecms.exbridge.jp/)"
ATOM = "{http://www.w3.org/2005/Atom}"


def _fetch(url: str) -> bytes:
    req = urllib.request.Request(url, headers={"User-Agent": UA})
    with urllib.request.urlopen(req, timeout=30) as r:
        return r.read()


def _parse(data: bytes) -> list[dict]:
    root = ET.fromstring(data)
    items = []
    # RSS 2.0
    for it in root.iter("item"):
        items.append({
            "title": (it.findtext("title") or "").strip(),
            "url": (it.findtext("link") or "").strip(),
            "summary": (it.findtext("description") or "").strip()[:500],
        })
    # Atom
    for it in root.iter(f"{ATOM}entry"):
        link = ""
        for l in it.findall(f"{ATOM}link"):
            if l.get("rel") in (None, "alternate"):
                link = l.get("href", "")
                break
        items.append({
            "title": (it.findtext(f"{ATOM}title") or "").strip(),
            "url": link.strip(),
            "summary": (it.findtext(f"{ATOM}summary") or "").strip()[:500],
        })
    return [i for i in items if i["title"] and i["url"]]


def _score(item: dict, keywords: list[str]) -> float:
    text = (item["title"] + " " + item["summary"]).lower()
    return float(sum(1 for k in keywords if k.lower() in text))


def research(cfg: dict, conn, tick_id: int) -> dict:
    rc = cfg.get("research", {})
    keywords = rc.get("niche_keywords", [])
    limit = int(rc.get("max_items_per_tick", 30))
    added = 0
    fetched = 0
    for feed in rc.get("feeds", []):
        try:
            items = _parse(_fetch(feed))
        except Exception as e:
            state.record(conn, tick_id, NAME, "feed_error", 1,
                         {"feed": feed, "error": str(e)[:200]})
            continue
        fetched += len(items)
        for item in items:
            score = _score(item, keywords)
            if score <= 0:
                continue
            try:
                conn.execute(
                    "INSERT INTO research (tick_id, source, title, url, summary,"
                    " score, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
                    (tick_id, feed, item["title"][:300], item["url"],
                     item["summary"], score, state.now()),
                )
                added += 1
            except Exception:
                pass  # URL重複はスキップ
            if added >= limit:
                break
        if added >= limit:
            break
    conn.commit()
    state.record(conn, tick_id, NAME, "research_added", added, {"fetched": fetched})
    return {"added": added, "fetched": fetched}
