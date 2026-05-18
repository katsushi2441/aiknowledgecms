#!/usr/bin/env python3
import sys
import re
import subprocess
import json
from urllib.parse import unquote

AIGM_KEYWORDS = [
    'video generation', 'music generation', 'agent', 'multiagent',
    'whisper', 'subtitle', 'caption', 'tts', 'voice', 'audio',
    'llm', 'ollama', 'fastapi', 'seo', 'content generation',
    'affiliate', 'monetization', 'sns', 'knowledge', 'rss'
]

def search_ddg(query, max_results=10):
    q = query.replace(' ', '+')
    cmd = [
        'curl', '-s', '--max-time', '10',
        f'https://lite.duckduckgo.com/lite/?q={q}',
        '-H', 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        '-b', 'ax=v315-0'
    ]
    result = subprocess.run(cmd, capture_output=True, text=True)
    html = result.stdout

    if not html or 'result-link' not in html:
        return []

    titles = re.findall(r"class='result-link'[^>]*>([^<]+)</a>", html)
    raw_urls = re.findall(r'uddg=([^&"]+)', html)
    urls = [unquote(u) for u in raw_urls]
    snippets = re.findall(r"class='result-snippet'>\s*(.+?)\s*</td>", html, re.DOTALL)
    snippets = [re.sub(r'<[^>]+>', '', s).strip() for s in snippets]

    items = []
    for i in range(min(len(titles), len(urls), max_results)):
        title = titles[i].strip()
        snippet = snippets[i] if i < len(snippets) else ''
        url = urls[i]
        text = (title + ' ' + snippet).lower()

        # AIGMキーワードスコアリング
        score = sum(1 for kw in AIGM_KEYWORDS if kw in text)

        items.append({
            'title': title,
            'url': url,
            'snippet': snippet,
            'is_github': 'github.com' in url,
            'aigm_score': score
        })

    # スコア降順ソート
    items.sort(key=lambda x: (x['aigm_score'], x['is_github']), reverse=True)
    return items

def ollama_summarize(title, snippet):
    prompt = f"以下のOSSツールがAIGM（AI Generated Media）システムに有用かを1行で評価してください。\\nタイトル: {title}\\n概要: {snippet}"
    payload = json.dumps({
        "model": "gemma3:12b",
        "prompt": prompt,
        "stream": False
    })
    cmd = [
        'curl', '-s', '--max-time', '30',
        'https://exbridge.ddns.net/api/generate',
        '-H', 'Content-Type: application/json',
        '-d', payload
    ]
    result = subprocess.run(cmd, capture_output=True, text=True)
    try:
        data = json.loads(result.stdout)
        return data.get('response', '').strip()
    except Exception:
        return ''

if __name__ == '__main__':
    query = sys.argv[1] if len(sys.argv) > 1 else 'AI OSS video music agent github 2026'
    use_ollama = '--ollama' in sys.argv

    results = search_ddg(query)

    print(f"=== DDG検索結果: {query} ===\n")
    for i, r in enumerate(results):
        print(f"[{i+1}] {r['title']}")
        print(f"    URL     : {r['url']}")
        print(f"    GitHub  : {r['is_github']}")
        print(f"    AIGMスコア: {r['aigm_score']}")
        print(f"    概要    : {r['snippet'][:100]}")

        if use_ollama and r['aigm_score'] > 0:
            evaluation = ollama_summarize(r['title'], r['snippet'])
            print(f"    Ollama評価: {evaluation}")
        print()
