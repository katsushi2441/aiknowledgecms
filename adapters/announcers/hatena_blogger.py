"""hatena_blogger アナウンサ — はてなブログ/Bloggerへメール投稿で転載する。

vwork/scripts/post_to_hatena.py と同じ仕組み(メール投稿)。
宛先は env_file の HATENA_POST_EMAIL / BLOGGER_POST_EMAIL。
"""
from __future__ import annotations

import smtplib
import ssl
from email.mime.text import MIMEText

from core.loop import md_to_simple_html


def announce(cfg: dict, title: str, url: str, body_md: str) -> dict:
    env = cfg["_env"]
    smtp_host = env.get("SMTP_HOST", "mail18.heteml.jp")
    smtp_port = int(env.get("SMTP_PORT", "465"))
    smtp_from = env.get("SMTP_FROM", "")
    smtp_pass = env.get("SMTP_PASSWORD", "")
    targets = {
        "hatena": env.get("HATENA_POST_EMAIL", ""),
        "blogger": env.get("BLOGGER_POST_EMAIL", ""),
    }
    if not (smtp_from and smtp_pass):
        return {"sent": [], "skipped": "SMTP未設定"}

    header = (f'<p>この記事はAIKnowledgeCMSのエージェントループが自動生成・検証・公開したものです。'
              f'元記事: <a href="{url}">{url}</a></p><hr>')
    html = header + md_to_simple_html(body_md)

    sent = []
    ctx = ssl.create_default_context()
    with smtplib.SMTP_SSL(smtp_host, smtp_port, context=ctx, timeout=60) as s:
        s.login(smtp_from, smtp_pass)
        for name, to_addr in targets.items():
            if not to_addr:
                continue
            msg = MIMEText(html, "html", "utf-8")
            msg["Subject"] = title
            msg["From"] = smtp_from
            msg["To"] = to_addr
            s.sendmail(smtp_from, [to_addr], msg.as_bytes())
            sent.append(name)
    return {"sent": sent}
