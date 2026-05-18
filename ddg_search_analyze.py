#!/usr/bin/env python3
import sys
import re
import subprocess
import json
from urllib.parse import unquote

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
        items.append({
            'title': titles[i].strip(),
            'url': urls[i],
            'snippet': snippets[i] if i < len(snippets) else '',
            'is_github': 'github.com' in urls[i]
        })
    return items

def fetch_github_readme(github_url):
    m = re.match(r'https://github\.com/([^/]+/[^/]+?)(?:/|$)', github_url)
    if not m:
        return ''
    repo = m.group(1)
    for branch in ['main', 'master']:
        cmd = [
            'curl', '-s', '--max-time', '15',
            f'https://raw.githubusercontent.com/{repo}/{branch}/README.md',
            '-H', 'User-Agent: Mozilla/5.0'
        ]
        result = subprocess.run(cmd, capture_output=True, text=True)
        if result.stdout and '404' not in result.stdout[:50]:
            return result.stdout[:2000]
    return ''

def ollama_make_post(title, url, readme, snippet):
    context = f"README抜粋:\n{readme}" if readme else f"概要: {snippet}"

    prompt = f"""あなたはAI系OSSを紹介するXアカウントの中の人です。
以下のOSSについて、X（旧Twitter）への投稿文を日本語で作成してください。

ルール：
- 140文字以内（URLは含めない）
- 技術者向け、具体的な特徴を1〜2点
- ハッシュタグ2〜3個（#AI #OSS #GitHub など適切なもの）
- 煽り・誇張なし、技術的に正確に
- 最後にURLを別行で入れる枠を用意（[URL]と記載）

タイトル: {title}
URL: {url}
{context}

投稿文のみ出力してください。"""

    payload = json.dumps({
        "model": "gemma3:12b",
        "prompt": prompt,
        "stream": False
    }, ensure_ascii=False)

    cmd = [
        'curl', '-s', '--max-time', '60',
        'https://exbridge.ddns.net/api/generate',
        '-H', 'Content-Type: application/json',
        '-d', payload
    ]
    result = subprocess.run(cmd, capture_output=True, text=True)
    try:
        data = json.loads(result.stdout)
        response = data.get('response', '').strip()
        # URLプレースホルダーを実URLに置換
        response = response.replace('[URL]', url)
        return response
    except Exception:
        return '（Ollama応答エラー）'

if __name__ == '__main__':
    query = sys.argv[1] if len(sys.argv) > 1 else 'AI OSS github 2026'
    top_n = 3
    if '--top' in sys.argv:
        idx = sys.argv.index('--top')
        if idx + 1 < len(sys.argv):
            top_n = int(sys.argv[idx + 1])

    print(f"検索中: {query}\n")
    results = search_ddg(query)

    # GitHubリポジトリ優先
    targets = [r for r in results if r['is_github']][:top_n]
    if not targets:
        targets = results[:top_n]

    for i, r in enumerate(targets):
        print(f"{'='*60}")
        print(f"[{i+1}] {r['title']}")
        print(f"URL: {r['url']}\n")

        readme = fetch_github_readme(r['url']) if r['is_github'] else ''
        post = ollama_make_post(r['title'], r['url'], readme, r['snippet'])

        print("--- X投稿文 ---")
        print(post)
        print()
