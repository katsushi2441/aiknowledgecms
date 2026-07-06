"""hatena_blogger アナウンサ — はてなブログ/Bloggerへメール投稿で配信する。

Phase 4 (外部リンク獲得):
  記事公開時は、チャネルごとに別アングルの紹介記事をLLMで生成して投稿する。
  同一本文の3重複製(自サイト+はてな+Blogger)は重複コンテンツとして本家の
  評価を食うため、内容を変えた「衛星記事+元記事へのリンク」に切り替えた。
  生成/検証に失敗したチャネルは従来の原文転載にフォールバックする
  (バックリンク獲得は必ず成立させる)。

週次レポート等(kind="report")は数値の改変リスクを避けて原文転載のまま。
投稿はvwork/scripts/post_to_hatena.pyと同じメール投稿方式。
宛先は env_file の HATENA_POST_EMAIL / BLOGGER_POST_EMAIL。
"""
from __future__ import annotations

import json
import re
import smtplib
import ssl
import urllib.request
from email.mime.text import MIMEText

from core.loop import md_to_simple_html

DEFAULT_OLLAMA = "http://127.0.0.1:11434"

# チャネルごとに切り口を変える(重複コンテンツ回避の要)
STYLES = {
    "hatena": ("入門者向けの紹介記事",
               "「これは何ができるものか」「誰に向いているか」を中心に、"
               "初めてこの話題に触れる読者へ紹介する構成"),
    "blogger": ("要点整理記事",
                "「3つのポイント」のような箇条書き中心で、"
                "要点を素早く把握したい読者向けに整理する構成"),
}


def _variant(cfg: dict, channel: str, title: str, url: str, body_md: str) -> dict | None:
    """元記事から、チャネル別の切り口の紹介記事(md)を生成して返す。失敗はNone。"""
    gen = cfg.get("create", {}).get("generator", {})
    if gen.get("kind") != "ollama":
        return None
    style_name, style_desc = STYLES[channel]
    prompt = f"""あなたは技術ブログのライターです。以下の元記事をもとに、
別のブログに投稿する「{style_name}」を書いてください。

# 元記事タイトル
{title}

# 元記事URL
{url}

# 元記事本文
{body_md}

# 執筆ルール
- {style_desc}。
- 400〜700字。です・ます調。元記事の丸写しにせず、構成と言い回しを変える。
- 元記事にある事実だけを使う。新しい事実・数字・日付を創作しない。
- 本文の途中で1回、自然な文脈で元記事URL({url})に触れる。
- タイトルも元記事と変える(主要キーワードは含める)。30〜60字。

# 出力形式(厳守・この形式以外を出力しない)
TITLE: <タイトル>
---
<本文markdown>
"""
    api = cfg["create"].get("ollama_api", DEFAULT_OLLAMA).rstrip("/") + "/api/generate"
    req = urllib.request.Request(
        api,
        data=json.dumps({
            "model": gen["model"], "prompt": prompt, "stream": False,
            "think": False,  # 思考型モデル対策
            "options": {"temperature": 0.7, "num_predict": 2048},
        }).encode(),
        headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=600) as r:
        raw = json.loads(r.read())["response"]
    m = re.search(r"TITLE:\s*(?P<title>.+?)\s*\n---\s*\n(?P<body>.+)", raw, re.S)
    if not m:
        return None
    v = {"title": m.group("title").strip()[:80], "body": m.group("body").strip()}

    # ルール検査(決定的): 長さ・タイトル・URL許可リスト。
    # 許可URL = 元記事URL + 元記事本文に出てくるURL(動画ダイジェストの
    # 動画リンク等はゲート通過済みの本文由来なので衛星記事でも引用可)。
    if not (10 <= len(v["title"]) <= 80):
        return None
    if not (300 <= len(v["body"]) <= 1600):
        return None
    allowed = {url} | {u.rstrip(".,;:)\"'）")
                       for u in re.findall(r"https?://[!-~]+", body_md)}
    for u in re.findall(r"https?://[!-~]+", v["body"]):
        if u.rstrip(".,;:)\"'）") not in allowed:
            return None
    if v["title"].strip() == title.strip():
        return None  # タイトルが同一なら意図を満たさない

    # 検証エージェント: 元記事への忠実性(生成担当と別呼び出し)
    try:
        from gates.verify_article import verifier_agent
        verdict = verifier_agent(cfg, v, f"元記事本文:\n{body_md}")
        if verdict.get("verdict") != "PASS":
            return None
    except Exception:
        return None
    return v


def announce(cfg: dict, title: str, url: str, body_md: str,
             kind: str = "article") -> dict:
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

    sent = []
    modes = {}
    ctx = ssl.create_default_context()
    with smtplib.SMTP_SSL(smtp_host, smtp_port, context=ctx, timeout=60) as s:
        s.login(smtp_from, smtp_pass)
        for name, to_addr in targets.items():
            if not to_addr:
                continue
            variant = None
            if kind == "article":
                try:
                    variant = _variant(cfg, name, title, url, body_md)
                except Exception:
                    variant = None
            if variant:
                # 衛星記事: 別内容 + 末尾に確実なバックリンク(本文中のリンクとは別)
                footer = (f'<hr><p>より詳しい解説は元記事をどうぞ: '
                          f'<a href="{url}">{title}</a></p>')
                html = md_to_simple_html(variant["body"]) + footer
                subject = variant["title"]
                modes[name] = "variant"
            else:
                # フォールバック: 従来の原文転載(リンクは必ず入る)
                header = (f'<p>この記事はAIKnowledgeCMSのエージェントループが'
                          f'自動生成・検証・公開したものです。'
                          f'元記事: <a href="{url}">{url}</a></p><hr>')
                html = header + md_to_simple_html(body_md)
                subject = title
                modes[name] = "verbatim"
            msg = MIMEText(html, "html", "utf-8")
            msg["Subject"] = subject
            msg["From"] = smtp_from
            msg["To"] = to_addr
            s.sendmail(smtp_from, [to_addr], msg.as_bytes())
            sent.append(name)
    return {"sent": sent, "modes": modes}
