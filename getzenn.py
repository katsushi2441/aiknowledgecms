#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
zenn_collect.py
Zennからアカウント収集 → https://aiknowledgecms.exbridge.jp/saveaccounts.php にPOST
"""

import time
import json
import sys
import urllib.request
import urllib.error
import urllib.parse

# qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
# 設定
# qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
SAVE_API_URL  = 'https://aiknowledgecms.exbridge.jp/saveaccounts.php'
SAVE_API_TOKEN = 'AIKNOWLEDGE_SAVE_TOKEN_HERE'  # saveaccounts.php側と合わせる

ZENN_TOPICS_LIMIT = 50   # 人気トピック上位取得件数
ZENN_PAGES        = 3
SLEEP_PAGE    = 2   # ページ間
SLEEP_TOPIC   = 3   # トピック間
SLEEP_USER    = 1   # ユーザー詳細間
SLEEP_TAG     = 1   # タグ取得間
SLEEP_429     = 5   # 429発生時

# qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
# HTTP取得
# qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
def fetch_json(url, timeout=15):
    """GETしてJSONをパース。(data, http_status) を返す。失敗時はdata=None"""
    req = urllib.request.Request(
        url,
        headers={
            'User-Agent': 'Mozilla/5.0 (compatible; AIKnowledgeBot/1.0)',
            'Accept': 'application/json',
        }
    )
    try:
        with urllib.request.urlopen(req, timeout=timeout) as res:
            body = res.read().decode('utf-8')
            return json.loads(body), res.status
    except urllib.error.HTTPError as e:
        print('[DEBUG] HTTPError url=%s status=%d' % (url, e.code), flush=True)
        return None, e.code
    except Exception as ex:
        print('[DEBUG] fetch error url=%s err=%s' % (url, str(ex)), flush=True)
        return None, 0

def post_json(url, payload, token, timeout=15):
    """dictをJSONでPOST。(data, http_status) を返す。"""
    body = json.dumps(payload, ensure_ascii=False).encode('utf-8')
    req = urllib.request.Request(
        url,
        data=body,
        headers={
            'Content-Type': 'application/json',
            'X-Save-Token': token,
        },
        method='POST'
    )
    try:
        with urllib.request.urlopen(req, timeout=timeout) as res:
            resp_body = res.read().decode('utf-8')
            return json.loads(resp_body), res.status
    except urllib.error.HTTPError as e:
        body_err = e.read().decode('utf-8', errors='replace')
        print('[DEBUG] POST HTTPError status=%d body=%s' % (e.code, body_err[:200]), flush=True)
        return None, e.code
    except Exception as ex:
        print('[DEBUG] POST error err=%s' % str(ex), flush=True)
        return None, 0

# qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
# Zennユーティリティ
# qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
def zenn_get_user_tags(zenn_username):
    """ユーザーの記事からタグを最大5件取得"""
    url = 'https://zenn.dev/api/articles?username=%s&order=latest' % zenn_username
    data, http = fetch_json(url)
    if http == 429:
        print('[WARN] 429 tags zenn:%s — sleep(%d)' % (zenn_username, SLEEP_429), flush=True)
        time.sleep(SLEEP_429)
        return []
    if not data or 'articles' not in data:
        return []
    tag_count = {}
    for article in data['articles']:
        topics = article.get('topics', [])
        for t in topics:
            if isinstance(t, dict):
                name = t.get('name', '')
            else:
                name = t
            if name:
                tag_count[name] = tag_count.get(name, 0) + 1
    sorted_tags = sorted(tag_count.items(), key=lambda x: x[1], reverse=True)
    return [k for k, v in sorted_tags[:5]]

# qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
# STEP0: 人気トピック上位N件を動的取得
# qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
def fetch_popular_topics(limit=50):
    """https://zenn.dev/api/topics?order=popular から上位limit件のトピックslugを返す"""
    collected = []
    page = 1
    while len(collected) < limit:
        url = 'https://zenn.dev/api/topics?order=popular&page=%d' % page
        print('[DEBUG] topics fetch: %s' % url, flush=True)
        data, http = fetch_json(url)
        print('[DEBUG] http=%d' % http, flush=True)
        if http == 429:
            print('[WARN] 429 topics page=%d — sleep(%d)' % (page, SLEEP_429), flush=True)
            time.sleep(SLEEP_429)
            continue
        if not data or 'topics' not in data or len(data['topics']) == 0:
            print('[DEBUG] topics終端 page=%d' % page, flush=True)
            break
        for t in data['topics']:
            slug = t.get('slug', '') or t.get('name', '')
            if slug and slug not in collected:
                collected.append(slug)
        print('  topics page=%d 取得=%d件 累計=%d件' % (page, len(data['topics']), len(collected)), flush=True)
        if len(collected) >= limit:
            break
        page += 1
        time.sleep(1)
    topics = collected[:limit]
    print('=== 取得トピック(%d件): %s ===' % (len(topics), ', '.join(topics)), flush=True)
    return topics

# qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
# STEP1: トピック×ページ収集
# qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
def collect_zenn_users(topics):
    zenn_users = {}  # username -> article_count
    for topic in topics:
        print('=== トピック: %s ===' % topic, flush=True)
        for order in ('latest', 'trending'):
            for page in range(1, ZENN_PAGES + 1):
                url = 'https://zenn.dev/api/articles?topic_name=%s&order=%s&page=%d' % (urllib.parse.quote(topic), order, page)
                print('[DEBUG] fetch: %s' % url, flush=True)
                data, http = fetch_json(url)
                print('[DEBUG] http=%d' % http, flush=True)

                if http == 429:
                    print('[WARN] 429 topic=%s order=%s page=%d — sleep(%d) skip' % (topic, order, page, SLEEP_429), flush=True)
                    time.sleep(SLEEP_429)
                    continue
                if not data or 'articles' not in data:
                    print('[ERROR] JSON不正 topic=%s order=%s page=%d' % (topic, order, page), flush=True)
                    time.sleep(SLEEP_PAGE)
                    continue
                articles = data['articles']
                if len(articles) == 0:
                    print('[DEBUG] 記事なし topic=%s order=%s page=%d' % (topic, order, page), flush=True)
                    time.sleep(SLEEP_PAGE)
                    continue
                before = len(zenn_users)
                for article in articles:
                    user = article.get('user', {})
                    uname = user.get('username', '')
                    if uname:
                        zenn_users[uname] = zenn_users.get(uname, 0) + 1
                added = len(zenn_users) - before
                print('  order=%s page=%d 記事=%d件 新規+%d件 累計=%d件' % (order, page, len(articles), added, len(zenn_users)), flush=True)
                time.sleep(SLEEP_PAGE)
        time.sleep(SLEEP_TOPIC)

    print('Zennユーザー総数: %d件' % len(zenn_users), flush=True)
    return zenn_users

# qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
# STEP2: ユーザー詳細取得 → POST
# qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
def collect_user_details(zenn_users):
    usernames = list(zenn_users.keys())
    total     = len(usernames)
    print('ユーザー詳細 %d件 処理開始' % total, flush=True)

    success = 0
    skip    = 0

    for idx, zenn_username in enumerate(usernames, 1):
        user_url = 'https://zenn.dev/api/users/%s' % zenn_username
        print('[DEBUG] (%d/%d) user fetch: %s' % (idx, total, user_url), flush=True)
        data, http = fetch_json(user_url)
        print('[DEBUG] http=%d' % http, flush=True)

        if http == 429:
            print('[WARN] 429 zenn:%s — sleep(%d) skip' % (zenn_username, SLEEP_429), flush=True)
            time.sleep(SLEEP_429)
            skip += 1
            continue
        if http < 200 or http >= 300 or not data:
            print('[ERROR] user fetch失敗 http=%d zenn:%s' % (http, zenn_username), flush=True)
            time.sleep(SLEEP_USER)
            skip += 1
            continue
        if 'user' not in data:
            print('[SKIP] zenn:%s user key なし' % zenn_username, flush=True)
            time.sleep(SLEEP_USER)
            skip += 1
            continue

        zuser   = data['user']
        twitter = zuser.get('twitter_username', '')
        github  = zuser.get('github_username', '')

        if not twitter:
            print('[SKIP] zenn:%s Xアカウントなし' % zenn_username, flush=True)
            time.sleep(SLEEP_USER)
            skip += 1
            continue

        print('[DEBUG] @%s タグ取得中...' % twitter, flush=True)
        tags = zenn_get_user_tags(zenn_username)
        time.sleep(SLEEP_TAG)

        zenn_source = {
            'username':          zenn_username,
            'articles_count':    int(zuser.get('articles_count', 0)),
            'total_liked_count': int(zuser.get('total_liked_count', 0)),
            'follower_count':    int(zuser.get('follower_count', 0)),
            'github_username':   github,
            'tags':              tags,
            'fetched_at':        time.strftime('%Y-%m-%d'),
        }

        payload = {
            'twitter':     twitter,
            'zenn_source': zenn_source,
            'bio':         zuser.get('bio', ''),
            'name':        zuser.get('name', twitter),
            'tags':        tags,
        }

        print('[DEBUG] POST @%s → %s' % (twitter, SAVE_API_URL), flush=True)
        resp, rhttp = post_json(SAVE_API_URL, payload, SAVE_API_TOKEN)
        print('[DEBUG] POST http=%d resp=%s' % (rhttp, str(resp)[:200]), flush=True)

        if resp and resp.get('ok'):
            action = resp.get('action', 'saved')
            print('[OK] @%s action=%s' % (twitter, action), flush=True)
            success += 1
        else:
            reason = resp.get('reason', 'unknown') if resp else 'no_response'
            print('[ERROR] POST失敗 @%s reason=%s' % (twitter, reason), flush=True)
            skip += 1

        time.sleep(SLEEP_USER)

    return success, skip

# qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
# メイン
# qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
def main():
    print('=== zenn_collect.py 開始 ===', flush=True)
    topics           = fetch_popular_topics(ZENN_TOPICS_LIMIT)
    zenn_users       = collect_zenn_users(topics)
    success, skip    = collect_user_details(zenn_users)
    print('=== 完了: %d件保存 / %d件スキップ ===' % (success, skip), flush=True)

if __name__ == '__main__':
    main()
