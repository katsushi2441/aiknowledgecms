"""AIKnowledgeCMS Loop — tick ランナー (Phase 1: SENSE / TRIAGE / REPORT)

使い方:
    python3 -m core.loop            # 1 tick 実行(公開・通知あり)
    python3 -m core.loop --dry-run  # 副作用なし(FTP公開・メールをしない)

ガードレール:
    - data/KILL が存在したら何もせず終了する (kill switch)
    - dry-run は観測と判断を行うが、外部への副作用を持たない
"""
from __future__ import annotations

import argparse
import ftplib
import html
import json
import smtplib
import ssl
import sys
import traceback
from email.mime.text import MIMEText
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from core import loopfile, state
from adapters.sensors import gsc, http_health, kurage_clicks, simpletrack
from adapters.collectors import rss

ROOT = Path(__file__).resolve().parents[1]
REPORTS = ROOT / "reports"
KILL = ROOT / "data" / "KILL"

SENSORS = {
    "http_health": http_health,
    "simpletrack": simpletrack,
    "gsc": gsc,
    "kurage_clicks": kurage_clicks,
}


# ---------------------------------------------------------------- triage
def triage(cfg: dict, conn, tick_id: int, sensed: dict) -> dict:
    """観測値をルールで課題キューへ変換する。Phase 1 はルールベース。"""
    th = cfg.get("thresholds", {})
    opened: list[dict] = []
    resolved: list[str] = []

    def _open(fp, severity, title, detail):
        if state.open_issue(conn, tick_id, fp, severity, title, detail):
            opened.append({"fingerprint": fp, "severity": severity, "title": title})

    def _resolve(fp):
        if state.resolve_issue(conn, tick_id, fp):
            resolved.append(fp)

    # ページ死活と応答速度
    for page in sensed.get("http_health", {}).get("pages", []):
        fp_down = f"page_down:{page['page']}"
        fp_slow = f"page_slow:{page['page']}"
        ok = 200 <= page["status"] < 400
        if not ok:
            _open(fp_down, "critical",
                  f"ページ異常: {page['page']} (HTTP {page['status']})",
                  json.dumps(page, ensure_ascii=False))
        else:
            _resolve(fp_down)
            if page["ms"] > int(th.get("slow_page_ms", 4000)):
                _open(fp_slow, "warning",
                      f"応答が遅い: {page['page']} ({page['ms']}ms)",
                      json.dumps(page, ensure_ascii=False))
            else:
                _resolve(fp_slow)

    # PVトレンド (前tick比) — 旧システムの自然減に反応しないよう新システムPVで判定
    st = sensed.get("simpletrack")
    if st is not None:
        prev = state.latest_value(conn, "pv_new_24h", before_tick=tick_id)
        cur = st.get("pv_new_24h", 0)
        drop_pct = int(th.get("pv_drop_pct", 30))
        if prev is not None and prev >= 20 and cur < prev * (1 - drop_pct / 100):
            _open("pv_drop", "warning",
                  f"PV下落: 24h PVが {int(prev)} → {int(cur)} ({drop_pct}%閾値超え)",
                  json.dumps({"prev": prev, "cur": cur}))
        else:
            _resolve("pv_drop")

    # GSCクリック減 (24時間前比 / Phase 4 MEASURE) — 28日移動合計なので
    # 前tick比ではほぼ動かない。1日前の値と比較する。
    gs = sensed.get("gsc")
    if gs is not None and not gs.get("skipped"):
        import time as _time
        cutoff = _time.strftime("%Y-%m-%d %H:%M:%S",
                                _time.localtime(_time.time() - 86400))
        prev24 = state.value_before(conn, "gsc_clicks_28d", cutoff)
        cur_clicks = gs.get("clicks_28d", 0)
        clicks_drop_pct = int(th.get("gsc_clicks_drop_pct", 20))
        if (prev24 is not None and prev24 >= 20
                and cur_clicks < prev24 * (1 - clicks_drop_pct / 100)):
            _open("gsc_clicks_drop", "warning",
                  f"GSCクリック減: 28d合計が24hで {int(prev24)} → {int(cur_clicks)}"
                  f" ({clicks_drop_pct}%閾値超え)",
                  json.dumps({"prev24h": prev24, "cur": cur_clicks}))
        else:
            _resolve("gsc_clicks_drop")

        # 追跡クエリの順位下落 (センサが検出済みのものをissue化)
        drops = gs.get("position_drops", [])
        dropped_fps = set()
        for d in drops:
            fp = f"pos_drop:{d['query']}"
            dropped_fps.add(fp)
            _open(fp, "warning",
                  f"順位下落: 「{d['query']}」が {d['prev']} → {d['cur']} 位",
                  json.dumps(d, ensure_ascii=False))
        # 今回下落していない既存のpos_drop issueは解消扱い
        for row in conn.execute(
                "SELECT fingerprint FROM issues WHERE status='open'"
                " AND fingerprint LIKE 'pos_drop:%'").fetchall():
            if row["fingerprint"] not in dropped_fps:
                _resolve(row["fingerprint"])

    conn.commit()
    return {"opened": opened, "resolved": resolved}


