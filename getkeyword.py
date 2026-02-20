#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import sys
import argparse
import urllib.parse
import hashlib

import feedparser
import requests

# FastAPI（APIモード用）
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
import uvicorn

DEFAULT_NEWS_LIMIT = 5
DEFAULT_MAX_SEEDS = 10
DEFAULT_OLLAMA_URL = "http://localhost:11434/api/generate"
DEFAULT_MODEL = "gemma3:12b"
API_PORT = 8003

# ============================
# FastAPI APP（★先に定義）
# ============================
app = FastAPI(title="getkeyword API", version="1.0")

# ============================
# Core Logic（唯一の中核）
# ============================
def extract_keywords(base, max_seeds=DEFAULT_MAX_SEEDS):

    base_news = fetch_google_news_rss(base, DEFAULT_NEWS_LIMIT)
    if not base_news:
        return []

    base_fingerprints = {
        news_fingerprint(n) for n in base_news
    }

    resp = call_ollama(
        DEFAULT_OLLAMA_URL,
        DEFAULT_MODEL,
        build_seed_prompt(base)
    )

    seeds = parse_seeds(resp)

    results = []

    for s in seeds:
        if s == base:
            continue

        news = fetch_google_news_rss(s, 3)
        if not news:
            continue

        duplicated = False
        for n in news:
            if news_fingerprint(n) in base_fingerprints:
                duplicated = True
                break
        if duplicated:
            continue

        results.append({
            "keyword": s,
            "url": news[0]["link"]
        })

        if len(results) >= max_seeds:
            break

    return results

# =========================
# AI / Ollama : Keyword Type Batch
# =========================
class KeywordTypeBatchRequest(BaseModel):
    keywords: list

@app.post("/keyword_type_batch")
def keyword_type_batch(req: KeywordTypeBatchRequest):

    if not isinstance(req.keywords, list) or not req.keywords:
        raise HTTPException(status_code=400, detail="keywords required")

    keywords = [str(k).strip() for k in req.keywords if str(k).strip()]
    if not keywords:
        raise HTTPException(status_code=400, detail="empty keywords")

    joined = "\n".join(keywords)

    prompt = f"""
あなたは用語分類AIです。
次の単語群を「general」または「technical」に分類してください。

# 単語一覧
{joined}

# 判定基準
- 専門知識がない人でも意味が分かる単語は general
- 中学生でも説明なしに理解できる語は general
- 特定分野の専門知識がないと意味が分からない語のみ technical
- general判定は積極的に行う
- JSONのみ出力

# 出力形式
[
  {{"keyword":"単語","type":"general"}},
  {{"keyword":"単語","type":"technical"}}
]
"""

    payload = {
        "model": OLLAMA_MODEL,
        "prompt": prompt,
        "stream": False,
        "options": {
            "temperature": 0.0
        }
    }

    try:
        r = requests.post(
            OLLAMA_URL,
            json=payload,
            timeout=120
        )
        r.raise_for_status()
        data = r.json()
    except Exception as e:
        raise HTTPException(status_code=502, detail=str(e))

    text = data.get("response", "").strip()

    import json
    try:
        parsed = json.loads(text)
        if not isinstance(parsed, list):
            raise Exception("invalid format")
    except Exception:
        # 失敗時は全部technical扱い
        parsed = [{"keyword":k, "type":"technical"} for k in keywords]

    return {
        "ok": True,
        "results": parsed
    }


# =========================
# AI / Ollama : Keyword Type
# =========================
class KeywordTypeRequest(BaseModel):
    keyword: str

@app.post("/keyword_type")
def keyword_type(req: KeywordTypeRequest):

    keyword = req.keyword.strip()
    if not keyword:
        raise HTTPException(status_code=400, detail="keyword required")

    prompt = f"""
あなたは用語分類AIです。

次の単語が「一般語」か「専門用語」か判定してください。

# 単語
{keyword}

# 判定基準
- 日常会話で普通に使われる語は general
- IT・医療・法律・金融など特定分野で主に使われる語は technical
- 固有名詞（製品名・技術名）は technical
- JSONのみ出力

# 出力形式
{{"type":"general"}} または {{"type":"technical"}}
"""

    payload = {
        "model": OLLAMA_MODEL,
        "prompt": prompt,
        "stream": False,
        "options": {
            "temperature": 0.0
        }
    }

    try:
        r = requests.post(
            OLLAMA_URL,
            json=payload,
            timeout=60
        )
        r.raise_for_status()
        data = r.json()
    except Exception as e:
        raise HTTPException(status_code=502, detail=str(e))

    text = data.get("response", "").strip()

    try:
        parsed = json.loads(text)
        t = parsed.get("type", "technical")
    except Exception:
        t = "technical"

    if t not in ["general", "technical"]:
        t = "technical"

    return {
        "ok": True,
        "keyword": keyword,
        "type": t
    }


