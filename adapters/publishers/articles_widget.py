"""articles_widget パブリッシャ — 「最新のAI解説記事」ウィジェットを公開する。

新システム(/articles/)最大の集客ボトルネックは「発見されないこと」:
サイト内トラフィックの大半を持つ旧ページ(aiknowledgecms.php 約300PV/日)から
/articles/ への内部リンクがゼロで、Googleにも読者にも孤島になっていた
(2026-07-14診断: pv_new_24hが0〜8で横ばい、未インデックス21件滞留)。

kurage_videos.html と同じ方式: 自己完結のHTML断片 (media_articles.html) を
サイト直下へFTP公開し、高PVページがPHPのincludeで読み込む。
ダイジェスト記事(digest-*)と週報(loop-weekly-*)は検索価値が薄いので載せず、
解説記事(what-is-* / insight-* / GSC機会記事)だけを新しい順に並べる。
"""
from __future__ import annotations

import ftplib
import html
import io

REMOTE_NAME = "media_articles.html"
MAX_ITEMS = 6

# 内部リンクとして流したいのは検索価値のある解説記事のみ
EXCLUDE_PREFIXES = ("digest-", "loop-weekly-")

WIDGET_SHELL = """<!-- AIKnowledgeCMS articles_widget (自動生成・毎tick更新) -->
<div style="margin:28px 0;padding:20px 22px;background:#fbf9f4;border:1px solid #efe8d8;border-radius:14px;font-family:-apple-system,'Segoe UI','Hiragino Sans','Noto Sans JP',sans-serif">
<div style="font-size:15px;font-weight:700;color:#101828;margin-bottom:10px">&#128218; 最新のAI解説記事 <span style="font-weight:400;font-size:11.5px;color:#98a2b3">(エージェントループが自動生成・検証済み)</span></div>
<ul style="margin:0;padding-left:20px;line-height:1.9">
{items}
</ul>
<div style="margin-top:10px;font-size:12px"><a href="/articles/" style="color:#b45309;text-decoration:none;font-weight:600">解説記事をもっと見る →</a></div>
</div>
"""

ITEM = ('<li style="font-size:13.5px"><a href="/articles/{slug}.html" '
        'style="color:#344054;text-decoration:none">{title}</a></li>')


def build_html(conn, max_items: int = MAX_ITEMS) -> str | None:
    rows = conn.execute(
        "SELECT slug, title FROM content WHERE status='published'"
        " ORDER BY published_at DESC LIMIT 60").fetchall()
    picked = []
    for r in rows:
        slug = r["slug"]
        if any(slug.startswith(p) for p in EXCLUDE_PREFIXES):
            continue
        picked.append(r)
        if len(picked) >= max_items:
            break
    if not picked:
        return None
    items = "\n".join(
        ITEM.format(slug=html.escape(r["slug"]), title=html.escape(r["title"]))
        for r in picked)
    return WIDGET_SHELL.format(items=items)


def publish(cfg: dict, conn) -> str | None:
    """ウィジェットHTML断片をサイト直下へ公開。素材が無ければ何もしない。"""
    page = build_html(conn)
    if page is None:
        return None
    env = cfg["_env"]
    site_root = cfg["publisher"]["articles_dir"].rstrip("/").rsplit("/", 1)[0]
    remote = f"{site_root}/{REMOTE_NAME}"
    with ftplib.FTP(env["FTP_HOST"], timeout=60) as ftp:
        ftp.login(env["FTP_USER"], env["FTP_PASS"])
        ftp.storbinary(f"STOR {remote}", io.BytesIO(page.encode("utf-8")))
    return remote
