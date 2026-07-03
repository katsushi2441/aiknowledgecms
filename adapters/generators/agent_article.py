"""agent_article ジェネレータ — 未使用のresearchから記事ドラフトを生成する。

エージェントは交換可能: kind=ollama(ローカルLLM) / kind=cli(任意のagent CLI)。
出力契約(厳格):
    TITLE: <記事タイトル>
    SLUG: <a-z0-9とハイフンのみ>
    ---
    <本文markdown>
"""
from __future__ import annotations

import json
import re
import subprocess
import urllib.request

from core import state

NAME = "agent_article"
DEFAULT_OLLAMA = "http://127.0.0.1:11434"


def _llm(gen_cfg: dict, agent_cli: str, prompt: str, timeout: int = 420,
         ollama_api: str = DEFAULT_OLLAMA) -> str:
    if gen_cfg.get("kind") == "ollama":
        req = urllib.request.Request(
            ollama_api.rstrip("/") + "/api/generate",
            data=json.dumps({
                "model": gen_cfg["model"],
                "prompt": prompt,
                "stream": False,
                "options": {"temperature": 0.7, "num_predict": 4096},
            }).encode(),
            headers={"Content-Type": "application/json"},
        )
        with urllib.request.urlopen(req, timeout=timeout) as r:
            return json.loads(r.read())["response"]
    # kind=cli: loopfileの agent_cli にstdinでプロンプトを渡す
    proc = subprocess.run(
        agent_cli.split(), input=prompt, text=True,
        capture_output=True, timeout=timeout,
    )
    if proc.returncode != 0:
        raise RuntimeError(f"agent cli failed: {proc.stderr[:300]}")
    return proc.stdout


def pick_sources(conn, n: int = 3):
    """未使用のresearchからスコア・新しさ順に題材を選ぶ。"""
    return conn.execute(
        "SELECT * FROM research WHERE used = 0 ORDER BY score DESC, id DESC LIMIT ?",
        (n,),
    ).fetchall()


def build_prompt(cfg: dict, sources) -> str:
    theme = cfg["create"].get("theme", "")
    src_lines = "\n".join(
        f"- {s['title']}\n  URL: {s['url']}\n  概要: {s['summary'] or '(なし)'}"
        for s in sources
    )
    return f"""あなたは技術メディア「AIKnowledgeCMS」の記事ライターです。
以下の実在するニュース素材だけを根拠に、日本語の短い考察記事を書いてください。

テーマ: {theme}

# 素材(この情報以外の具体的事実・数字・日付を作らないこと)
{src_lines}

# 執筆ルール
- 800〜1400字。です・ます調。煽らない。
- 素材にない事実・数字・企業名・日付を創作しない。不確かなことは「〜の可能性があります」と書く。
- 記事内で参照するURLは上記素材のURLのみ使用可。
- 構成: 導入(何が起きたか) → 考察(AIエージェント経済にとって何を意味するか) → まとめ(読者への示唆)。
- 最後に「## 参考」として使用した素材のURLを列挙する。

# 出力形式(厳守・この形式以外を出力しない)
TITLE: <30〜60字の記事タイトル>
SLUG: <英小文字とハイフンのみ12〜50字>
---
<本文markdown>
"""


def parse_output(text: str) -> dict | None:
    m = re.search(
        r"TITLE:\s*(?P<title>.+?)\s*\nSLUG:\s*(?P<slug>[a-z0-9\-]+)\s*\n---\s*\n(?P<body>.+)",
        text, re.S,
    )
    if not m:
        return None
    return {
        "title": m.group("title").strip(),
        "slug": m.group("slug").strip(),
        "body": m.group("body").strip(),
    }


def generate(cfg: dict, conn, tick_id: int) -> dict | None:
    """ドラフトを1本生成してcontentにdraftとして保存する。素材が無ければNone。"""
    sources = pick_sources(conn)
    if not sources:
        state.record(conn, tick_id, NAME, "create_skipped", 1, {"reason": "no_research"})
        return None

    prompt = build_prompt(cfg, sources)
    raw = _llm(cfg["create"]["generator"], cfg.get("agent_cli", ""), prompt,
               ollama_api=cfg["create"].get("ollama_api", DEFAULT_OLLAMA))
    parsed = parse_output(raw)
    if parsed is None:
        state.record(conn, tick_id, NAME, "create_parse_error", 1, {"raw": raw[:500]})
        return None

    # slug衝突は連番で回避
    slug = parsed["slug"][:50]
    base = slug
    i = 2
    while conn.execute("SELECT 1 FROM content WHERE slug=?", (slug,)).fetchone():
        slug = f"{base[:46]}-{i}"
        i += 1

    src_urls = [s["url"] for s in sources]
    if "## 参考" not in parsed["body"] and "##参考" not in parsed["body"]:
        refs = "\n".join(f"- {u}" for u in src_urls)
        parsed["body"] = parsed["body"].rstrip() + f"\n\n## 参考\n{refs}\n"
    conn.execute(
        "INSERT INTO content (slug, title, status, body_md, sources, created_tick, created_at)"
        " VALUES (?, ?, 'draft', ?, ?, ?, ?)",
        (slug, parsed["title"][:120], parsed["body"],
         json.dumps(src_urls, ensure_ascii=False), tick_id, state.now()),
    )
    conn.commit()
    return {"slug": slug, "title": parsed["title"], "body": parsed["body"],
            "sources": src_urls,
            "source_ids": [s["id"] for s in sources]}
