"""videos コレクタ — 外部サイトの動画一覧を監視し、新着をresearchへ積む。

AIKnowledgeCMSの「拡散ハブ」役割 (Phase 4+):
自サイトのネタ集めだけでなく、Kurageエコシステムの他サイト
(kuragev.php のAIショート動画 / YouTubeチャンネル) を監視して、
新着動画への外部リンク付き紹介記事(video_digest)の素材を集める。

research.url のUNIQUE制約が重複防止の台帳を兼ねる。
挿入は古い順(研究テーブルのid昇順=時系列)にし、digestが
「前回紹介した続きから」を id ASC で自然に辿れるようにする。
"""
from __future__ import annotations

import json
import urllib.request
import xml.etree.ElementTree as ET

from core import state

NAME = "videos"


def _insert(conn, tick_id: int, source: str, title: str, url: str, summary: str) -> bool:
    try:
        conn.execute(
            "INSERT INTO research (tick_id, source, title, url, summary, score, created_at)"
            " VALUES (?, ?, ?, ?, ?, 0, ?)",
            (tick_id, source, title[:200], url, (summary or "")[:400], state.now()))
        return True
    except Exception:
        return False  # 既出URL


def _collect_kuragev(conn, tick_id: int, dcfg: dict) -> int:
    api = dcfg.get("api", "http://127.0.0.1:18303/jobs?limit=20")
    public_base = dcfg.get("public_base", "https://kurage.exbridge.jp/kuragev.php?id=")
    req = urllib.request.Request(api, headers={"Accept": "application/json"})
    with urllib.request.urlopen(req, timeout=30) as r:
        jobs = json.loads(r.read()).get("jobs", [])
    added = 0
    # APIは新しい順で返す → 古い順に挿入して research.id を時系列にする
    for job in reversed(jobs):
        if job.get("status") != "done" or not job.get("job_id"):
            continue
        title = job.get("display_title") or job.get("title") or "(無題)"
        summary = (job.get("display_summary") or job.get("summary")
                   or job.get("tweet_text") or "")
        url = public_base + job["job_id"]
        if _insert(conn, tick_id, f"video:{dcfg['name']}", title, url, summary):
            added += 1
    return added


def _collect_youtube(conn, tick_id: int, dcfg: dict) -> int:
    feed = dcfg["feed"]
    req = urllib.request.Request(feed, headers={"User-Agent": "AIKnowledgeCMS-Loop"})
    with urllib.request.urlopen(req, timeout=30) as r:
        root = ET.fromstring(r.read())
    ns = {"a": "http://www.w3.org/2005/Atom", "m": "http://search.yahoo.com/mrss/"}
    entries = root.findall("a:entry", ns)
    added = 0
    # RSSは新しい順 → 古い順に挿入
    for e in reversed(entries):
        title_el = e.find("a:title", ns)
        link_el = e.find("a:link", ns)
        if title_el is None or link_el is None:
            continue
        desc_el = e.find(".//m:description", ns)
        summary = (desc_el.text or "") if desc_el is not None else ""
        if _insert(conn, tick_id, f"video:{dcfg['name']}",
                   title_el.text or "(無題)", link_el.get("href"), summary):
            added += 1
    return added


def collect(cfg: dict, conn, tick_id: int) -> dict:
    """loopfileのdigestsに列挙された動画ソースを巡回。失敗しても他は続行。"""
    total = 0
    per_source = {}
    for dcfg in cfg.get("digests", []):
        name = dcfg["name"]
        try:
            if dcfg["kind"] == "kuragev":
                n = _collect_kuragev(conn, tick_id, dcfg)
            elif dcfg["kind"] == "youtube_rss":
                n = _collect_youtube(conn, tick_id, dcfg)
            else:
                continue
            per_source[name] = n
            total += n
        except Exception as e:
            per_source[name] = 0
            state.record(conn, tick_id, NAME, f"collect_error_{name}", 1,
                         {"error": str(e)[:200]})
    conn.commit()
    state.record(conn, tick_id, NAME, "videos_added", total, per_source)
    return {"added": total, "per_source": per_source}
