#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
zenn_x_accounts.py - ZennからXアカウント一覧と詳細を取得して表示
使用方法: python3 zenn_x_accounts.py [topicname] [pages]
例:       python3 zenn_x_accounts.py ai 3
          python3 zenn_x_accounts.py llm 5
topicname省略時: ai
pages省略時: 3
"""

import sys
import json
import time
import re

try:
    import requests
except ImportError:
    print("[ERROR] pip install requests")
    sys.exit(1)

HEADERS = {
    "User-Agent": "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36",
    "Accept": "application/json",
}
TIMEOUT = 15

# ================================================================
# Zenn API
# ================================================================
def fetch_json(url, label=""):
    print("[DEBUG] GET {}".format(url))
    try:
        r = requests.get(url, headers=HEADERS, timeout=TIMEOUT)
        print("[DEBUG] {} status={}".format(label, r.status_code))
        if r.status_code != 200:
            print("[WARN]  {}".format(r.text[:200]))
            return None
        return r.json()
    except Exception as e:
        print("[ERROR] {}: {}".format(label, e))
        return None

def get_articles_by_topic(topicname, page=1):
    """トピックの記事一覧 → ユーザー名リスト"""
    url = "https://zenn.dev/api/articles?topicname={}&order=latest&page={}".format(topicname, page)
    return fetch_json(url, "articles_topic")

def get_user_detail(zenn_username):
    """Zennユーザー詳細（twitter_username含む）"""
    url = "https://zenn.dev/api/users/{}".format(zenn_username)
    return fetch_json(url, "user_detail")

def get_user_articles(zenn_username):
    """ユーザーの記事一覧"""
    url = "https://zenn.dev/api/articles?username={}&order=latest".format(zenn_username)
    return fetch_json(url, "user_articles")

# ================================================================
# メイン収集
# ================================================================
def collect(topicname, max_pages):
    print("")
    print("=" * 60)
    print("  STEP1: トピック '{}' から記事収集 ({}ページ)".format(topicname, max_pages))
    print("=" * 60)

    # Zennユーザー名を収集
    zenn_users = {}  # zenn_username -> article_count
    for page in range(1, max_pages + 1):
        data = get_articles_by_topic(topicname, page)
        if not data or not data.get("articles"):
            print("[INFO] page={} 記事なし、終了".format(page))
            break
        for article in data["articles"]:
            user = article.get("user", {})
            uname = user.get("username", "")
            if uname:
                zenn_users[uname] = zenn_users.get(uname, 0) + 1
        print("[INFO] page={} 記事数={} 累計ユーザー={}".format(
            page, len(data["articles"]), len(zenn_users)))
        time.sleep(0.5)

    print("\n[INFO] Zennユーザー総数: {}".format(len(zenn_users)))

    print("")
    print("=" * 60)
    print("  STEP2: 各ユーザーの詳細取得 (twitter_username含む)")
    print("=" * 60)

    accounts = []
    for zenn_username, article_count in zenn_users.items():
        detail = get_user_detail(zenn_username)
        if not detail or "user" not in detail:
            print("[SKIP] {}".format(zenn_username))
            time.sleep(0.3)
            continue

        u = detail["user"]
        twitter = u.get("twitter_username", "") or ""
        github  = u.get("github_username", "") or ""

        # 記事のタグを取得
        tags = []
        art_data = get_user_articles(zenn_username)
        if art_data and art_data.get("articles"):
            tag_count = {}
            for a in art_data["articles"]:
                for t in (a.get("topics") or []):
                    tag_count[t] = tag_count.get(t, 0) + 1
            tags = sorted(tag_count, key=lambda x: -tag_count[x])[:5]

        account = {
            "zenn_username":    zenn_username,
            "twitter_username": twitter,
            "github_username":  github,
            "name":             u.get("name", ""),
            "bio":              u.get("bio", "") or "",
            "follower_count":   u.get("follower_count", 0),
            "articles_count":   u.get("articles_count", 0),
            "total_liked_count":u.get("total_liked_count", 0),
            "tags":             tags,
            "zenn_article_count_in_topic": article_count,
        }
        accounts.append(account)

        x_str = "@{}".format(twitter) if twitter else "(Xなし)"
        print("[OK] zenn:{:<20} X:{:<20} 記事:{} いいね:{}".format(
            zenn_username, x_str,
            u.get("articles_count", 0), u.get("total_liked_count", 0)))

        time.sleep(0.5)

    return accounts

# ================================================================
# 表示
# ================================================================
def display(accounts, topicname):
    # Xアカウントありのみ抽出
    with_x    = [a for a in accounts if a["twitter_username"]]
    without_x = [a for a in accounts if not a["twitter_username"]]

    print("")
    print("=" * 60)
    print("  結果サマリー: topic={}".format(topicname))
    print("=" * 60)
    print("  総ユーザー数   : {}".format(len(accounts)))
    print("  Xアカウントあり: {}".format(len(with_x)))
    print("  Xアカウントなし: {}".format(len(without_x)))

    # いいね数でソート
    with_x.sort(key=lambda a: -a["total_liked_count"])

    print("")
    print("=" * 60)
    print("  Xアカウント一覧 (いいね数順)")
    print("=" * 60)
    for a in with_x:
        print("")
        print("  @{:<25} (Zenn: {})".format(a["twitter_username"], a["zenn_username"]))
        print("  名前    : {}".format(a["name"]))
        print("  bio     : {}".format(a["bio"][:80]))
        print("  記事数  : {}  いいね合計: {}  Zennフォロワー: {}".format(
            a["articles_count"], a["total_liked_count"], a["follower_count"]))
        print("  タグ    : {}".format(", ".join(a["tags"]) if a["tags"] else "-"))
        if a["github_username"]:
            print("  GitHub  : https://github.com/{}".format(a["github_username"]))

    return with_x

# ================================================================
# メイン
# ================================================================
def main():
    topicname = sys.argv[1] if len(sys.argv) > 1 else "ai"
    max_pages = int(sys.argv[2]) if len(sys.argv) > 2 else 3

    accounts = collect(topicname, max_pages)
    with_x   = display(accounts, topicname)

    # JSON出力
    out = {
        "topicname": topicname,
        "total":     len(accounts),
        "with_x":    len(with_x),
        "accounts":  with_x,
    }
    fname = "zenn_accounts_{}.json".format(topicname)
    with open(fname, "w", encoding="utf-8") as f:
        json.dump(out, f, ensure_ascii=False, indent=2)
    print("")
    print("[OK] {} に保存 (Xアカウントあり: {}件)".format(fname, len(with_x)))

if __name__ == "__main__":
    main()