# ---------------------------------------------------------------- create
def maybe_create(cfg: dict, conn, tick_id: int, dry_run: bool,
                 force: bool = False) -> dict:
    """CREATE→ゲート→DISTRIBUTE。日次予算内で1本だけ試行する。

    dry-runではLLMを呼ばない(コストも副作用)。
    """
    result = {"attempted": False, "published": None, "rejected": None, "skipped": ""}
    cc = cfg.get("create")
    if cc is None:
        result["skipped"] = "create未設定"
        return result
    if dry_run and not force:
        result["skipped"] = "dry-run"
        return result

    today = state.now()[:10]
    # digest-記事は別予算(maybe_digest)なので通常記事の予算から除外する
    published_today = conn.execute(
        "SELECT COUNT(*) FROM content WHERE status='published' AND published_at LIKE ?"
        " AND slug NOT LIKE 'digest-%'",
        (today + "%",)).fetchone()[0]
    attempts_today = conn.execute(
        "SELECT COUNT(*) FROM content WHERE created_at LIKE ?"
        " AND slug NOT LIKE 'digest-%'", (today + "%",)).fetchone()[0]
    if not force:
        if published_today >= int(cc.get("articles_per_day", 1)):
            result["skipped"] = f"日次予算達成(published={published_today})"
            return result
        if attempts_today >= int(cc.get("max_attempts_per_day", 2)):
            result["skipped"] = f"試行上限(attempts={attempts_today})"
            return result

    from adapters.generators import agent_article
    from gates import verify_article

    result["attempted"] = True
    draft = agent_article.generate(cfg, conn, tick_id)
    if draft is None:
        result["skipped"] = "素材なし/生成失敗"
        return result

    gate = verify_article.run(cfg, conn, draft)
    if not gate["passed"]:
        reasons = gate["problems"] or [
            (gate.get("verifier") or {}).get("reason", "verifier FAIL")]
        result["rejected"] = {"title": draft["title"], "reasons": reasons}
        state.record(conn, tick_id, "create", "article_rejected", 1,
                     {"slug": draft["slug"], "reasons": reasons,
                      "refresh": bool(draft.get("refresh"))})
        return result

    if draft.get("refresh"):
        # 合格した改稿を公開中の本体行へ反映し、一時ドラフト行を消す。
        # (ゲート不合格時はここに来ないので、公開中の記事は無傷のまま)
        real = draft["real_slug"]
        conn.execute(
            "UPDATE content SET title=?, body_md=?,"
            " gate_result=(SELECT gate_result FROM content WHERE slug=?)"
            " WHERE slug=?",
            (draft["title"][:120], draft["body"], draft["slug"], real))
        conn.execute("DELETE FROM content WHERE slug=?", (draft["slug"],))
        conn.commit()
        draft = {**draft, "slug": real}

    from adapters.publishers import articles_ftp
    url = articles_ftp.publish(cfg, conn, draft, gate)
    if draft.get("refresh"):
        # リライトは既知の記事の更新なのでSNS等への再告知はしない
        announced = {}
    else:
        announced = announce_all(cfg, conn, tick_id, draft["title"], url, draft["body"])
    state.record(conn, tick_id, "create", "article_published", 1,
                 {"slug": draft["slug"], "url": url,
                  "announced": list(announced.keys()),
                  "refresh": bool(draft.get("refresh"))})
    result["published"] = {"title": draft["title"], "url": url,
                           "announced": announced,
                           "refresh": bool(draft.get("refresh"))}
    return result


