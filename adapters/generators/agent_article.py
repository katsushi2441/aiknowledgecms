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
import urllib.parse
import urllib.request

from core import state

NAME = "agent_article"
DEFAULT_OLLAMA = "http://127.0.0.1:11434"


def _llm(gen_cfg: dict, agent_cli: str, prompt: str, timeout: int = 600,
         ollama_api: str = DEFAULT_OLLAMA) -> str:
    if gen_cfg.get("kind") == "ollama":
        req = urllib.request.Request(
            ollama_api.rstrip("/") + "/api/generate",
            data=json.dumps({
                "model": gen_cfg["model"],
                "prompt": prompt,
                "stream": False,
                # gemma4等の思考型モデルは隠れ推論がnum_predictを食い潰し
                # 空応答になるため、明示的に思考を無効化する
                "think": False,
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


def _enrich_query(conn, tick_id: int, query: str) -> list:
    """GSCクエリをGitHub検索で実在の根拠に接地させ、researchに登録して返す。

    接地できないクエリの記事は創作になるため書かない(呼び出し側でフォールバック)。
    """
    url = ("https://api.github.com/search/repositories?q="
           + urllib.parse.quote(query) + "&per_page=3")
    req = urllib.request.Request(url, headers={
        "User-Agent": "AIKnowledgeCMS-Loop", "Accept": "application/vnd.github+json"})
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            items = json.loads(r.read()).get("items", [])
    except Exception:
        return []
    urls = []
    for it in items:
        desc = (it.get("description") or "").strip()
        if not desc:
            continue
        summary = (f"{desc} / ⭐{it.get('stargazers_count', 0)}"
                   + (f" / 言語: {it['language']}" if it.get("language") else ""))
        try:
            conn.execute(
                "INSERT INTO research (tick_id, source, title, url, summary, score, created_at)"
                " VALUES (?, 'github_enrich', ?, ?, ?, 5, ?)",
                (tick_id, f"GitHub: {it['full_name']}", it["html_url"],
                 summary[:400], state.now()))
        except Exception:
            pass  # 既出URL
        urls.append(it["html_url"])
        if len(urls) >= 2:
            break
    conn.commit()
    if not urls:
        return []
    return conn.execute(
        "SELECT * FROM research WHERE url IN ({})".format(",".join("?" * len(urls))),
        urls).fetchall()


def _fetch_page_context(url: str, timeout: int = 15) -> dict | None:
    """受けページのtitle/descriptionを接地素材として取る(創作防止)。"""
    try:
        req = urllib.request.Request(
            url, headers={"User-Agent": "Mozilla/5.0 (aiknowledgecms-loop)"})
        with urllib.request.urlopen(req, timeout=timeout) as r:
            text = r.read(300000).decode("utf-8", "replace")
    except Exception:
        return None
    tm = re.search(r"<title[^>]*>\s*([^<]{3,200})", text)
    dm = re.search(r'name="description"\s+content="([^"]{3,400})', text)
    om = re.search(r'property="og:description"\s+content="([^"]{3,400})', text)
    if not tm:
        return None
    return {"title": tm.group(1).strip(),
            "description": (dm or om).group(1).strip() if (dm or om) else ""}


def _tracked(url: str) -> str:
    """kurage側ページへのリンクには送客計測のref=akcを付ける(既存規約)。"""
    if "kurage.exbridge.jp" in url and "ref=" not in url:
        return url + ("&" if "?" in url else "?") + "ref=akc"
    return url


def build_network_prompt(cfg: dict, meta: dict, ctx: dict, enrich) -> str:
    """gscnet://(他プロパティで伸びている検索クエリ)起点の紹介+解説記事。"""
    q = meta.get("query", "")
    page_url = _tracked(meta.get("page", ""))
    enrich_lines = "\n".join(
        f"- {s['title']}\n  URL: {s['url']}\n  概要: {s['summary'] or '(なし)'}"
        for s in enrich) or "(なし)"
    stats = (f"直近7日: クリック{meta.get('clicks_7d', 0)}回・表示{meta.get('impressions_7d', 0)}回"
             f"(前週: {meta.get('clicks_prev7d', 0)}/{meta.get('impressions_prev7d', 0)})")
    return f"""あなたは技術メディア「AIKnowledgeCMS」の記事ライターです。
検索クエリ「{q}」からの流入が関連サイトで伸びています({stats})。
このクエリで検索する読者の疑問に答え、受けページへ案内する日本語記事を書いてください。

# 受けページ(実在。この記事が読者を案内する先)
- URL: {page_url}
- ページタイトル: {ctx.get('title', '')}
- ページ説明: {ctx.get('description', '') or '(なし)'}

# 補助素材(実在の情報)
{enrich_lines}

# 執筆ルール
- 800〜1400字。です・ます調。「{q}とは何か」に最初の段落で直接答える。
- 受けページを本文中で必ず紹介し、上記URLをそのまま1文字も変えずリンクとして書く。
- 受けページのタイトル・説明と補助素材にある事実だけを使う。機能・数字・日付を創作しない。
  詳細が素材にない場合は「詳しくは紹介ページを参照」と書く。
- 最後に「## 参考」を置き、受けページURLと使った補助素材URLを列挙する。

# 出力形式(厳守・この形式以外を出力しない)
TITLE: <「{q}」の主要な語を先頭近くに含む30〜60字のタイトル>
SLUG: <英小文字とハイフンのみ12〜50字>
---
<本文markdown>
"""


def pick_sources(conn, n: int = 3):
    """未使用のresearchからスコア・新しさ順に題材を選ぶ。

    video:% はvideo_digest専用の素材なので通常記事には使わない。
    """
    return conn.execute(
        "SELECT * FROM research WHERE used = 0 AND source NOT LIKE 'video:%'"
        " ORDER BY score DESC, id DESC LIMIT ?",
        (n,),
    ).fetchall()


def build_prompt(cfg: dict, sources) -> str:
    theme = cfg["create"].get("theme", "")
    # GSC opportunity(検索クエリ)起点なら「検索意図に答える解説記事」モード
    gsc_sources = [s for s in sources if s["url"].startswith("gsc://")]
    if gsc_sources:
        q = gsc_sources[0]["title"].replace("検索クエリ「", "").split("」")[0]
        news = [s for s in sources if not s["url"].startswith("gsc://")]
        news_lines = "\n".join(
            f"- {s['title']}\n  URL: {s['url']}\n  概要: {s['summary'] or '(なし)'}"
            for s in news) or "(なし)"
        return f"""あなたは技術メディア「AIKnowledgeCMS」の記事ライターです。
Google検索クエリ「{q}」で検索する読者の疑問に答える、日本語の解説記事を書いてください。

# 素材(実在の情報。記事の主題はこの素材が指すツール/リポジトリの解説)
{news_lines}

# 執筆ルール
- 900〜1500字。です・ます調。「{q}とは何か」に最初の段落で直接答える。
- 素材のdescription・スター数・言語は事実として使ってよい。それ以外の具体的な機能・数字・日付を創作しない。機能の詳細が素材にない場合は「詳細は公式リポジトリを参照」と書く。
- テーマ性の付け足し(AIエージェント経済等)は素材と自然に関係する場合のみ1〜2文まで。
- 記事内で参照するURLは補助素材のURLのみ使用可(無ければURLを書かない)。
- 最後に「## 参考」を置き、使った補助素材URLを列挙(無ければ「- 一般的な技術解説です」と1行)。

# 出力形式(厳守・この形式以外を出力しない)
TITLE: <30〜60字の記事タイトル(クエリの語を含める)>
SLUG: <英小文字とハイフンのみ12〜50字>
---
<本文markdown>
"""
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


def build_refresh_prompt(cfg: dict, article, metrics_summary: str, sources) -> str:
    """既存記事のリライト用プロンプト。素材=元記事本文+元記事の参照URL。"""
    src_lines = "\n".join(
        f"- {s['title']}\n  URL: {s['url']}\n  概要: {s['summary'] or '(なし)'}"
        for s in sources) or "(なし)"
    return f"""あなたは技術メディア「AIKnowledgeCMS」の記事ライターです。
以下の既存記事を、検索パフォーマンスを改善する目的でリライトしてください。

# 検索パフォーマンス(Google Search Console実測)
{metrics_summary}

# 既存記事タイトル
{article['title']}

# 既存記事本文
{article['body_md']}

# 参照可能な素材(元記事が根拠にした情報)
{src_lines}

# リライト方針
- 冒頭の1段落で検索者の疑問に直接答える(結論先出し)。
- タイトルは検索クエリの語を含めつつ、内容が具体的に伝わるよう改善してよい。
- 見出し(##)で構造を明確にし、900〜1600字に整える。
- 既存記事と素材にある事実だけを使う。新しい事実・数字・日付を創作しない。
- 記事内で参照するURLは上記素材のURLのみ使用可。
- 最後に「## 参考」を置き、使った素材URLを列挙(無ければ「- 一般的な技術解説です」と1行)。

# 出力形式(厳守・この形式以外を出力しない)
TITLE: <30〜60字の記事タイトル>
SLUG: {article['slug']}
---
<本文markdown>
"""


def generate_refresh(cfg: dict, conn, tick_id: int, refresh_row) -> dict | None:
    """リライト候補(refresh://slug/YYYYMM)から既存記事の改稿ドラフトを作る。

    ゲート不合格で公開中の記事を巻き込まないよう、一時slug({slug}--r)の
    contentドラフト行を作って検証させ、合格後にmaybe_create側で本体へ反映する。
    """
    slug = refresh_row["url"].removeprefix("refresh://").split("/")[0]
    article = conn.execute(
        "SELECT slug, title, body_md, sources FROM content"
        " WHERE slug=? AND status='published'", (slug,)).fetchone()
    if article is None:
        conn.execute("UPDATE research SET used=1 WHERE id=?", (refresh_row["id"],))
        conn.commit()
        state.record(conn, tick_id, NAME, "refresh_skipped", 1,
                     {"slug": slug, "reason": "published記事なし"})
        return None

    src_urls = [u for u in json.loads(article["sources"] or "[]")
                if u.startswith("http")]
    sources = []
    if src_urls:
        sources = conn.execute(
            "SELECT * FROM research WHERE url IN ({})".format(
                ",".join("?" * len(src_urls))), src_urls).fetchall()

    prompt = build_refresh_prompt(cfg, article, refresh_row["summary"] or "", sources)
    raw = _llm(cfg["create"]["generator"], cfg.get("agent_cli", ""), prompt,
               ollama_api=cfg["create"].get("ollama_api", DEFAULT_OLLAMA))
    parsed = parse_output(raw)
    if parsed is None:
        state.record(conn, tick_id, NAME, "refresh_parse_error", 1, {"raw": raw[:500]})
        return None

    tmp_slug = f"{slug[:46]}--r"
    conn.execute("DELETE FROM content WHERE slug=?", (tmp_slug,))
    if "## 参考" not in parsed["body"] and "##参考" not in parsed["body"]:
        refs = "\n".join(f"- {u}" for u in src_urls) or "- 一般的な技術解説です"
        parsed["body"] = parsed["body"].rstrip() + f"\n\n## 参考\n{refs}\n"
    conn.execute(
        "INSERT INTO content (slug, title, status, body_md, sources, created_tick, created_at)"
        " VALUES (?, ?, 'draft', ?, ?, ?, ?)",
        (tmp_slug, parsed["title"][:120], parsed["body"],
         json.dumps(src_urls, ensure_ascii=False), tick_id, state.now()))
    conn.commit()
    return {"slug": tmp_slug, "real_slug": slug, "refresh": True,
            "title": parsed["title"], "body": parsed["body"],
            "sources": src_urls, "source_ids": [refresh_row["id"]]}


def generate(cfg: dict, conn, tick_id: int) -> dict | None:
    """ドラフトを1本生成してcontentにdraftとして保存する。素材が無ければNone。"""
    sources = pick_sources(conn)
    if not sources:
        state.record(conn, tick_id, NAME, "create_skipped", 1, {"reason": "no_research"})
        return None

    # リライト候補が最優先のときはrefreshモード
    if sources and sources[0]["url"].startswith("refresh://"):
        return generate_refresh(cfg, conn, tick_id, sources[0])

    # 他プロパティで伸びているクエリ(gscnet://)起点: 受けページを接地素材に
    # 「解説+送客」記事を書く。受けページが取得できなければ書かない。
    network_prompt = None
    if sources and sources[0]["url"].startswith("gscnet://"):
        top = sources[0]
        try:
            meta = json.loads(top["summary"] or "{}")
        except Exception:
            meta = {}
        ctx = _fetch_page_context(meta.get("page", "")) if meta.get("page") else None
        if not meta.get("query") or ctx is None:
            conn.execute("UPDATE research SET used=1 WHERE id=?", (top["id"],))
            conn.commit()
            state.record(conn, tick_id, NAME, "network_skipped_no_page", 1,
                         {"url": top["url"]})
            sources = [s for s in pick_sources(conn) if not s["url"].startswith("gscnet://")]
            if not sources:
                state.record(conn, tick_id, NAME, "create_skipped", 1,
                             {"reason": "no_groundable_sources"})
                return None
        else:
            enrich = _enrich_query(conn, tick_id, meta["query"]) or []
            network_prompt = build_network_prompt(cfg, meta, ctx, enrich)
            sources = [top] + list(enrich)

    # クエリ起点のときは「1クエリ + GitHub実データ接地」に絞る。
    # 接地できないクエリは創作記事になるため書かず、使用済みにしてRSS素材へフォールバック。
    if network_prompt is None and sources and sources[0]["url"].startswith("gsc://"):
        top = sources[0]
        query = top["title"].replace("検索クエリ「", "").split("」")[0]
        enrich = _enrich_query(conn, tick_id, query)
        if enrich:
            sources = [top] + list(enrich)
        else:
            conn.execute("UPDATE research SET used=1 WHERE id=?", (top["id"],))
            conn.commit()
            state.record(conn, tick_id, NAME, "query_skipped_no_grounding", 1,
                         {"query": query})
            sources = [s for s in pick_sources(conn) if not s["url"].startswith("gsc://")]
            if not sources:
                state.record(conn, tick_id, NAME, "create_skipped", 1,
                             {"reason": "no_groundable_sources"})
                return None

    prompt = network_prompt or build_prompt(cfg, sources)
    raw = _llm(cfg["create"]["generator"], cfg.get("agent_cli", ""), prompt,
               ollama_api=cfg["create"].get("ollama_api", DEFAULT_OLLAMA))
    parsed = parse_output(raw)
    if parsed is None:
        state.record(conn, tick_id, NAME, "create_parse_error", 1, {"raw": raw[:500]})
        return None

    # slug衝突は連番で回避
    slug = parsed["slug"][:50].rstrip("-")
    base = slug
    i = 2
    while conn.execute("SELECT 1 FROM content WHERE slug=?", (slug,)).fetchone():
        slug = f"{base[:46]}-{i}"
        i += 1

    src_urls = [s["url"] for s in sources]
    if "## 参考" not in parsed["body"] and "##参考" not in parsed["body"]:
        http_refs = [u for u in src_urls if u.startswith("http")]
        refs = "\n".join(f"- {u}" for u in http_refs) or "- 一般的な技術解説です"
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
