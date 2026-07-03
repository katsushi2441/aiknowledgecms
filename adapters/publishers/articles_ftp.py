"""articles_ftp パブリッシャ — ゲート通過記事を /articles/ にHTML公開する。"""
from __future__ import annotations

import ftplib
import html
import io
import json

from core import state
from core.loop import md_to_simple_html  # 共通の簡易md→html

ARTICLE_SHELL = """<!doctype html>
<html lang="ja"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{title} — AIKnowledgeCMS Media</title>
<meta name="description" content="{desc}">
<meta property="og:title" content="{title}">
<meta property="og:type" content="article">
<style>
body{{margin:0;background:#f7f9fc;color:#101828;font-family:-apple-system,"Segoe UI","Hiragino Sans","Noto Sans JP",sans-serif;line-height:1.9}}
.wrap{{max-width:760px;margin:0 auto;padding:34px 20px 60px}}
.card{{background:#fff;border:1px solid #e9edf3;border-radius:16px;padding:32px 34px;box-shadow:0 1px 3px rgba(16,24,40,.05)}}
h1{{font-size:24px;line-height:1.5;letter-spacing:-.01em;margin:0 0 8px}}
h2{{font-size:17px;margin:26px 0 8px}}
p,li{{font-size:15px;color:#344054}}
.meta{{font-size:12.5px;color:#98a2b3;margin-bottom:20px}}
.badge{{display:inline-block;background:#eef0fe;color:#4f46e5;border-radius:999px;padding:3px 12px;font-size:11.5px;font-weight:700;margin-bottom:14px}}
a{{color:#4f46e5;text-decoration:none;font-weight:600;word-break:break-all}}
.nav{{display:flex;justify-content:space-between;margin-bottom:14px;font-size:13px}}
ul{{padding-left:22px}}
</style></head><body><div class="wrap">
<div class="nav"><a href="index.html">← Media 一覧</a><a href="/aiknowledgecms.html">AIKnowledgeCMS</a></div>
<div class="card">
<span class="badge">🤖 この記事はエージェントループが自動生成し、検証ゲートを通過して公開されました</span>
{body}
<div class="meta" style="margin-top:26px">published: {published} / gate: creator={creator} → verifier={verifier}</div>
</div>
</div></body></html>
"""

INDEX_SHELL = """<!doctype html>
<html lang="ja"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Media — AIKnowledgeCMS</title>
<style>
body{{margin:0;background:#f7f9fc;color:#101828;font-family:-apple-system,"Segoe UI","Hiragino Sans","Noto Sans JP",sans-serif;line-height:1.8}}
.wrap{{max-width:760px;margin:0 auto;padding:34px 20px 60px}}
.card{{background:#fff;border:1px solid #e9edf3;border-radius:16px;padding:28px 30px;box-shadow:0 1px 3px rgba(16,24,40,.05)}}
h1{{font-size:22px;margin:0 0 4px}}
.meta{{font-size:12.5px;color:#98a2b3;margin-bottom:16px}}
li{{margin:8px 0;font-size:14.5px}}
a{{color:#4f46e5;text-decoration:none;font-weight:600}}
.nav{{display:flex;justify-content:flex-end;margin-bottom:14px;font-size:13px}}
</style></head><body><div class="wrap">
<div class="nav"><a href="/aiknowledgecms.html">AIKnowledgeCMS</a></div>
<div class="card">
<h1>AIKnowledgeCMS Media</h1>
<div class="meta">エージェントループが収集・生成・検証・公開まで自律的に行う記事一覧。ループの実行記録は <a href="/loop/">/loop/</a>。</div>
<ul>{items}</ul>
</div>
</div></body></html>
"""


def publish(cfg: dict, conn, draft: dict, gate: dict) -> str:
    """記事と一覧を公開し、公開URLを返す。"""
    env = cfg["_env"]
    remote_dir = cfg["publisher"]["articles_dir"].rstrip("/")
    site = cfg["site"].rstrip("/")

    body_html = f"<h1>{html.escape(draft['title'])}</h1>\n" + md_to_simple_html(draft["body"])
    desc = html.escape(draft["body"][:110].replace("\n", " "))
    page = ARTICLE_SHELL.format(
        title=html.escape(draft["title"]), desc=desc, body=body_html,
        published=state.now(),
        creator=cfg["create"]["generator"]["model"],
        verifier=(gate.get("verifier") or {}).get("model", "-"),
    )

    conn.execute(
        "UPDATE content SET status='published', published_at=? WHERE slug=?",
        (state.now(), draft["slug"]),
    )
    # 使った素材を消費済みに
    ids = draft.get("source_ids") or []
    if ids:
        conn.execute(
            "UPDATE research SET used=1 WHERE id IN ({})".format(",".join("?" * len(ids))), ids)
    conn.commit()

    rows = conn.execute(
        "SELECT slug, title, published_at FROM content WHERE status='published'"
        " ORDER BY id DESC LIMIT 100").fetchall()
    items = "\n".join(
        f'<li><a href="{html.escape(r["slug"])}.html">{html.escape(r["title"])}</a>'
        f' <span class="meta">{html.escape(r["published_at"] or "")}</span></li>'
        for r in rows)
    index_page = INDEX_SHELL.format(items=items)

    with ftplib.FTP(env["FTP_HOST"], timeout=60) as ftp:
        ftp.login(env["FTP_USER"], env["FTP_PASS"])
        try:
            ftp.mkd(remote_dir)
        except ftplib.error_perm:
            pass
        ftp.storbinary(f"STOR {remote_dir}/{draft['slug']}.html",
                       io.BytesIO(page.encode("utf-8")))
        ftp.storbinary(f"STOR {remote_dir}/index.html",
                       io.BytesIO(index_page.encode("utf-8")))
    return f"{site}/articles/{draft['slug']}.html"