# ---------------------------------------------------------------- announce
def announce_all(cfg: dict, conn, tick_id: int, title: str, url: str,
                 body_md: str, kind: str = "article",
                 names: list[str] | None = None) -> dict:
    """loopfileのannouncersに列挙されたチャネルへ配信。失敗しても他は続行。

    kind="article": はてな/Bloggerへはチャネル別の衛星記事(別内容+バックリンク)。
    kind="report": 数値改変を避けて原文転載。
    names: チャネルの明示指定(digestは動画から動画を作らないようkurage_videoを外す等)。
    """
    from adapters.announcers import aixsns, hatena_blogger, kurage_video
    channels = {"aixsns": lambda: aixsns.announce(cfg, title, url),
                "hatena_blogger": lambda: hatena_blogger.announce(cfg, title, url, body_md, kind=kind),
                "kurage_video": lambda: kurage_video.announce(cfg, title, url, body_md)}
    results = {}
    for name in (names if names is not None else cfg.get("announcers", ["aixsns"])):
        fn = channels.get(name)
        if fn is None:
            continue
        try:
            results[name] = fn()
        except Exception as e:
            results[name] = {"error": str(e)[:200]}
        state.record(conn, tick_id, "distribute", f"announce_{name}", 1,
                     results[name] if isinstance(results[name], dict) else {})
    conn.commit()
    return results


def maybe_video_insight(cfg: dict, conn, tick_id: int, dry_run: bool) -> list[dict]:
    """Kurage動画の題材を1本ずつ深掘りする単独記事(1日N本、digestとは別予算)。

    video_digest(まとめ紹介・送客目的)を薄めずに、同じ動画素材から
    「知識記事」としての厚みも作ることで、送客と自サイトSEOの両方を伸ばす。
    tick内でmaybe_digestより先に呼ぶことで、情報量の多い動画素材を先取りする。
    """
    results: list[dict] = []
    vi_cfg = cfg.get("create", {}) if cfg.get("create") else {}
    per_day = int(vi_cfg.get("video_insight_per_day", 0))
    if per_day < 1 or dry_run or not cfg.get("digests"):
        return results
    dcfg_by_name = {d["name"]: d for d in cfg.get("digests", [])}
    today = state.now()[:10]
    published_today = conn.execute(
        "SELECT COUNT(*) FROM content WHERE status='published' AND published_at LIKE ?"
        " AND slug LIKE 'insight-%'", (today + "%",)).fetchone()[0]
    max_attempts = int(vi_cfg.get("video_insight_max_attempts_per_day", per_day * 2))
    attempts_today = conn.execute(
        "SELECT COUNT(*) FROM content WHERE created_at LIKE ? AND slug LIKE 'insight-%'",
        (today + "%",)).fetchone()[0]
    if published_today >= per_day or attempts_today >= max_attempts:
        return results
    try:
        from adapters.generators import video_insight
        from gates import verify_article
        draft = video_insight.generate(cfg, conn, tick_id, dcfg_by_name)
        if draft is None:
            return results
        # slugをinsight-接頭辞に統一(日次予算のカウントに使う)
        if not draft["slug"].startswith("insight-"):
            new_slug = f"insight-{draft['slug']}"[:50]
            conn.execute("UPDATE content SET slug=? WHERE slug=?", (new_slug, draft["slug"]))
            conn.commit()
            draft["slug"] = new_slug
        gate = verify_article.run(cfg, conn, draft)
        if not gate["passed"]:
            reasons = gate["problems"] or [
                (gate.get("verifier") or {}).get("reason", "verifier FAIL")]
            state.record(conn, tick_id, "video_insight", "insight_rejected", 1,
                         {"slug": draft["slug"], "reasons": reasons})
            results.append({"rejected": draft["title"], "reasons": reasons})
            return results
        from adapters.publishers import articles_ftp
        url = articles_ftp.publish(cfg, conn, draft, gate)
        announced = announce_all(cfg, conn, tick_id, draft["title"], url, draft["body"],
                                 names=["aixsns", "hatena_blogger"])
        state.record(conn, tick_id, "video_insight", "insight_published", 1,
                     {"slug": draft["slug"], "url": url})
        results.append({"published": draft["title"], "url": url,
                        "announced": list(announced.keys())})
    except Exception:
        traceback.print_exc()
        state.record(conn, tick_id, "video_insight", "insight_error", 1,
                     {"error": traceback.format_exc()[-400:]})
    return results


