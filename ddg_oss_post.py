#!/usr/bin/env python3
import sys
import re
import subprocess
import json
import time

# ========== 設定 ==========
API_URL = 'https://aiknowledgecms.exbridge.jp/saveoss.php'
OLLAMA  = 'https://exbridge.ddns.net/api/generate'
MODEL   = 'gemma3:12b'

SKIP_REPO_WORDS = [
    'awesome', 'collection', 'list', 'resources', 'roadmap',
    'cheatsheet', 'guide', 'tutorial', 'learning', 'examples',
    'templates', 'cookbook', 'papers', 'survey', 'notes',
    'bookmarks', 'reference', 'links', 'readings', 'study',
    'howto', 'best-practice', 'interview'
]

SKIP_GITHUB_USERS = [
    'topics', 'trending', 'search', 'login', 'orgs',
    'sponsors', 'features', 'marketplace', 'explore',
    'collections', 'events', 'about', 'pricing', 'apps'
]
# ==========================

def is_valid_github_repo(path):
    m = re.match(r'^/?([^/]+)/([^/\?#]+)/?$', path.replace('https://github.com', ''))
    if not m:
        return False
    user      = m.group(1).lower()
    repo_name = m.group(2).lower()
    if user in SKIP_GITHUB_USERS:
        return False
    for word in SKIP_REPO_WORDS:
        if word in repo_name:
            return False
    return True

def extract_title_from_readme(readme, fallback):
    """READMEから適切なタイトルを抽出"""
    for line in readme.splitlines():
        line = line.strip()
        if not line:
            continue
        line = re.sub(r'<[^>]+>', '', line).strip()
        if not line:
            continue
        line = re.sub(r'\[([^\]]+)\]\([^\)]+\)', r'\1', line)
        line = line.strip()
        if not line:
            continue
        if 'shields.io' in line or 'badge' in line.lower():
            continue
        if '|' in line:
            continue
        if line.startswith('!'):
            continue
        if line.startswith('>'):
            continue
        if line.startswith('-') or line.startswith('*'):
            continue
        if len(line) < 4 or len(line) > 120:
            continue
        if line.startswith('#'):
            title = line.lstrip('#').strip()
            if title and len(title) > 2:
                return title
        if not line.startswith('<') and not line.startswith('|'):
            return line
    return fallback

def extract_tags(post_text, github_url, title):
    """post_text のハッシュタグ + リポジトリ名 + 固定タグ"""
    # post_textからハッシュタグ抽出
    tags = re.findall(r'#(\w+)', post_text)

    # 汎用タグを一旦除外（後で固定追加する）
    generic = {'OSS', 'AI', 'GitHub', 'opensource', 'OpenSource', 'Github'}
    tags = [t for t in tags if t not in generic]

    # リポジトリ名をタグに追加
    m = re.match(r'https://github\.com/[^/]+/([^/\?#]+)', github_url)
    if m:
        repo_name = m.group(1)
        # ハイフン・アンダースコア・ドットを除去してCamelCase風に
        repo_tag = re.sub(r'[-_.]', '', repo_name)
        if repo_tag and repo_tag.lower() not in [t.lower() for t in tags]:
            tags.append(repo_tag)

    # 固定タグを末尾に追加
    for fixed in ['AI', 'OSS', 'GitHub']:
        if fixed not in tags:
            tags.append(fixed)

    # 重複除去・順序保持
    seen = set()
    result = []
    for t in tags:
        if t.lower() not in seen:
            seen.add(t.lower())
            result.append(t)

    return result

def fetch_github_trending(period='daily', language=''):
    url = f'https://github.com/trending?since={period}'
    if language:
        url = f'https://github.com/trending/{language}?since={period}'

    cmd = [
        'curl', '-s', '--max-time', '15',
        url,
        '-H', 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]
    result = subprocess.run(cmd, capture_output=True, text=True)
    html = result.stdout

    if not html:
        print('[DEBUG] GitHub Trending: 取得失敗')
        return []

    raw_paths = re.findall(r'href="/([a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+)"', html)

    seen = set()
    items = []
    for path in raw_paths:
        url = 'https://github.com/' + path
        if url in seen:
            continue
        seen.add(url)
        if not is_valid_github_repo(path):
            continue
        items.append({
            'url':       url,
            'snippet':   '',
            'is_github': True
        })

    print(f'[DEBUG] GitHub Trending: {len(items)}件取得')
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
        if result.stdout and '404' not in result.stdout[:100]:
            print(f'[DEBUG] README取得成功: {repo} ({branch})')
            return result.stdout[:2000]
    print(f'[DEBUG] README取得失敗: {github_url}')
    return ''

