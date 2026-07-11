"""video_insight ジェネレータ — Kurage動画の題材を深掘りした単独の知識記事を書く。

video_digest(1日数本のまとめ紹介記事、リンク獲得が目的)とは別に、
同じresearch(video:{source})から「その動画が扱っているテーマ」を
1本ずつ掘り下げた読み物記事を作る。動画のサマリだけを事実の根拠とし、
本文中に動画への自然なリンクを置くことで、知識記事としての深さと
Kurage動画への送客を両立させる(video_digestと同じ品質ゲートを通す)。

まとめて紹介するvideo_digestと、1本ずつ掘り下げるvideo_insightで
同じ動画を取り合わないよう、research.usedの早い者勝ち(id ASC)に任せる
— tick内でvideo_insightをvideo_digestより先に呼ぶことで、
「深掘りする価値がありそうな題材」を先取りできる。
"""
from __future__ import annotations

import json
import time

from core import state
from adapters.generators.agent_article import DEFAULT_OLLAMA, _llm, parse_output

NAME = "video_insight"


def pick_video(conn):
    """未使用の動画素材から、サマリが最も豊富な1本を選ぶ(古い順を優先しつつ
    情報量が薄い題材は後回しにする: id ASCの若い100件からサマリ長最大を選ぶ)。"""
    rows = conn.execute(
        "SELECT * FROM research WHERE source LIKE 'video:%' AND used = 0"
        " ORDER BY id ASC LIMIT 100").fetchall()
    if not rows:
        return None
    return max(rows, key=lambda r: len(r["summary"] or ""))


def tracked_url(url: str, ref: str) -> str:
    if not ref:
        return url
    return url + ("&" if "?" in url else "?") + "ref=" + ref


def build_prompt(cfg: dict, video, video_url: str) -> str:
    label = video["source"].removeprefix("video:")
    return f"""あなたは技術メディア「AIKnowledgeCMS」の記事ライターです。
Kurage動画で扱われたテーマを深掘りする、読み応えのある日本語の解説記事を書いてください。
単なる動画紹介ではなく、そのテーマ自体を独立した記事として掘り下げてください。

# 素材(実在の情報。これ以外の具体的事実・数字・日付を作らないこと)
- 動画タイトル: {video['title']}
- 動画URL: {video_url}
- 動画の内容サマリ: {video['summary'] or '(なし)'}

# 執筆ルール
- 900〜1500字。です・ます調。「{video['title']}」というテーマ自体の解説として
  最初の段落で読者の疑問(このテーマは何が要点か)に直接答える。
- サマリにある事実だけを使う。サマリにない具体的な数字・日付・固有名詞を創作しない。
  サマリだけでは掘り下げが難しい場合は、一般的な背景知識で補って構わないが、
  素材の事実と混同されないよう「一般的には」「背景として」等を付けて区別する。
- 本文中の自然な位置(例: 導入の直後、または最後の段落の直前)に1箇所、
  「詳しくはKurageの動画で解説しています: {video_url}」のように、
  動画へのリンクを本文の一部として自然に置く(参考リストへの機械的な追いやりにしない)。
- 最後に「## 参考」を置き、{video_url} を列挙する。

# 出力形式(厳守・この形式以外を出力しない)
TITLE: <30〜60字の記事タイトル(「{label}」という語は含めなくてよい)>
SLUG: <英小文字とハイフンのみ12〜50字>
---
<本文markdown>
"""


def generate(cfg: dict, conn, tick_id: int, dcfg_by_name: dict) -> dict | None:
    """深掘り記事のドラフトを1本生成しcontentにdraft保存。素材が無ければNone。"""
    video = pick_video(conn)
    if video is None:
        return None

    source_name = video["source"].removeprefix("video:")
    ref = (dcfg_by_name.get(source_name) or {}).get("link_ref", "")
    video_url = tracked_url(video["url"], ref)

    prompt = build_prompt(cfg, video, video_url)
    raw = _llm(cfg["create"]["generator"], cfg.get("agent_cli", ""), prompt,
               ollama_api=cfg["create"].get("ollama_api", DEFAULT_OLLAMA))
    parsed = parse_output(raw)
    if parsed is None:
        state.record(conn, tick_id, NAME, "insight_parse_error", 1,
                     {"source": source_name, "raw": raw[:500]})
        return None

    slug = parsed["slug"][:50].rstrip("-")
    base = slug
    i = 2
    while conn.execute("SELECT 1 FROM content WHERE slug=?", (slug,)).fetchone():
        slug = f"{base[:46]}-{i}"
        i += 1

    if "## 参考" not in parsed["body"] and "##参考" not in parsed["body"]:
        parsed["body"] = parsed["body"].rstrip() + f"\n\n## 参考\n- {video_url}\n"
    # ゲートのURL許可リストは本文が実際に引用するURL(ref付きtracked_url)で
    # 判定する必要がある。生URLだけを許可リストに入れると本文中のtracked_url
    # が「素材にないURL」として毎回却下される(officecli記事と同型のバグ)。
    src_urls = [video_url] if video_url != video["url"] else [video["url"]]
    conn.execute(
        "INSERT INTO content (slug, title, status, body_md, sources, created_tick, created_at)"
        " VALUES (?, ?, 'draft', ?, ?, ?, ?)",
        (slug, parsed["title"][:120], parsed["body"],
         json.dumps(src_urls, ensure_ascii=False), tick_id, state.now()))
    conn.commit()
    return {"slug": slug, "title": parsed["title"], "body": parsed["body"],
            "sources": src_urls, "source_ids": [video["id"]]}