def maybe_digest(cfg: dict, conn, tick_id: int, dry_run: bool) -> list[dict]:
    """外部動画サイトの新着ダイジェスト記事(1日1本/ソース)。拡散ハブの役割。

    素材はvideosコレクタが積んだresearch(video:{name})。既存の品質ゲートを通し、
    合格したものだけ公開する。動画から動画を作る循環を避けるため
    kurage_videoアナウンサには流さない。
    """
    results: list[dict] = []
    if dry_run:
        return results
    today = state.now()[:10]
    for dcfg in cfg.get("digests", []):
        name = dcfg["name"]
        if int(dcfg.get("per_day", 1)) < 1:
            continue
        already = conn.execute(
            "SELECT COUNT(*) FROM content WHERE slug LIKE ? AND status='published'"
            " AND published_at LIKE ?",
            (f"digest-{name}-%", today + "%")).fetchone()[0]
        if already >= int(dcfg.get("per_day", 1)):
            continue
        try:
            from adapters.generators import video_digest
            from gates import verify_article
            draft = video_digest.generate(cfg, conn, tick_id, dcfg)
            if draft is None:
                continue
            gate = verify_article.run(cfg, conn, draft)
            if not gate["passed"]:
                reasons = gate["problems"] or [
                    (gate.get("verifier") or {}).get("reason", "verifier FAIL")]
                state.record(conn, tick_id, "digest", "digest_rejected", 1,
                             {"source": name, "slug": draft["slug"], "reasons": reasons})
                results.append({"source": name, "rejected": draft["title"],
                                "reasons": reasons})
                continue
            from adapters.publishers import articles_ftp
            url = articles_ftp.publish(cfg, conn, draft, gate)
            announced = announce_all(cfg, conn, tick_id, draft["title"], url,
                                     draft["body"],
                                     names=["aixsns", "hatena_blogger"])
            state.record(conn, tick_id, "digest", "digest_published", 1,
                         {"source": name, "slug": draft["slug"], "url": url,
                          "videos": len(draft["sources"])})
            results.append({"source": name, "published": draft["title"],
                            "url": url, "videos": len(draft["sources"])})
        except Exception:
            traceback.print_exc()
            state.record(conn, tick_id, "digest", "digest_error", 1,
                         {"source": name, "error": traceback.format_exc()[-400:]})
    return results


def maybe_weekly_report(cfg: dict, conn, tick_id: int, dry_run: bool) -> dict | None:
    """週次の一次データレポート(決定的生成・LLM/ゲート不要)を公開する。"""
    wr = cfg.get("weekly_report", {})
    if not wr.get("enabled") or dry_run:
        return None
    from adapters.generators import weekly_report
    if not weekly_report.due(conn):
        return None
    from adapters.publishers import articles_ftp
    draft = weekly_report.generate(cfg, conn, tick_id)
    gate = {"verifier": {"model": "deterministic(自DB実測)"}}
    url = articles_ftp.publish(cfg, conn, draft, gate)
    announced = announce_all(cfg, conn, tick_id, draft["title"], url, draft["body"],
                             kind="report")
    state.record(conn, tick_id, "create", "weekly_report_published", 1,
                 {"url": url, "announced": list(announced.keys())})
    return {"title": draft["title"], "url": url, "announced": announced}