def ollama_request(prompt):
    payload = json.dumps({
        'model':  MODEL,
        'prompt': prompt,
        'stream': False
    }, ensure_ascii=False)
    cmd = [
        'curl', '-s', '--max-time', '120',
        OLLAMA,
        '-H', 'Content-Type: application/json',
        '-d', payload
    ]
    result = subprocess.run(cmd, capture_output=True, text=True)
    raw = result.stdout.strip()

    if not raw:
        print('[DEBUG] Ollama応答が空')
        return ''

    response_text = ''
    for line in raw.splitlines():
        line = line.strip()
        if not line:
            continue
        try:
            data = json.loads(line)
            chunk = data.get('response', '')
            response_text += chunk
            if data.get('done', False):
                break
        except Exception:
            continue

    if not response_text:
        try:
            data = json.loads(raw)
            response_text = data.get('response', '')
        except Exception:
            print(f'[DEBUG] Ollama応答エラー: {raw[:200]}')
            return ''

    response_text = '\n'.join(line.strip() for line in response_text.splitlines())
    return response_text.strip()

def make_analysis(title, url, readme, snippet):
    context = f'README抜粋:\n{readme}' if readme else f'概要: {snippet}'
    prompt = f"""以下のOSSについて、技術者向けに3点で簡潔に考察してください。

タイトル: {title}
URL: {url}
{context}

出力形式（この形式のみで出力）：
■ 概要（1行）
■ 特徴・用途（2〜3行）
■ 結論（1行）"""
    return ollama_request(prompt)

def make_post_text(title, url, readme, snippet):
    context = f'README抜粋:\n{readme}' if readme else f'概要: {snippet}'
    prompt = f"""あなたはAI系OSSを紹介するXアカウントの中の人です。
以下のOSSについてX投稿文を日本語で作成してください。

ルール：
- 本文は100文字以内
- 技術的に正確、具体的な特徴を1〜2点
- ハッシュタグは付けない（別途自動付与します）
- 煽り・誇張なし
- URLは含めない（別途付与します）

タイトル: {title}
{context}

投稿文のみ出力してください。"""
    return ollama_request(prompt)

def save_to_cms(title, github_url, analysis, post_text, tags):
    payload = json.dumps({
        'title':      title,
        'github_url': github_url,
        'analysis':   analysis,
        'post_text':  post_text,
        'tags':       tags
    }, ensure_ascii=False)
    cmd = [
        'curl', '-s', '--max-time', '15',
        API_URL,
        '-H', 'Content-Type: application/json',
        '-d', payload
    ]
    result = subprocess.run(cmd, capture_output=True, text=True)
    print(f'[DEBUG] CMS API response: {result.stdout[:200]}')
    try:
        return json.loads(result.stdout)
    except Exception:
        return {'error': result.stdout}

def fetch_posts():
    list_url = API_URL.replace('saveoss.php', 'data/oss_posts.json')
    cmd = [
        'curl', '-s', '--max-time', '10',
        list_url,
        '-H', 'User-Agent: Mozilla/5.0'
    ]
    result = subprocess.run(cmd, capture_output=True, text=True)
    try:
        posts = json.loads(result.stdout)
        return posts if posts else []
    except Exception:
        return []

def get_registered_urls():
    posts = fetch_posts()
    urls = set(p['github_url'] for p in posts if 'github_url' in p)
    print(f'[DEBUG] 登録済みURL数: {len(urls)}')
    return urls

def list_posts():
    posts = fetch_posts()
    if not posts:
        print('投稿がありません')
        return
    print(f'\n登録済み投稿一覧（{len(posts)}件）')
    print('='*60)
    for p in posts:
        print(f'ID  : {p["id"]}')
        print(f'題名: {p["title"]}')
        print(f'URL : {p["github_url"]}')
        print(f'日時: {p["created_at"]}')
        print('-'*40)

def delete_post(target_id):
    payload = json.dumps({
        'action': 'delete',
        'id':     target_id
    }, ensure_ascii=False)
    cmd = [
        'curl', '-s', '--max-time', '15',
        API_URL,
        '-H', 'Content-Type: application/json',
        '-d', payload
    ]
    result = subprocess.run(cmd, capture_output=True, text=True)
    print(f'[DEBUG] API response: {result.stdout[:200]}')
    try:
        res = json.loads(result.stdout)
        if res.get('status') == 'ok':
            print(f'削除完了: {target_id}')
        else:
            print(f'削除失敗: {res}')
    except Exception:
        print(f'削除失敗: {result.stdout[:200]}')