# =========================
# AI / Ollama : Daily Summary
# =========================
class DailySummaryRequest(BaseModel):
    texts: list
    date: str | None = None

class PromptRequest(BaseModel):
    prompt: str

@app.post("/daily_summary")
def daily_summary(req: PromptRequest):

    prompt = req.prompt

    if not prompt or not isinstance(prompt, str):
        raise HTTPException(status_code=400, detail="prompt required")

    payload = {
        "model": OLLAMA_MODEL,
        "prompt": prompt,
        "stream": False,
        "options": {
            "temperature": 0.3,
            "seed": 12345,
            "top_p": 0.8
        }
    }

    try:
        r = requests.post(
            OLLAMA_URL,
            json=payload,
            timeout=240
        )
        r.raise_for_status()
        data = r.json()
    except Exception as e:
        raise HTTPException(status_code=502, detail=str(e))

    text = data.get("response", "")
    if not isinstance(text, str):
        text = ""

    return {
        "summary": text.strip()
    }


@app.post("/daily_summary2")
def daily_summary2(req: DailySummaryRequest):

    texts = req.texts
    date  = req.date or ""

    if not isinstance(texts, list) or not texts:
        raise HTTPException(status_code=400, detail="texts required")

    joined = "\n\n".join([t.strip() for t in texts if isinstance(t, str) and t.strip()])

    if not joined:
        raise HTTPException(status_code=400, detail="empty texts")

    prompt = f"""
あなたはナレッジエディターです。
以下は同一日の複数の知識レポートです。

内容を相互に関連づけて統合し、
「1日分の知識まとめ」を日本語の読み物として作成してください。

条件：
・600〜2200文字
・感想、評価、称賛、改善提案は禁止
・見出し、箇条書き、URLは禁止
・ニュースの羅列は禁止
・事実と論点のみを書く
・最後に、その日全体から読み取れることを短くまとめる
・必ず日本語で、外国語は禁止です。日本語で生成してください

# 日付
{date}

# 知識レポート一覧
{joined}
"""

    payload = {
        "model": OLLAMA_MODEL,
        "prompt": prompt,
        "stream": False,
        "options": {
            "temperature": 0.3,
            "seed": 12345,
            "top_p": 0.8
        }
    }

    try:
        r = requests.post(
            OLLAMA_URL,
            json=payload,
            timeout=240
        )
        r.raise_for_status()
        data = r.json()
    except Exception as e:
        raise HTTPException(status_code=502, detail=str(e))

    text = data.get("response", "")
    if not isinstance(text, str):
        text = ""

    return {
        "ok": True,
        "summary": text.strip()
    }

# =========================
# AI / Ollama : News Analysis
# =========================
class NewsAnalysisRequest(BaseModel):
    keyword: str
    news: list

@app.post("/news_analysis")
def news_analysis(req: NewsAnalysisRequest):

    keyword = req.keyword.strip()
    news_items = req.news

    if not keyword or not isinstance(news_items, list) or not news_items:
        raise HTTPException(status_code=400, detail="invalid parameters")

    lines = []
    i = 1
    for n in news_items:
        title = n.get("title", "").strip()
        date  = n.get("pubDate", "").strip()
        if title:
            lines.append(f"{i}. {title} ({date})")
            i += 1

    news_text = "\n".join(lines)

    prompt = f"""
あなたはプロのリサーチャー兼ナレッジエディターです。
以下のニュース一覧をもとに、情報を整理・統合し、
後から読み返しても価値がある考察文章を日本語で作成してください。

# キーワード
{keyword}

# ニュース一覧
{news_text}

# 条件
- 600〜900文字
- 見出し・箇条書き・挨拶・URLは禁止
- 読み物として自然な文章のみ
- 主観的な感想は入れない
- 短期的な速報性より再読価値を重視

# 開始文（改変禁止）
- {keyword}に関する最近の動向について整理する。
"""

    payload = {
        "model": OLLAMA_MODEL,
        "prompt": prompt,
        "stream": False,
        "options": {
            "temperature": 0.7
        }
    }

    try:
        r = requests.post(
            OLLAMA_URL,
            json=payload,
            timeout=180
        )
        r.raise_for_status()
        data = r.json()
    except Exception as e:
        raise HTTPException(status_code=502, detail=str(e))

    text = data.get("response", "")
    if not isinstance(text, str):
        text = ""

    return {
        "ok": True,
        "analysis": text.strip()
    }