# ---------------------------------------------------------------- report
def build_report_md(cfg, conn, tick_id, sensed, triaged, dry_run, researched=None,
                    created=None, digests=None) -> str:
    lines = [f"# Loop Report — tick {tick_id}", ""]
    lines.append(f"- 実行時刻: {state.now()}")
    lines.append(f"- モード: {'dry-run' if dry_run else 'live'}")
    lines.append("")
    lines.append("## KPI")
    st = sensed.get("simpletrack") or {}
    hh = sensed.get("http_health") or {}
    lines.append(f"- pv_new_24h(新システム): **{st.get('pv_new_24h', 'n/a')}** / 全体 {st.get('pv_24h', 'n/a')}")
    lines.append(f"- uniq_ips_new_24h: **{st.get('uniq_ips_new_24h', 'n/a')}** / 全体 {st.get('uniq_ips_24h', 'n/a')}")
    lines.append(f"- pages_healthy: **{hh.get('healthy', '?')}/{hh.get('total', '?')}**")
    kc = sensed.get("kurage_clicks")
    if kc is not None:
        lines.append(
            f"- kurage動画への送客(24h): **{kc.get('sent_24h', 0)}**"
            f" (記事 {kc.get('article_24h', 0)} / ウィジェット {kc.get('widget_24h', 0)}"
            f" / ref無しリファラ {kc.get('referrer_only_24h', 0)})"
            f" — 動画PV全体 {kc.get('video_pv_24h', 'n/a')}")
    lines.append("")
    if st.get("top"):
        lines.append("## 24h Top URLs")
        for url, c in st["top"]:
            lines.append(f"- {c} pv — {url}")
        lines.append("")
    lines.append("## ページ死活")
    for p in hh.get("pages", []):
        mark = "✅" if 200 <= p["status"] < 400 else "🛑"
        lines.append(f"- {mark} {p['page']} — HTTP {p['status']} / {p['ms']}ms"
                     + (f" / {p['error']}" if p["error"] else ""))
    lines.append("")
    if researched is not None:
        lines.append("## RESEARCH")
        lines.append(f"- 新規素材: {researched.get('added', 0)}件 (取得 {researched.get('fetched', 0)}件)")
        lines.append("")
    if created is not None:
        lines.append("## CREATE / DISTRIBUTE")
        if created.get("published"):
            pub = created["published"]
            ann = pub.get("announced") or {}
            ann_s = "/".join(ann.keys()) if isinstance(ann, dict) else str(ann)
            verb = "記事をリライト公開" if pub.get("refresh") else "記事を公開"
            lines.append(f"- ✅ {verb}: [{pub['title']}]({pub['url']})"
                         + (f" (配信: {ann_s})" if ann_s else ""))
        elif created.get("rejected"):
            rej = created["rejected"]
            lines.append(f"- 🛑 ゲート不合格で非公開: {rej['title']}")
            for r in rej["reasons"][:5]:
                lines.append(f"  - {r}")
        else:
            lines.append(f"- スキップ: {created.get('skipped', '-')}")
        lines.append("")
    if digests:
        lines.append("## DIGEST (拡散ハブ)")
        for d in digests:
            if d.get("published"):
                lines.append(f"- ✅ {d['source']}: [{d['published']}]({d['url']}) ({d['videos']}本を紹介)")
            else:
                lines.append(f"- 🛑 {d['source']}: ゲート不合格 {d.get('rejected', '')}")
        lines.append("")
    lines.append("## TRIAGE")
    lines.append(f"- 新規/再発 issue: {len(triaged['opened'])}")
    for o in triaged["opened"]:
        lines.append(f"  - [{o['severity']}] {o['title']}")
    lines.append(f"- 解決 issue: {len(triaged['resolved'])}")
    lines.append("")
    lines.append("## Open Issues (キュー)")
    rows = state.open_issues(conn)
    if not rows:
        lines.append("- なし 🎉")
    for r in rows:
        lines.append(f"- [{r['severity']}] {r['title']} (初出 tick {r['first_seen_tick']})")
    return "\n".join(lines) + "\n"


HTML_SHELL = """<!doctype html>
<html lang="ja"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{title}</title>
<style>
body{{margin:0;background:#f7f9fc;color:#101828;font-family:-apple-system,"Segoe UI","Hiragino Sans","Noto Sans JP",sans-serif;line-height:1.75}}
.wrap{{max-width:860px;margin:0 auto;padding:34px 20px 60px}}
.card{{background:#fff;border:1px solid #e9edf3;border-radius:16px;padding:28px 30px;box-shadow:0 1px 3px rgba(16,24,40,.05)}}
h1{{font-size:22px;letter-spacing:-.01em;margin:0 0 4px}}
h2{{font-size:15px;color:#4f46e5;letter-spacing:.08em;text-transform:uppercase;margin:26px 0 8px}}
ul{{margin:0;padding-left:20px}} li{{margin:3px 0;font-size:14px;color:#475467}}
li strong{{color:#101828}}
.meta{{font-size:12.5px;color:#98a2b3;margin-bottom:6px}}
a{{color:#4f46e5;text-decoration:none;font-weight:600}}
.nav{{display:flex;justify-content:space-between;margin-bottom:14px;font-size:13px}}
</style></head><body><div class="wrap">
<div class="nav"><a href="index.html">← Loop Reports</a><a href="/aiknowledgecms.html">AIKnowledgeCMS</a></div>
<div class="card">{body}</div>
</div></body></html>
"""