def delete_all_posts():
    payload = json.dumps({
        'action': 'deleteall'
    }, ensure_ascii=False)
    cmd = [
        'curl', '-s', '--max-time', '15',
        API_URL,
        '-H', 'Content-Type: application/json',
        '-d', payload
    ]
    result = subprocess.run(cmd, capture_output=True, text=True)
    print(f'[DEBUG] API response: {result.stdout[:200]}')
    try:
        res = json.loads(result.stdout)
        if res.get('status') == 'ok':
            print(f'全件削除完了（{res.get("deleted", "?")}件）')
        else:
            print(f'削除失敗: {res}')
    except Exception:
        print(f'削除失敗: {result.stdout[:200]}')

def main():
    args = sys.argv[1:]

    if '--list' in args:
        list_posts()
        return

    if '--delall' in args:
        posts = fetch_posts()
        if not posts:
            print('削除する投稿がありません')
            return
        print(f'{len(posts)}件を全件削除します。よろしいですか？ [y/N]: ', end='')
        ans = input().strip().lower()
        if ans != 'y':
            print('キャンセルしました')
            return
        delete_all_posts()
        return

    if '--del' in args:
        idx = args.index('--del')
        if idx + 1 >= len(args):
            print('使い方: python3 ddg_oss_post.py --del <ID>')
            print('IDは --list で確認できます')
            return
        delete_post(args[idx + 1])
        return

    period = 'daily'
    if '--weekly' in args:
        period = 'weekly'
    elif '--monthly' in args:
        period = 'monthly'

    language = ''
    if '--lang' in args:
        idx = args.index('--lang')
        if idx + 1 < len(args):
            language = args[idx + 1]

    top_n = 3
    if '--top' in args:
        idx = args.index('--top')
        if idx + 1 < len(args):
            top_n = int(args[idx + 1])

    dry_run = '--dry' in args

    print(f'ソース: GitHub Trending ({period})')
    print(f'言語: {language if language else "全言語"}')
    print(f'取得件数: {top_n}件')
    print(f'DryRun: {dry_run}\n')

    registered = get_registered_urls()
    results = fetch_github_trending(period=period, language=language)

    targets = []
    for r in results:
        if r['url'] in registered:
            print(f'[SKIP] 登録済み: {r["url"]}')
            continue
        targets.append(r)
        if len(targets) >= top_n:
            break

    if not targets:
        print('新規リポジトリが見つかりませんでした。')
        return

    print(f'新規対象: {len(targets)}件\n')

    for i, r in enumerate(targets):
        print(f'\n{"="*60}')
        print(f'[{i+1}/{len(targets)}] {r["url"]}')

        readme = fetch_github_readme(r['url'])

        fallback = r['url'].replace('https://github.com/', '')
        title = extract_title_from_readme(readme, fallback) if readme else fallback
        print(f'タイトル: {title}')

        print('Ollama: 考察生成中...')
        analysis = make_analysis(title, r['url'], readme, r['snippet'])

        print('Ollama: X投稿文生成中...')
        post_text = make_post_text(title, r['url'], readme, r['snippet'])

        # タグをリポジトリ名から自動生成
        tags = extract_tags(post_text, r['url'], title)

        # post_textにタグとURLを付与
        tag_str = ' '.join(['#' + t for t in tags])
        post_text_with_url = post_text.rstrip() + '\n' + tag_str + '\n' + r['url']

        print('\n--- X投稿文 ---')
        print(post_text_with_url)
        print('\n--- AI考察 ---')
        print(analysis)
        print('\n--- タグ ---')
        print(tags)

        if not dry_run:
            print('\nCMS登録中...')
            res = save_to_cms(title, r['url'], analysis, post_text_with_url, tags)
            status = res.get('status', '')
            if status == 'duplicate':
                print(f'[SKIP] CMS側で重複検出: {r["url"]}')
            elif status == 'ok':
                print(f'登録完了: {res.get("id")}')
                registered.add(r['url'])
            else:
                print(f'登録結果: {res}')
        else:
            print('\n[DryRun] CMS登録スキップ')

        if i < len(targets) - 1:
            time.sleep(2)

    print('\n完了')

if __name__ == '__main__':
    main()