# =========================
# AI / Ollama : Keyword Seed
# =========================
OLLAMA_URL = "http://127.0.0.1:11434/api/generate"
OLLAMA_MODEL = "gemma3:12b"

class KeywordSeedRequest(BaseModel):
    keyword: str

@app.post("/keyword_seed")
def keyword_seed(req: KeywordSeedRequest):

    keyword = req.keyword.strip()
    if not keyword:
        raise HTTPException(status_code=400, detail="keyword required")

    prompt = f"""
あなたはWEBメディア編集者兼リサーチャーです。

以下のキーワードを起点に、
ニュース記事・技術記事として継続的に扱いやすい
関連キーワードを10個生成してください。

# 元キーワード
{keyword}

# 条件
- 名詞
- 技術・IT・AI・Web業界で実際に使われる用語
- 直近1年以内にニュースや記事が複数存在する語
- 企業名・プロダクト名・技術名称・研究分野名は可
- 単独で意味が広すぎる抽象概念は禁止
- 記事タイトルとして成立する
- 3つだけ
- 番号・記号・説明は禁止
- 改行区切りのみで出力
"""

    payload = {
        "model": OLLAMA_MODEL,
        "prompt": prompt,
        "stream": False,
        "options": {
            "temperature": 0.7
        }
    }

    try:
        r = requests.post(
            OLLAMA_URL,
            json=payload,
            timeout=120
        )
        r.raise_for_status()
        data = r.json()
    except Exception as e:
        raise HTTPException(status_code=502, detail=str(e))

    text = data.get("response", "").strip()
    seeds = [line.strip() for line in text.split("\n") if line.strip()]

    return {
        "ok": True,
        "keyword": keyword,
        "seeds": seeds[:3]
    }

# ============================
# API : getkeyword
# ============================
class KeywordRequest(BaseModel):
    keyword: str
    max_seeds: int | None = DEFAULT_MAX_SEEDS

@app.post("/getkeyword")
def getkeyword_api(req: KeywordRequest):

    base = req.keyword.strip()
    if not base:
        raise HTTPException(status_code=400, detail="keyword is empty")

    results = extract_keywords(base, req.max_seeds or DEFAULT_MAX_SEEDS)

    return {
        "keyword": base,
        "results": results
    }



# ----------------------------
# News
# ----------------------------
def fetch_google_news_rss(keyword, limit):
    q = urllib.parse.quote(keyword)
    url = f"https://news.google.com/rss/search?q={q}&hl=ja&gl=JP&ceid=JP:ja"

    feed = feedparser.parse(url)
    if not getattr(feed, "entries", None):
        return []

    items = []
    for e in feed.entries[:limit]:
        items.append({
            "title": (e.get("title") or "").strip(),
            "link": (e.get("link") or "").strip(),
            "summary": (e.get("summary") or "").strip(),
        })
    return items


def news_fingerprint(item):
    s = (item.get("title", "") + item.get("link", "")).encode("utf-8")
    return hashlib.sha1(s).hexdigest()


# ----------------------------
# Ollama
# ----------------------------
def call_ollama(url, model, prompt, timeout=120):
    r = requests.post(
        url,
        json={"model": model, "prompt": prompt, "stream": False},
        timeout=timeout
    )
    r.raise_for_status()
    return r.json().get("response", "")


# ----------------------------
# Prompt
# ----------------------------
def build_seed_prompt(base):
    return f"""
あなたはWEBメディア編集者兼リサーチャーです。

以下のキーワードを起点に、
**ニュース記事・技術記事として継続的に扱いやすい**
関連キーワードを生成してください。

# 元キーワード
{base}

# 条件
- 名詞
- 技術・IT・AI・Web業界で実際に使われる用語
- 単独で意味が広すぎる抽象概念は禁止
- 記事タイトルとして成立する
- 10個まで
- 番号・記号・説明は禁止
- 改行区切りのみで出力
"""


def parse_seeds(text):
    results = []
    for line in text.splitlines():
        line = line.strip()
        if not line:
            continue
        if line[0] in "-*0123456789":
            continue
        results.append(line)
    return results

# ============================
# Entry
# ============================
def run_api():
    uvicorn.run(app, host="0.0.0.0", port=API_PORT)

if __name__ == "__main__":
    run_api()



