"""videos_widget パブリッシャ — 「最新のKurage AI動画」ウィジェットを公開する。

拡散ハブの送客導線。researchに溜まったkuragev動画の新しい順に数本を、
自己完結のHTML断片 (kurage_videos.html) としてサイト直下へFTP公開する。
高PVの既存ページ (oss.php / aiknowledgecms.php) がPHPのincludeで読み込む。
リンクには ref=akc-w を付け、kurage_clicksセンサがウィジェット経由の
送客として計測できるようにする。
"""
from __future__ import annotations

import ftplib
import html
import io

REMOTE_NAME = "kurage_videos.html"
MAX_ITEMS = 5

WIDGET_SHELL = """<!-- AIKnowledgeCMS videos_widget (自動生成・毎tick更新) -->
<div style="margin:28px 0;padding:20px 22px;background:#f7f9fc;border:1px solid #e4e9f0;border-radius:14px;font-family:-apple-system,'Segoe UI','Hiragino Sans','Noto Sans JP',sans-serif">
<div style="font-size:15px;font-weight:700;color:#101828;margin-bottom:10px">&#127909; 最新のKurage AI動画</div>
<ul style="margin:0;padding-left:20px;line-height:1.9">
{items}
</ul>
<div style="margin-top:10px;font-size:12px"><a href="https://kurage.exbridge.jp/?ref=akc-w" style="color:#4f46e5;text-decoration:none;font-weight:600">Kurage動画サイトをもっと見る →</a></div>
</div>
"""

ITEM = ('<li style="font-size:13.5px"><a href="{url}" '
        'style="color:#344054;text-decoration:none">{title}</a></li>')


def build_html(conn, max_items: int = MAX_ITEMS) -> str | None:
    rows = conn.execute(
        "SELECT title, url FROM research WHERE source='video:kuragev'"
        " ORDER BY id DESC LIMIT ?", (max_items,)).fetchall()
    if not rows:
        return None
    items = "\n".join(
        ITEM.format(url=html.escape(r["url"] + "&ref=akc-w"),
                    title=html.escape(r["title"]))
        for r in rows)
    return WIDGET_SHELL.format(items=items)


def publish(cfg: dict, conn) -> str | None:
    """ウィジェットHTML断片をサイト直下へ公開。素材が無ければ何もしない。"""
    page = build_html(conn)
    if page is None:
        return None
    env = cfg["_env"]
    # articles_dir (…/articles) の親 = サイトのドキュメントルート
    site_root = cfg["publisher"]["articles_dir"].rstrip("/").rsplit("/", 1)[0]
    remote = f"{site_root}/{REMOTE_NAME}"
    with ftplib.FTP(env["FTP_HOST"], timeout=60) as ftp:
        ftp.login(env["FTP_USER"], env["FTP_PASS"])
        ftp.storbinary(f"STOR {remote}", io.BytesIO(page.encode("utf-8")))
    return remote
