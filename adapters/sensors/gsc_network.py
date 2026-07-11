"""gsc_network センサ — 自分以外も含む全プロパティの「検索流入が伸びている
クエリと受けページ」を検知し、記事化の題材(research)に積む。

背景(2026-07-11): oss.phpのOfficeCLI流入増を人間が目視で見つけて記事化を
指示する、という流れをLOOP自身にやらせる。kurage.exbridge.jp等の他プロパティ
のGSCを毎tick観測し、直近7日で前週比の伸びたクエリを見つけたら、
「そのクエリの解説記事+受けページへの送客リンク」の題材として積む。
1クエリ1回まで(research.urlのUNIQUEで自然に抑制)。
"""
from __future__ import annotations

import json
import time
import urllib.parse

from core import state
from adapters.sensors.gsc import _auth, _query_api, _add_research, _is_writable_query

NAME = "gsc_network"


def _window(days_back_start: int, days_back_end: int) -> tuple[str, str]:
    fmt = lambda d: time.strftime("%Y-%m-%d", time.localtime(time.time() - d * 86400))
    return fmt(days_back_start), fmt(days_back_end)


def _query_pages(site: str, token: str, qp: str, start: str, end: str) -> dict:
    """クエリ→{clicks, impressions, page} の集計(受けページはクリック最多)。"""
    rows = _query_api(site, token, qp, {
        "startDate": start, "endDate": end,
        "dimensions": ["query", "page"], "rowLimit": 1000,
    })
    agg: dict[str, dict] = {}
    for r in rows:
        q, page = r["keys"][0], r["keys"][1]
        a = agg.setdefault(q, {"clicks": 0, "impressions": 0, "page": page, "page_clicks": -1})
        a["clicks"] += r["clicks"]
        a["impressions"] += r["impressions"]
        if r["clicks"] > a["page_clicks"]:
            a["page_clicks"] = r["clicks"]
            a["page"] = page
    return agg


def sense(cfg: dict, conn, tick_id: int) -> dict:
    nc = cfg.get("gsc_network", {})
    props = nc.get("properties", [])
    if not props:
        return {"skipped": True}
    try:
        auth = _auth(cfg.get("gsc", {}))
    except Exception:
        auth = None
    if auth is None:
        state.record(conn, tick_id, NAME, "gscnet_skipped", 1, {"reason": "認証手段なし"})
        return {"skipped": True}
    token, qp = auth

    min_clicks = int(nc.get("min_clicks", 2))
    min_imp = int(nc.get("min_impressions", 40))
    rise = float(nc.get("rise_factor", 1.5))
    budget = int(nc.get("max_new_per_tick", 3))

    # GSCデータは2日遅れ: 直近7日窓 vs その前7日窓
    cur_s, cur_e = _window(9, 2)
    prev_s, prev_e = _window(16, 9)

    added = 0
    candidates = 0
    for site in props:
        host = urllib.parse.urlparse(site).netloc
        try:
            cur = _query_pages(site, token, qp, cur_s, cur_e)
            prev = _query_pages(site, token, qp, prev_s, prev_e)
        except Exception as e:
            state.record(conn, tick_id, NAME, "gscnet_error", 1,
                         {"site": site, "error": str(e)[:200]})
            continue
        rising = []
        for q, a in cur.items():
            p = prev.get(q, {"clicks": 0, "impressions": 0})
            grew_clicks = a["clicks"] >= min_clicks and a["clicks"] >= rise * max(p["clicks"], 1)
            grew_imp = a["impressions"] >= min_imp and a["impressions"] >= rise * max(p["impressions"], 1)
            if grew_clicks or grew_imp:
                rising.append((q, a, p))
        rising.sort(key=lambda x: -(x[1]["clicks"] * 100 + x[1]["impressions"]))
        candidates += len(rising)
        for q, a, p in rising:
            if added >= budget:
                break
            if not _is_writable_query(q):
                continue
            if _add_research(
                    conn, tick_id, "gsc_network",
                    f"検索クエリ「{q}」が{host}で伸びている",
                    f"gscnet://{host}/{urllib.parse.quote(q)}",
                    json.dumps({
                        "query": q, "host": host, "page": a["page"],
                        "clicks_7d": a["clicks"], "impressions_7d": a["impressions"],
                        "clicks_prev7d": p["clicks"], "impressions_prev7d": p["impressions"],
                    }, ensure_ascii=False),
                    11.0 + a["clicks"]):
                added += 1
                state.record(conn, tick_id, NAME, "gscnet_rising_added", 1,
                             {"query": q, "host": host, "page": a["page"],
                              "clicks_7d": a["clicks"], "prev": p["clicks"]})

    state.record(conn, tick_id, NAME, "gscnet_candidates", candidates)
    state.record(conn, tick_id, NAME, "gscnet_added", added)
    return {"candidates": candidates, "added": added}