def md_to_simple_html(md: str) -> str:
    out = []
    in_list = False
    import re as _re

    def _inline(esc: str) -> str:
        """太字・markdownリンク・裸URLの自動リンク化。"""
        while "**" in esc:
            esc = esc.replace("**", "<strong>", 1).replace("**", "</strong>", 1)
        # markdownリンクを一旦プレースホルダに退避(裸URL処理と衝突させない)
        anchors: list[str] = []

        def _mk(m):
            anchors.append(f'<a href="{m.group(2)}">{m.group(1)}</a>')
            return f"\x00{len(anchors) - 1}\x00"
        esc = _re.sub(r"\[([^\]]+)\]\((https?://[^\s)]+)\)", _mk, esc)

        # 裸URLをリンク化(末尾の句読点・閉じ括弧はリンク外に残す)
        def _bare(m):
            u = m.group(0)
            trail = ""
            while u and u[-1] in ".,;:)\"'":
                trail = u[-1] + trail
                u = u[:-1]
            return f'<a href="{u}">{u}</a>{trail}'
        esc = _re.sub(r"https?://[!-~]+", _bare, esc)
        return _re.sub("\x00(\\d+)\x00", lambda m: anchors[int(m.group(1))], esc)

    for line in md.splitlines():
        esc = _inline(html.escape(line))
        if esc.startswith("# "):
            out.append(f"<h1>{esc[2:]}</h1>")
        elif esc.startswith("### "):
            if in_list:
                out.append("</ul>"); in_list = False
            out.append(f"<h3>{esc[4:]}</h3>")
        elif esc.startswith("## "):
            if in_list:
                out.append("</ul>"); in_list = False
            out.append(f"<h2>{esc[3:]}</h2>")
        elif esc.lstrip().startswith("- "):
            if not in_list:
                out.append("<ul>"); in_list = True
            out.append(f"<li>{esc.lstrip()[2:]}</li>")
        elif esc.strip() == "":
            if in_list:
                out.append("</ul>"); in_list = False
        else:
            out.append(f'<div class="meta">{esc}</div>')
    if in_list:
        out.append("</ul>")
    return "\n".join(out)


def publish_reports(cfg: dict, conn, tick_id: int, report_md: str) -> list[str]:
    """レポートHTMLを /loop/ にFTP公開する。公開したリモートパスを返す。"""
    env = cfg["_env"]
    remote_dir = cfg["publisher"]["remote_dir"].rstrip("/")
    body = md_to_simple_html(report_md)
    page = HTML_SHELL.format(title=f"Loop Report tick {tick_id} — AIKnowledgeCMS", body=body)

    # index: 直近50tickの一覧
    rows = conn.execute(
        "SELECT id, started_at, dry_run, summary FROM ticks"
        " WHERE finished_at IS NOT NULL OR id = ? ORDER BY id DESC LIMIT 50", (tick_id,)
    ).fetchall()
    items = "\n".join(
        f'<li><a href="tick-{r["id"]}.html">tick {r["id"]}</a>'
        f' <span class="meta">{html.escape(r["started_at"])}'
        f'{" (dry-run)" if r["dry_run"] else ""} — {html.escape(r["summary"] or "")}</span></li>'
        for r in rows if not r["dry_run"] or r["id"] == tick_id
    )
    index_page = HTML_SHELL.format(
        title="Loop Reports — AIKnowledgeCMS",
        body=f"<h1>Loop Reports</h1>"
             f'<div class="meta">このサイトを運用しているエージェントループの実行レポート(自動生成)。</div>'
             f"<ul>{items}</ul>")

    uploaded = []
    with ftplib.FTP(env["FTP_HOST"], timeout=60) as ftp:
        ftp.login(env["FTP_USER"], env["FTP_PASS"])
        try:
            ftp.mkd(remote_dir)
        except ftplib.error_perm:
            pass
        for name, content in [
            (f"tick-{tick_id}.html", page),
            ("latest.html", page),
            ("index.html", index_page),
        ]:
            bio = __import__("io").BytesIO(content.encode("utf-8"))
            ftp.storbinary(f"STOR {remote_dir}/{name}", bio)
            uploaded.append(f"{remote_dir}/{name}")
    return uploaded


