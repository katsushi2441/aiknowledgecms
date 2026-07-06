"""公開ダッシュボード — ループのSQLite実測値から毎tick自動生成する成績表。

「AIが運営するサイトの成績を全部公開する」集客装置。数値は全て実測・決定的生成。
チャートはインラインSVG(依存なし)。2系列: 新システムPV(#2a78d6) / 全体PV(#1baf7a)
— dataviz検証済みパレット。aquaは3:1未満のため直接ラベル+テーブルを併設する。
"""
from __future__ import annotations

import html
import json
import time

from core import state

SERIES_NEW = "#2a78d6"   # 新システムPV
SERIES_ALL = "#1baf7a"   # 全体PV(参考)


def _series(conn, key: str, days: int = 14):
    cutoff = time.strftime("%Y-%m-%d %H:%M:%S",
                           time.localtime(time.time() - days * 86400))
    rows = conn.execute(
        "SELECT value, created_at FROM observations WHERE key=? AND created_at>=?"
        " AND value IS NOT NULL ORDER BY id", (key, cutoff)).fetchall()
    return [(r["created_at"], float(r["value"])) for r in rows]


def _latest(conn, key: str):
    r = conn.execute(
        "SELECT value FROM observations WHERE key=? AND value IS NOT NULL"
        " ORDER BY id DESC LIMIT 1", (key,)).fetchone()
    return None if r is None else r["value"]


def _latest_meta(conn, key: str):
    r = conn.execute(
        "SELECT meta FROM observations WHERE key=? AND meta IS NOT NULL"
        " ORDER BY id DESC LIMIT 1", (key,)).fetchone()
    if r is None:
        return None
    try:
        return json.loads(r["meta"])
    except Exception:
        return None


def _line_chart(new_pts, all_pts) -> str:
    """2系列の折れ線SVG。細いマーク・控えめグリッド・線端に直接ラベル。"""
    W, H, PL, PR, PT, PB = 660, 220, 44, 88, 14, 26
    pts_all = new_pts + all_pts
    if len(new_pts) < 2:
        return '<div class="empty">計測点が溜まると推移グラフが表示されます</div>'
    ymax = max(v for _, v in pts_all) * 1.15 or 1
    t0 = min(t for t, _ in pts_all)
    t1 = max(t for t, _ in pts_all)

    def ts(s):
        return time.mktime(time.strptime(s, "%Y-%m-%d %H:%M:%S"))
    x0, x1 = ts(t0), max(ts(t1), ts(t0) + 1)

    def X(t):
        return PL + (ts(t) - x0) / (x1 - x0) * (W - PL - PR)

    def Y(v):
        return PT + (1 - v / ymax) * (H - PT - PB)

    def poly(pts, color, label):
        d = " ".join(f"{X(t):.1f},{Y(v):.1f}" for t, v in pts)
        lx, ly = X(pts[-1][0]) + 6, Y(pts[-1][1]) + 4
        dots = "".join(
            f'<circle cx="{X(t):.1f}" cy="{Y(v):.1f}" r="8" fill="transparent">'
            f'<title>{html.escape(label)} {int(v)} ({html.escape(t[5:16])})</title></circle>'
            f'<circle cx="{X(t):.1f}" cy="{Y(v):.1f}" r="2.5" fill="{color}"/>'
            for t, v in pts)
        return (f'<polyline points="{d}" fill="none" stroke="{color}" stroke-width="2" '
                f'stroke-linejoin="round" stroke-linecap="round"/>' + dots +
                f'<text x="{lx:.1f}" y="{ly:.1f}" class="dlabel">{html.escape(label)} {int(pts[-1][1])}</text>')

    grid = ""
    for frac in (0.0, 0.5, 1.0):
        v = ymax * frac
        y = Y(v)
        grid += (f'<line x1="{PL}" y1="{y:.1f}" x2="{W-PR}" y2="{y:.1f}" class="grid"/>'
                 f'<text x="{PL-6}" y="{y+4:.1f}" class="alabel" text-anchor="end">{int(v)}</text>')
    xlab = (f'<text x="{PL}" y="{H-6}" class="alabel">{html.escape(t0[5:10])}</text>'
            f'<text x="{W-PR}" y="{H-6}" class="alabel" text-anchor="end">{html.escape(t1[5:10])}</text>')

    return (f'<svg viewBox="0 0 {W} {H}" role="img" aria-label="24時間PVの推移">'
            f'{grid}{xlab}'
            f'{poly(all_pts, SERIES_ALL, "全体")}'
            f'{poly(new_pts, SERIES_NEW, "新システム")}'
            f'</svg>')


