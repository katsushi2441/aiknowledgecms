#!/usr/bin/env python3
"""
oss_worker.py - OSS Timeline 常駐ワーカー
GitHub Trendingを定期取得してCMSに登録する

実行:
  python3 oss_worker.py          # 常駐
  python3 oss_worker.py --now    # 即時実行
  python3 oss_worker.py --now --weekly  # 週間トレンドで即時実行
"""
import sys
import os
import re
import subprocess
import json
import time
import datetime
import logging

# ========== 設定 ==========
API_URL  = 'https://aiknowledgecms.exbridge.jp/saveoss.php'
OLLAMA   = 'https://exbridge.ddns.net/api/generate'
MODEL    = 'gemma4:e4b'
LOG_FILE = os.path.expanduser('~/oss_worker.log')

# 1日4回実行する時刻（24h）
DAILY_HOURS  = [6, 12, 18, 0]
DAILY_TOP_N  = 3   # 1回あたり何件登録するか

# 週間トレンドは月曜6時のみ
WEEKLY_DAY   = 0   # 0=月曜
WEEKLY_HOUR  = 6
WEEKLY_TOP_N = 5

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

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(message)s',
    handlers=[
        logging.FileHandler(LOG_FILE),
        logging.StreamHandler(sys.stdout)
    ]
)
log = logging.getLogger(__name__)


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
        if line.startswith('!') or line.startswith('>'):
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

def extract_tags(post_text, github_url):
    tags = re.findall(r'#(\w+)', post_text)
    generic = {'OSS', 'AI', 'GitHub', 'opensource', 'OpenSource', 'Github'}
    tags = [t for t in tags if t not in generic]

    m = re.match(r'https://github\.com/[^/]+/([^/\?#]+)', github_url)
    if m:
        repo_tag = re.sub(r'[-_.]', '', m.group(1))
        if repo_tag and repo_tag.lower() not in [t.lower() for t in tags]:
            tags.append(repo_tag)

    for fixed in ['AI', 'OSS', 'GitHub']:
        if fixed not in tags:
            tags.append(fixed)

    seen = set()
    result = []
    for t in tags:
        if t.lower() not in seen:
            seen.add(t.lower())
            result.append(t)
    return result

def fetch_github_trending(period='daily', language=''):
    import datetime
    today = datetime.date.today()
    if period == 'daily':
        since = today - datetime.timedelta(days=1)
    elif period == 'weekly':
        since = today - datetime.timedelta(days=7)
    else:
        since = today - datetime.timedelta(days=30)

    q = 'pushed:>' + str(since) + '+stars:>50'
    if language:
        q += '+language:' + language

    url = ('https://api.github.com/search/repositories'
           '?q=' + q + '&sort=stars&order=desc&per_page=30')

    cmd = ['curl', '-s', '--max-time', '15', url,
           '-H', 'Accept: application/vnd.github+json',
           '-H', 'User-Agent: oss-worker/1.0']
    result = subprocess.run(cmd, capture_output=True, text=True)
    raw = result.stdout.strip()
    if not raw:
        log.warning('GitHub Search API: 取得失敗')
        return []

    try:
        data = json.loads(raw)
    except Exception:
        log.warning('GitHub Search API: JSONパースエラー')
        return []

    items = []
    seen = set()
    for repo in data.get('items', []):
        url = repo.get('html_url', '')
        if not url or url in seen:
            continue
        seen.add(url)
        path = url.replace('https://github.com/', '')
        if not is_valid_github_repo(path):
            continue
        snippet = repo.get('description', '') or ''
        items.append({'url': url, 'snippet': snippet})

    log.info('GitHub Search API (%s): %d件取得', period, len(items))
    return items

def fetch_github_trending2(period='daily', language=''):
    url = 'https://github.com/trending?since=' + period
    if language:
        url = 'https://github.com/trending/' + language + '?since=' + period
    cmd = [
        'curl', '-s', '--max-time', '15', url,
        '-H', 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]
    result = subprocess.run(cmd, capture_output=True, text=True)
    html = result.stdout
    if not html:
        log.warning('GitHub Trending: 取得失敗')
        return []

    raw_paths = re.findall(r'href="/([a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+)"', html)
    seen = set()
    items = []
    for path in raw_paths:
        repo_url = 'https://github.com/' + path
        if repo_url in seen:
            continue
        seen.add(repo_url)
        if not is_valid_github_repo(path):
            continue
        items.append({'url': repo_url, 'snippet': ''})

    log.info('GitHub Trending (%s): %d件取得', period, len(items))
    return items

def fetch_github_readme(github_url):
    m = re.match(r'https://github\.com/([^/]+/[^/]+?)(?:/|$)', github_url)
    if not m:
        return ''
    repo = m.group(1)
    for branch in ['main', 'master']:
        cmd = [
            'curl', '-s', '--max-time', '15',
            'https://raw.githubusercontent.com/' + repo + '/' + branch + '/README.md',
            '-H', 'User-Agent: Mozilla/5.0'
        ]
        result = subprocess.run(cmd, capture_output=True, text=True)
        if result.stdout and '404' not in result.stdout[:100]:
            return result.stdout[:2000]
    return ''