# ---------------------------------------------------------------- escalate
def escalate(cfg: dict, opened: list[dict]) -> bool:
    criticals = [o for o in opened if o["severity"] == "critical"]
    if not criticals or "critical_issue_opened" not in cfg.get("escalate_when", []):
        return False
    env = cfg["_env"]
    to = cfg.get("escalation", {}).get("email", "")
    host = env.get("SMTP_HOST"); user = env.get("SMTP_USER") or env.get("SMTP_FROM")
    pw = env.get("SMTP_PASSWORD"); frm = env.get("SMTP_FROM", user)
    if not (to and host and user and pw):
        return False
    body = "AIKnowledgeCMS Loopがcriticalなissueを検出しました。\n\n" + \
        "\n".join(f"- {c['title']}" for c in criticals) + \
        f"\n\nレポート: {cfg['site'].rstrip('/')}/loop/latest.html"
    msg = MIMEText(body, "plain", "utf-8")
    msg["Subject"] = "[AIKnowledgeCMS Loop] critical issue"
    msg["From"] = frm
    msg["To"] = to
    ctx = ssl.create_default_context()
    with smtplib.SMTP_SSL(host, int(env.get("SMTP_PORT", "465")), context=ctx, timeout=30) as s:
        s.login(user, pw)
        s.sendmail(frm, [to], msg.as_string())
    return True


# ---------------------------------------------------------------- tick
LOCK = ROOT / "data" / "LOCK"


def _acquire_lock() -> bool:
    LOCK.parent.mkdir(exist_ok=True)
    if LOCK.exists():
        try:
            pid = int(LOCK.read_text().strip())
            import os
            os.kill(pid, 0)  # 生存確認
            return False     # 実行中のtickがいる
        except (ValueError, ProcessLookupError, PermissionError):
            pass             # 死んだプロセスのロックは奪う
    import os
    LOCK.write_text(str(os.getpid()))
    return True