def build(cfg: dict, conn, tick_id: int) -> str:
    site = cfg["site"].rstrip("/")
    new_pts = _series(conn, "pv_new_24h")
    all_pts = _series(conn, "pv_24h")

    published = conn.execute(
        "SELECT COUNT(*) FROM content WHERE status='published'").fetchone()[0]
    rejected = conn.execute(
        "SELECT COUNT(*) FROM content WHERE status='rejected'").fetchone()[0]
    open_issues = len(state.open_issues(conn))
    ticks = conn.execute("SELECT COUNT(*) FROM ticks").fetchone()[0]

    tiles = [
        ("新システムPV / 24h", _latest(conn, "pv_new_24h"), "成長KPI(本体)"),
        ("全体PV / 24h", _latest(conn, "pv_24h"), "旧遺産ページ含む参考値"),
        ("Google表示 / 28d", _latest(conn, "gsc_impressions_28d"), "Search Console実測"),
        ("Googleクリック / 28d", _latest(conn, "gsc_clicks_28d"), "Search Console実測"),
        ("公開記事", published, "ゲート通過のみ"),
        ("ゲート却下", rejected, "理由つきで台帳に記録"),
        ("Open課題", open_issues, "TRIAGEキュー"),
        ("累計tick", ticks, "ループ実行回数"),
    ]
    tiles_html = "".join(
        f'<div class="tile"><small>{html.escape(k)}</small>'
        f'<strong>{("—" if v is None else int(v))}</strong>'
        f'<span>{html.escape(note)}</span></div>'
        for k, v, note in tiles)

    # データテーブル(チャートのフォールバック/アクセシビリティ)
    rows = ""
    merged = {}
    for t, v in all_pts:
        merged.setdefault(t[:16], [None, None])[1] = int(v)
    for t, v in new_pts:
        merged.setdefault(t[:16], [None, None])[0] = int(v)
    for t in sorted(merged)[-12:]:
        nv, av = merged[t]
        rows += (f"<tr><td>{html.escape(t)}</td>"
                 f"<td>{'—' if nv is None else nv}</td>"
                 f"<td>{'—' if av is None else av}</td></tr>")

    arts = conn.execute(
        "SELECT slug, title, published_at FROM content WHERE status='published'"
        " ORDER BY id DESC LIMIT 5").fetchall()
    arts_html = "".join(
        f'<li><a href="articles/{html.escape(a["slug"])}.html">{html.escape(a["title"])}</a>'
        f'<span class="meta"> {html.escape((a["published_at"] or "")[:10])}</span></li>'
        for a in arts) or "<li>まだありません</li>"

    issues = state.open_issues(conn)
    issues_html = "".join(
        f'<li>[{html.escape(i["severity"])}] {html.escape(i["title"])}</li>'
        for i in issues) or '<li class="ok">開いている課題はありません 🎉</li>'

    queries = conn.execute(
        "SELECT title FROM research WHERE source IN ('gsc_opportunity','gsc_refresh')"
        " AND used=0 ORDER BY score DESC LIMIT 3").fetchall()
    q_html = "".join(f"<li>{html.escape(q['title'])}</li>" for q in queries) or "<li>蓄積中</li>"

    # 記事別成績 (Phase 4 MEASURE — GSC 28日実測)
    art_meta = _latest_meta(conn, "article_metrics") or {}
    art_rows = ""
    for m in (art_meta.get("articles") or [])[:10]:
        t = conn.execute("SELECT title FROM content WHERE slug=?",
                         (m["slug"],)).fetchone()
        label = (t["title"] if t else m["slug"])[:34]
        art_rows += (
            f'<tr><td style="text-align:left">'
            f'<a href="articles/{html.escape(m["slug"])}.html">{html.escape(label)}</a></td>'
            f'<td>{int(m["impressions"])}</td><td>{int(m["clicks"])}</td>'
            f'<td>{m["ctr"]:.1%}</td><td>{m["position"]:.1f}</td></tr>')
    art_metrics_html = (
        f'<table style="width:100%"><tr><th style="text-align:left">記事</th>'
        f'<th>表示</th><th>クリック</th><th>CTR</th><th>平均順位</th></tr>{art_rows}</table>'
        if art_rows else '<div class="empty">GSCに記事ページの計測が届くと表示されます</div>')

    updated = state.now()
    return f"""<!doctype html>
<html lang="ja"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Loop Dashboard — AIKnowledgeCMS</title>
<meta name="description" content="エージェントループが運営するこのサイトの成績を、ループ自身が毎時公開する実測ダッシュボード。">
<style>
:root{{--ink:#101828;--body:#475467;--faint:#98a2b3;--line:#e9edf3;--soft:#f7f9fc;
--accent:#4f46e5;--s-new:{SERIES_NEW};--s-all:{SERIES_ALL}}}
*{{box-sizing:border-box;margin:0;padding:0}}
body{{background:var(--soft);color:var(--ink);line-height:1.7;
font-family:-apple-system,"Segoe UI","Hiragino Sans","Noto Sans JP",sans-serif}}
.wrap{{max-width:980px;margin:0 auto;padding:30px 20px 60px}}
.nav{{display:flex;justify-content:space-between;margin-bottom:16px;font-size:13px}}
.nav a{{color:var(--accent);text-decoration:none;font-weight:700}}
h1{{font-size:22px;letter-spacing:-.01em}}
.sub{{font-size:13px;color:var(--faint);margin:2px 0 22px}}
.tiles{{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px}}
.tile{{background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px 16px;
box-shadow:0 1px 3px rgba(16,24,40,.04)}}
.tile small{{display:block;font-size:11px;color:var(--faint);font-weight:700;letter-spacing:.02em}}
.tile strong{{display:block;font-size:26px;letter-spacing:-.02em;margin:2px 0}}
.tile span{{font-size:10.5px;color:var(--faint)}}
.card{{background:#fff;border:1px solid var(--line);border-radius:16px;padding:20px 22px;
box-shadow:0 1px 3px rgba(16,24,40,.04);margin-bottom:16px}}
.card h2{{font-size:14px;color:var(--accent);letter-spacing:.06em;text-transform:uppercase;margin-bottom:10px}}
svg{{width:100%;height:auto;display:block}}
.grid{{stroke:var(--line);stroke-width:1}}
.alabel{{font-size:10px;fill:var(--faint)}}
.dlabel{{font-size:11px;font-weight:700;fill:var(--body)}}
.legend{{display:flex;gap:16px;font-size:12px;color:var(--body);margin-top:6px}}
.legend i{{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:5px;vertical-align:-1px}}
details{{margin-top:8px;font-size:12.5px}}
summary{{cursor:pointer;color:var(--faint)}}
table{{border-collapse:collapse;margin-top:8px;font-size:12px}}
td,th{{border:1px solid var(--line);padding:4px 10px;text-align:right}}
th{{background:var(--soft);color:var(--body)}}
.cols{{display:grid;grid-template-columns:1fr 1fr;gap:16px}}
ul{{list-style:none}}
li{{font-size:13.5px;padding:5px 0;border-bottom:1px solid var(--soft)}}
li a{{color:var(--accent);text-decoration:none;font-weight:600}}
.meta{{color:var(--faint);font-size:11.5px}}
.ok{{color:#059669;font-weight:700}}
.empty{{color:var(--faint);font-size:13px;padding:20px;text-align:center}}
.foot{{font-size:11.5px;color:var(--faint);text-align:center;margin-top:20px}}
@media(max-width:720px){{.tiles{{grid-template-columns:1fr 1fr}}.cols{{grid-template-columns:1fr}}}}
</style></head><body><div class="wrap">
<div class="nav"><a href="aiknowledgecms.html">← AIKnowledgeCMS</a>
<span><a href="articles/">Media</a> · <a href="loop/">Loop Reports</a></span></div>
<h1>🤖 Loop Dashboard</h1>
<div class="sub">このサイトを運営しているエージェントループの成績表。ループ自身が毎時更新(最終: {updated} / tick {tick_id})。数値はすべて実測です。</div>
<div class="tiles">{tiles_html}</div>
<div class="card">
  <h2>24時間PVの推移</h2>
  {_line_chart(new_pts, all_pts)}
  <div class="legend"><span><i style="background:var(--s-new)"></i>新システム(成長KPI)</span>
  <span><i style="background:var(--s-all)"></i>全体(旧遺産ページ含む)</span></div>
  <details><summary>データテーブルで見る</summary>
  <table><tr><th>時刻</th><th>新システム</th><th>全体</th></tr>{rows}</table></details>
</div>
<div class="cols">
  <div class="card"><h2>ループが公開した記事</h2><ul>{arts_html}</ul></div>
  <div class="card"><h2>課題キュー / 次に狙う検索クエリ</h2>
    <ul>{issues_html}</ul>
    <h2 style="margin-top:14px">Next Targets</h2><ul>{q_html}</ul></div>
</div>
<div class="card">
  <h2>記事別成績 — Google検索 28日間 (MEASURE)</h2>
  {art_metrics_html}
</div>
<div class="foot">生成: AIKnowledgeCMS Growth Loop (LLM非使用・自DB実測) — <a href="https://github.com/katsushi2441/aiknowledgecms" style="color:var(--accent)">GitHub</a></div>
</div></body></html>
"""


def publish(cfg: dict, conn, tick_id: int) -> str:
    import ftplib
    import io
    env = cfg["_env"]
    page = build(cfg, conn, tick_id)
    remote = cfg["publisher"]["remote_dir"].rsplit("/", 1)[0]  # サイトルート
    with ftplib.FTP(env["FTP_HOST"], timeout=60) as ftp:
        ftp.login(env["FTP_USER"], env["FTP_PASS"])
        ftp.storbinary(f"STOR {remote}/dashboard.html",
                       io.BytesIO(page.encode("utf-8")))
    return cfg["site"].rstrip("/") + "/dashboard.html"