def ollama_request(prompt):
    payload = json.dumps({
        'model': MODEL, 'prompt': prompt, 'stream': False
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
        return ''

    response_text = ''
    for line in raw.splitlines():
        line = line.strip()
        if not line:
            continue
        try:
            data = json.loads(line)
            response_text += data.get('response', '')
            if data.get('done', False):
                break
        except Exception:
            continue

    if not response_text:
        try:
            data = json.loads(raw)
            response_text = data.get('response', '')
        except Exception:
            log.warning('Ollama応答エラー: %s', raw[:100])
            return ''

    response_text = '\n'.join(line.strip() for line in response_text.splitlines())
    return response_text.strip()

def make_analysis(title, url, readme, snippet):
    context = 'README抜粋:\n' + readme if readme else '概要: ' + snippet
    prompt = """以下のOSSについて、技術者向けに3点で簡潔に考察してください。

タイトル: {title}
URL: {url}
{context}

出力形式（この形式のみで出力）：
■ 概要（1行）
■ 特徴・用途（2〜3行）
■ 結論（1行）""".format(title=title, url=url, context=context)
    return ollama_request(prompt)

def make_post_text(title, url, readme, snippet):
    context = 'README抜粋:\n' + readme if readme else '概要: ' + snippet
    prompt = """あなたはAI系OSSを紹介するXアカウントの中の人です。
以下のOSSについてX投稿文を日本語で作成してください。

ルール：
- 本文は100文字以内
- 技術的に正確、具体的な特徴を1〜2点
- ハッシュタグは付けない（別途自動付与します）
- 煽り・誇張なし
- URLは含めない（別途付与します）

タイトル: {title}
{context}

投稿文のみ出力してください。""".format(title=title, context=context)
    return ollama_request(prompt)

def save_to_cms(title, github_url, analysis, post_text, tags):
    payload = json.dumps({
        'title': title, 'github_url': github_url,
        'analysis': analysis, 'post_text': post_text, 'tags': tags
    }, ensure_ascii=False)
    cmd = [
        'curl', '-s', '--max-time', '15',
        API_URL,
        '-H', 'Content-Type: application/json',
        '-d', payload
    ]
    result = subprocess.run(cmd, capture_output=True, text=True)
    try:
        return json.loads(result.stdout)
    except Exception:
        return {'error': result.stdout[:100]}

def fetch_registered_urls():
    list_url = API_URL.replace('saveoss.php', 'data/oss_posts.json')
    cmd = ['curl', '-s', '--max-time', '10', list_url, '-H', 'User-Agent: Mozilla/5.0']
    result = subprocess.run(cmd, capture_output=True, text=True)
    try:
        posts = json.loads(result.stdout)
        return set(p['github_url'] for p in posts if 'github_url' in p)
    except Exception:
        return set()

def run_job(period='daily', top_n=3):
    log.info('===== JOB START: %s top=%d =====', period, top_n)
    registered = fetch_registered_urls()
    log.info('登録済み: %d件', len(registered))

    results = fetch_github_trending(period=period)
    targets = []
    for r in results:
        if r['url'] in registered:
            continue
        targets.append(r)
        if len(targets) >= top_n:
            break

    if not targets:
        log.info('新規リポジトリなし')
        return

    log.info('新規対象: %d件', len(targets))

    for i, r in enumerate(targets):
        log.info('[%d/%d] %s', i + 1, len(targets), r['url'])

        readme   = fetch_github_readme(r['url'])
        fallback = r['url'].replace('https://github.com/', '')
        title    = extract_title_from_readme(readme, fallback) if readme else fallback

        log.info('タイトル: %s', title)

        analysis  = make_analysis(title, r['url'], readme, r['snippet'])
        post_text = make_post_text(title, r['url'], readme, r['snippet'])
        tags      = extract_tags(post_text, r['url'])
        tag_str   = ' '.join(['#' + t for t in tags])
        post_full = post_text.rstrip() + '\n' + tag_str + '\n' + r['url']

        res    = save_to_cms(title, r['url'], analysis, post_full, tags)
        status = res.get('status', '')
        if status == 'ok':
            log.info('登録完了: %s', res.get('id'))
            registered.add(r['url'])
        elif status == 'duplicate':
            log.info('重複スキップ: %s', r['url'])
        else:
            log.warning('登録失敗: %s', res)

        if i < len(targets) - 1:
            time.sleep(3)

    log.info('===== JOB END =====')

def main():
    log.info('oss_worker 起動 - 実行時刻: %s', str(DAILY_HOURS))

    # 実行済み記録: {(date, hour): True}
    done = {}

    while True:
        now  = datetime.datetime.now()
        hour = now.hour
        date = now.date()
        key  = (date, hour)

        # デイリー4回実行チェック
        if hour in DAILY_HOURS and key not in done:
            done[key] = True
            try:
                run_job(period='daily', top_n=DAILY_TOP_N)
            except Exception as e:
                log.error('デイリージョブエラー: %s', e)

            # 月曜6時は週間トレンドも追加実行
            if now.weekday() == WEEKLY_DAY and hour == WEEKLY_HOUR:
                weekly_key = (date, 'weekly')
                if weekly_key not in done:
                    done[weekly_key] = True
                    time.sleep(60)
                    try:
                        run_job(period='weekly', top_n=WEEKLY_TOP_N)
                    except Exception as e:
                        log.error('ウィークリージョブエラー: %s', e)

        # 古いdoneエントリを削除（メモリ節約）
        yesterday = date - datetime.timedelta(days=2)
        done = {k: v for k, v in done.items() if not (isinstance(k[0], datetime.date) and k[0] < yesterday)}

        time.sleep(60)

if __name__ == '__main__':
    if len(sys.argv) > 1 and sys.argv[1] == '--now':
        period = 'weekly' if '--weekly' in sys.argv else 'daily'
        top_n  = WEEKLY_TOP_N if period == 'weekly' else DAILY_TOP_N
        run_job(period=period, top_n=top_n)
    else:
        main()