def run_tick(cfg: dict, dry_run: bool, force_create: bool = False) -> int:
    if KILL.exists():
        print("KILL switch present — loop halted. (data/KILL を削除すると再開)")
        return 2
    if not _acquire_lock():
        print("別のtickが実行中のためスキップ (data/LOCK)")
        return 3

    conn = state.connect()
    tick_id = state.begin_tick(conn, dry_run)
    print(f"tick {tick_id} start (dry_run={dry_run})")

    # SENSE
    sensed: dict = {}
    for name in cfg.get("sensors", []):
        mod = SENSORS.get(name)
        if mod is None:
            print(f"  sensor {name}: unknown — skip")
            continue
        try:
            sensed[name] = mod.sense(cfg, conn, tick_id)
            print(f"  SENSE {name}: ok")
        except Exception:
            print(f"  SENSE {name}: FAILED")
            traceback.print_exc()
            state.record(conn, tick_id, name, "sensor_error", 1,
                         {"error": traceback.format_exc()[-500:]})
    conn.commit()

    # RESEARCH
    researched = None
    if cfg.get("research"):
        try:
            researched = rss.research(cfg, conn, tick_id)
            print(f"  RESEARCH: +{researched['added']} items")
        except Exception:
            print("  RESEARCH: FAILED")
            traceback.print_exc()

    # RESEARCH: 監視中の動画サイト(拡散ハブの素材)
    if cfg.get("digests"):
        try:
            from adapters.collectors import videos
            vres = videos.collect(cfg, conn, tick_id)
            print(f"  RESEARCH videos: +{vres['added']} ({vres['per_source']})")
        except Exception:
            print("  RESEARCH videos: FAILED")
            traceback.print_exc()

    # TRIAGE
    triaged = triage(cfg, conn, tick_id, sensed)
    print(f"  TRIAGE: opened={len(triaged['opened'])} resolved={len(triaged['resolved'])}")

    # CREATE → ゲート → DISTRIBUTE (失敗してもtickは完走させる)
    try:
        created = maybe_create(cfg, conn, tick_id, dry_run, force=force_create)
    except Exception:
        print("  CREATE: FAILED")
        traceback.print_exc()
        state.record(conn, tick_id, "create", "create_error", 1,
                     {"error": traceback.format_exc()[-500:]})
        created = {"attempted": True, "published": None, "rejected": None,
                   "skipped": "実行エラー(create_error参照)"}
    if created.get("published"):
        print(f"  CREATE: published {created['published']['url']}")
    elif created.get("rejected"):
        print(f"  CREATE: rejected ({created['rejected']['reasons'][:2]})")
    else:
        print(f"  CREATE: skip ({created.get('skipped')})")

    # VIDEO INSIGHT: Kurage動画の題材を1本ずつ深掘りする単独記事(digestより先取り)
    try:
        insights = maybe_video_insight(cfg, conn, tick_id, dry_run)
        for v in insights:
            if v.get("published"):
                print(f"  VIDEO_INSIGHT: published {v['url']}")
            else:
                print(f"  VIDEO_INSIGHT: rejected ({v.get('reasons', [])[:2]})")
    except Exception:
        print("  VIDEO_INSIGHT: FAILED")
        traceback.print_exc()

    # DIGEST: 外部動画サイトの新着紹介記事(1日1本/ソース)
    digests = []
    try:
        digests = maybe_digest(cfg, conn, tick_id, dry_run)
        for d in digests:
            if d.get("published"):
                print(f"  DIGEST {d['source']}: published {d['url']} ({d['videos']}本)")
            else:
                print(f"  DIGEST {d['source']}: rejected ({d.get('reasons', [])[:2]})")
    except Exception:
        print("  DIGEST: FAILED")
        traceback.print_exc()

    # 週次一次データレポート(期日が来ていれば)
    try:
        weekly = maybe_weekly_report(cfg, conn, tick_id, dry_run)
        if weekly:
            print(f"  WEEKLY: published {weekly['url']}")
    except Exception:
        print("  WEEKLY: FAILED")
        traceback.print_exc()

    # REPORT
    report_md = build_report_md(cfg, conn, tick_id, sensed, triaged, dry_run,
                                researched=researched, created=created,
                                digests=digests)
    REPORTS.mkdir(exist_ok=True)
    (REPORTS / f"tick-{tick_id}.md").write_text(report_md, encoding="utf-8")

    st = sensed.get("simpletrack") or {}
    hh = sensed.get("http_health") or {}
    summary = (f"pv_new={st.get('pv_new_24h', '?')}/{st.get('pv_24h', '?')} healthy={hh.get('healthy', '?')}/"
               f"{hh.get('total', '?')} open_issues={len(state.open_issues(conn))}")
    state.finish_tick(conn, tick_id, summary)

    if not dry_run:
        uploaded = publish_reports(cfg, conn, tick_id, report_md)
        print(f"  REPORT published: {len(uploaded)} files")
        try:
            from core import dashboard
            dash_url = dashboard.publish(cfg, conn, tick_id)
            print(f"  DASHBOARD updated: {dash_url}")
        except Exception:
            print("  DASHBOARD: FAILED")
            traceback.print_exc()
        try:
            from adapters.publishers import videos_widget
            widget_path = videos_widget.publish(cfg, conn)
            if widget_path:
                print(f"  WIDGET updated: {widget_path}")
        except Exception:
            print("  WIDGET: FAILED")
            traceback.print_exc()
        if escalate(cfg, triaged["opened"]):
            print("  ESCALATE: email sent")
    else:
        print("  (dry-run: 公開・通知はスキップ)")

    print(f"tick {tick_id} done — {summary}")
    LOCK.unlink(missing_ok=True)
    return 0


def main() -> int:
    ap = argparse.ArgumentParser(description="AIKnowledgeCMS loop tick runner")
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--force-create", action="store_true",
                    help="日次予算を無視してCREATEを強制実行(テスト用)")
    ap.add_argument("--loopfile", default=None)
    args = ap.parse_args()
    cfg = loopfile.load(args.loopfile)
    return run_tick(cfg, args.dry_run, force_create=args.force_create)


if __name__ == "__main__":
    sys.exit(main())
