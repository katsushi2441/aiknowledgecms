"""video_digest ジェネレータ — 監視中の動画サイトの新着紹介記事を書く。

1日1本/ソース。未紹介の動画を古い順に最大N本(既定10)まとめ、
各動画へのリンクと紹介文で構成する記事を生成する(拡散=外部リンク獲得が目的)。
紹介文はコレクタが取得した実サマリだけを根拠にLLMが書き、
既存の品質ゲート(ルール+検証エージェント)を通ったものだけ公開される。
"""
from __future__ import annotations

import json
import time

from core import state
from adapters.generators.agent_article import DEFAULT_OLLAMA, _llm, parse_output

NAME = "video_digest"


def pick_videos(conn, source: str, limit: int):
    """未紹介の動画を古い順(=research.id昇順)に選ぶ。"""
    return conn.execute(
        "SELECT * FROM research WHERE source=? AND used=0 ORDER BY id ASC LIMIT ?",
        (f"video:{source}", limit)).fetchall()


def build_prompt(dcfg: dict, videos) -> str:
    site_label = dcfg.get("label", dcfg["name"])
    lines = "\n".join(
        f"- タイトル: {v['title']}\n  URL: {v['url']}\n  サマリ: {v['summary'] or '(なし)'}"
        for v in videos)
    today = time.strftime("%Y年%m月%d日")
    return f"""あなたは技術メディア「AIKnowledgeCMS」の記事ライターです。
{site_label}に新しく公開された動画のダイジェスト(紹介)記事を書いてください。

# 新着動画({len(videos)}本 — 実在の情報)
{lines}

# 執筆ルール
- 導入1〜2文のあと、動画ごとに「### <動画タイトル>」の見出しを置き、
  サマリを根拠にした2〜3文の紹介文と、直後の行に「動画: <URL>」を書く。
- 紹介文はサマリにある内容だけを使う。サマリにない事実・数字を創作しない。
  サマリが無い動画はタイトルから分かる範囲の1文だけにする。
- 全体で600〜2000字。です・ます調。煽らない。
- URLは上記の動画URLのみ使用可。1文字も変えずそのまま書く。
- 最後に「## 参考」を置き、紹介した動画のURLを列挙する。

# 出力形式(厳守・この形式以外を出力しない)
TITLE: <「{today}」と「{site_label}」を含む30〜60字のタイトル>
SLUG: digest-{dcfg['name']}-{time.strftime('%Y%m%d')}
---
<本文markdown>
"""


def generate(cfg: dict, conn, tick_id: int, dcfg: dict) -> dict | None:
    """ダイジェスト記事のドラフトを生成しcontentにdraft保存。素材が無ければNone。"""
    videos = pick_videos(conn, dcfg["name"], int(dcfg.get("max_items", 10)))
    if not videos:
        return None

    prompt = build_prompt(dcfg, videos)
    raw = _llm(cfg["create"]["generator"], cfg.get("agent_cli", ""), prompt,
               ollama_api=cfg["create"].get("ollama_api", DEFAULT_OLLAMA))
    parsed = parse_output(raw)
    if parsed is None:
        state.record(conn, tick_id, NAME, "digest_parse_error", 1,
                     {"source": dcfg["name"], "raw": raw[:500]})
        return None

    # slugはテンプレで固定(LLMの出力に依存しない)
    slug = f"digest-{dcfg['name']}-{time.strftime('%Y%m%d')}"
    conn.execute("DELETE FROM content WHERE slug=? AND status!='published'", (slug,))
    if conn.execute("SELECT 1 FROM content WHERE slug=?", (slug,)).fetchone():
        return None  # 今日の分は公開済み

    src_urls = [v["url"] for v in videos]
    if "## 参考" not in parsed["body"] and "##参考" not in parsed["body"]:
        refs = "\n".join(f"- {u}" for u in src_urls)
        parsed["body"] = parsed["body"].rstrip() + f"\n\n## 参考\n{refs}\n"
    conn.execute(
        "INSERT INTO content (slug, title, status, body_md, sources, created_tick, created_at)"
        " VALUES (?, ?, 'draft', ?, ?, ?, ?)",
        (slug, parsed["title"][:120], parsed["body"],
         json.dumps(src_urls, ensure_ascii=False), tick_id, state.now()))
    conn.commit()
    return {"slug": slug, "title": parsed["title"], "body": parsed["body"],
            "sources": src_urls, "source_ids": [v["id"] for v in videos]}
