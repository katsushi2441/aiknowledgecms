"""articles_ftp パブリッシャ — ゲート通過記事を /articles/ にHTML公開する。"""
from __future__ import annotations

import ftplib
import html
import io
import json

from core import state
from core.loop import md_to_simple_html  # 共通の簡易md→html
from adapters.publishers.og_image import generate_og_image

ARTICLE_SHELL = """<!doctype html>
<html lang="ja"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{title} — AIKnowledgeCMS Media</title>
<meta name="description" content="{desc}">
<link rel="canonical" href="{url}">
<meta property="og:title" content="{title}">
<meta property="og:type" content="article">
<meta property="og:url" content="{url}">
<meta property="og:site_name" content="AIKnowledgeCMS Media">
<meta property="og:description" content="{desc}">
<meta property="og:image" content="{og_image_url}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:type" content="image/png">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:image" content="{og_image_url}">
<script type="application/ld+json">{json_ld}</script>
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


def _related_articles(conn, slug: str, title: str, k: int = 4) -> list:
    """タイトルの文字2-gram重なりで関連記事を選ぶ(孤児ページ解消の内部リンク網)。"""
    grams = {title[i:i + 2] for i in range(len(title) - 1)}
    scored = []
    for r in conn.execute(
            "SELECT slug, title FROM content WHERE status='published' AND slug != ?"
            " ORDER BY id DESC LIMIT 200", (slug,)):
        g2 = {r["title"][i:i + 2] for i in range(len(r["title"]) - 1)}
        overlap = len(grams & g2)
        scored.append((overlap, r["slug"], r["title"]))
    scored.sort(key=lambda x: -x[0])
    # 重なりが薄くても最新記事で埋めて、必ずk本の内部リンクを張る
    return [(s, t) for _, s, t in scored[:k]]


def render_article(cfg: dict, conn, slug: str, title: str, body_md: str,
                   published: str, creator: str, verifier: str) -> str:
    """記事HTMLを組み立てる(新規公開・一括再公開の共通経路)。"""
    site = cfg["site"].rstrip("/")
    article_url = f"{site}/articles/{slug}.html"
    og_image_url = f"{site}/images/og/{slug}.png"
    body_html = f"<h1>{html.escape(title)}</h1>\n" + md_to_simple_html(body_md)
    related = _related_articles(conn, slug, title)
    if related:
        body_html += "\n<h2>関連記事</h2>\n<ul>\n" + "\n".join(
            f'<li><a href="{html.escape(s)}.html">{html.escape(t)}</a></li>'
            for s, t in related) + "\n</ul>"
    desc = html.escape(body_md[:110].replace("\n", " "))
    json_ld = json.dumps({
        "@context": "https://schema.org",
        "@type": "Article",
        "headline": title[:110],
        "description": body_md[:110].replace("\n", " "),
        "image": [og_image_url],
        "datePublished": published,
        "mainEntityOfPage": article_url,
        "author": {"@type": "Organization", "name": "AIKnowledgeCMS", "url": site + "/"},
        "publisher": {"@type": "Organization", "name": "AIKnowledgeCMS",
                      "logo": {"@type": "ImageObject", "url": og_image_url}},
    }, ensure_ascii=False)
    return ARTICLE_SHELL.format(
        title=html.escape(title), desc=desc, body=body_html,
        published=published, creator=creator, verifier=verifier,
        url=article_url, og_image_url=og_image_url, json_ld=json_ld,
    )


def build_index_page(conn) -> str:
    rows = conn.execute(
        "SELECT slug, title, published_at FROM content WHERE status='published'"
        " ORDER BY id DESC LIMIT 100").fetchall()
    items = "\n".join(
        f'<li><a href="{html.escape(r["slug"])}.html">{html.escape(r["title"])}</a>'
        f' <span class="meta">{html.escape(r["published_at"] or "")}</span></li>'
        for r in rows)
    return INDEX_SHELL.format(items=items)


def publish(cfg: dict, conn, draft: dict, gate: dict) -> str:
    """記事と一覧を公開し、公開URLを返す。"""
    env = cfg["_env"]
    remote_dir = cfg["publisher"]["articles_dir"].rstrip("/")
    images_remote_dir = remote_dir.rsplit("/", 1)[0] + "/images/og"
    site = cfg["site"].rstrip("/")
    article_url = f"{site}/articles/{draft['slug']}.html"

    page = render_article(
        cfg, conn, draft["slug"], draft["title"], draft["body"],
        published=state.now(),
        creator=cfg["create"]["generator"]["model"],
        verifier=(gate.get("verifier") or {}).get("model", "-"),
    )
    og_image_bytes = generate_og_image(draft["title"])

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

    index_page = build_index_page(conn)

    with ftplib.FTP(env["FTP_HOST"], timeout=60) as ftp:
        ftp.login(env["FTP_USER"], env["FTP_PASS"])
        try:
            ftp.mkd(remote_dir)
        except ftplib.error_perm:
            pass
        try:
            ftp.mkd(images_remote_dir)
        except ftplib.error_perm:
            pass
        ftp.storbinary(f"STOR {remote_dir}/{draft['slug']}.html",
                       io.BytesIO(page.encode("utf-8")))
        ftp.storbinary(f"STOR {remote_dir}/index.html",
                       io.BytesIO(index_page.encode("utf-8")))
        ftp.storbinary(f"STOR {images_remote_dir}/{draft['slug']}.png",
                       io.BytesIO(og_image_bytes))
    return article_url


def republish_one(cfg: dict, conn, slug: str) -> str | None:
    """記事1本を現行テンプレで再公開する(ACTの未インデックス処置用)。

    lastmod相当の更新と関連記事リンクの張り直しで再クロールを促す。
    本文は変えない。一覧も更新する。
    """
    row = conn.execute(
        "SELECT slug, title, body_md, published_at FROM content"
        " WHERE slug=? AND status='published'", (slug,)).fetchone()
    if row is None:
        return None
    env = cfg["_env"]
    remote_dir = cfg["publisher"]["articles_dir"].rstrip("/")
    site = cfg["site"].rstrip("/")
    page = render_article(cfg, conn, row["slug"], row["title"], row["body_md"] or "",
                          published=row["published_at"] or "",
                          creator=cfg["create"]["generator"]["model"], verifier="-")
    with ftplib.FTP(env["FTP_HOST"], timeout=60) as ftp:
        ftp.login(env["FTP_USER"], env["FTP_PASS"])
        ftp.storbinary(f"STOR {remote_dir}/{row['slug']}.html",
                       io.BytesIO(page.encode("utf-8")))
        ftp.storbinary(f"STOR {remote_dir}/index.html",
                       io.BytesIO(build_index_page(conn).encode("utf-8")))
    return f"{site}/articles/{row['slug']}.html"


def republish_all(cfg: dict, conn) -> int:
    """公開済み全記事を現行テンプレートで再生成して上げ直す(テンプレ改修の反映用)。

    本文はDBのbody_mdをそのまま使い、内容は変えない。OG画像は既存を使う
    (無い記事だけ生成)。戻り値は再公開した記事数。
    """
    env = cfg["_env"]
    remote_dir = cfg["publisher"]["articles_dir"].rstrip("/")
    images_remote_dir = remote_dir.rsplit("/", 1)[0] + "/images/og"
    creator = cfg["create"]["generator"]["model"]
    rows = conn.execute(
        "SELECT slug, title, body_md, published_at FROM content"
        " WHERE status='published' ORDER BY id").fetchall()
    count = 0
    with ftplib.FTP(env["FTP_HOST"], timeout=120) as ftp:
        ftp.login(env["FTP_USER"], env["FTP_PASS"])
        try:
            existing_ogs = set(ftp.nlst(images_remote_dir))
        except ftplib.error_perm:
            existing_ogs = set()
        for r in rows:
            page = render_article(cfg, conn, r["slug"], r["title"], r["body_md"] or "",
                                  published=r["published_at"] or "",
                                  creator=creator, verifier="-")
            ftp.storbinary(f"STOR {remote_dir}/{r['slug']}.html",
                           io.BytesIO(page.encode("utf-8")))
            og_name = f"{r['slug']}.png"
            if not any(n.endswith(og_name) for n in existing_ogs):
                ftp.storbinary(f"STOR {images_remote_dir}/{og_name}",
                               io.BytesIO(generate_og_image(r["title"])))
            count += 1
        ftp.storbinary(f"STOR {remote_dir}/index.html",
                       io.BytesIO(build_index_page(conn).encode("utf-8")))
    return count
