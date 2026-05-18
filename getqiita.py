#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
getqiita.py
===========
Qiita API v2 からユーザー一覧を取得し、
twitter_screen_name フィールドに登録された
X アカウントを大量収集する。

エンドポイント（認証なしでも利用可能）:
  GET https://qiita.com/api/v2/users
    ?page=1&per_page=100

レート制限:
  認証なし: 60リクエスト/時間/IP
  認証あり: 1000リクエスト/時間

最大取得件数:
  page は 1〜100 まで → 最大 10,000件

レスポンスの主なフィールド:
  id                  - Qiitaユーザーid (例: qiita)
  name                - 表示名
  twitter_screen_name - Xアカウント (例: qiita) ← これが目的
  github_login_name   - GitHubアカウント
  location            - 場所
  followers_count     - フォロワー数
  items_count         - 投稿記事数
  description         - 自己紹介

出力: data/qiita_x_accounts.json

使い方:
  # 認証なし（60req/h、最大6000件程度）
  python3 getqiita.py

  # 認証あり（1000req/h、最大10000件）
  python3 getqiita.py --token YOUR_QIITA_TOKEN

  # ページ数を指定
  python3 getqiita.py --pages 20

  # フォロワー数でフィルタ（影響力のあるユーザーのみ）
  python3 getqiita.py --min-followers 10
"""

import requests
import json
import time
import re
import sys
import os
import logging
import argparse
from datetime import datetime

logging.basicConfig(
    level=logging.INFO,
    format="[%(levelname)s] %(message)s",
    handlers=[logging.StreamHandler(sys.stdout)]
)
log = logging.getLogger(__name__)

# ─── 設定 ────────────────────────────────────────────────────
API_BASE    = "https://qiita.com/api/v2/users"
OUTPUT_FILE = "data/qiita_x_accounts.json"

PER_PAGE    = 100    # 1リクエストあたりの件数（最大100）
MAX_PAGES   = 100    # 最大ページ数（100ページ × 100件 = 10000件）
SLEEP_AUTH  = 3.6    # 認証あり: 1000req/h → 3.6秒間隔
SLEEP_NOAUTH = 62.0  # 認証なし: 60req/h → 62秒間隔（安全マージン込み）

HEADERS_BASE = {
    "User-Agent": "Mozilla/5.0 (compatible; QiitaXCollector/1.0)",
    "Accept": "application/json",
}


# ─── Xアカウント正規化 ────────────────────────────────────────
def normalize_x(screen_name):
    """twitter_screen_name フィールドの値を @username 形式に正規化"""
    if not screen_name:
        return None
    s = screen_name.strip()
    # @付きの場合はそのまま
    m = re.match(r"@?([A-Za-z0-9_]{1,15})$", s)
    if m:
        return "@" + m.group(1)
    # URL形式が入っている場合
    m2 = re.search(r"(?:twitter\.com|x\.com)/([A-Za-z0-9_]{1,15})", s)
    if m2:
        return "@" + m2.group(1)
    return None


# ─── レート制限ヘッダー解析 ──────────────────────────────────
def parse_rate_limit(headers):
    """レスポンスヘッダーからレート制限情報を取得"""
    remaining = headers.get("Rate-Limit-Remaining", "?")
    limit     = headers.get("Rate-Limit-Limit", "?")
    reset     = headers.get("Rate-Limit-Reset", "?")
    return remaining, limit, reset


# ─── メイン収集 ──────────────────────────────────────────────
def collect(token=None, max_pages=MAX_PAGES, min_followers=0):
    headers = dict(HEADERS_BASE)
    if token:
        headers["Authorization"] = "Bearer " + token
        sleep_sec = SLEEP_AUTH
        log.info("[DEBUG] 認証モード: 1000req/h, %.1f秒間隔", sleep_sec)
    else:
        sleep_sec = SLEEP_NOAUTH
        log.info("[DEBUG] 非認証モード: 60req/h, %.1f秒間隔", sleep_sec)
        log.info("[DEBUG] ヒント: --token を指定すると17倍速くなります")

    all_users   = []
    x_accounts  = {}    # screen_name_lower -> {詳細}
    seen_ids    = set()
    total_x     = 0

    for page in range(1, max_pages + 1):
        params = {"page": page, "per_page": PER_PAGE}
        try:
            resp = requests.get(API_BASE, headers=headers, params=params, timeout=15)
            remaining, limit, reset = parse_rate_limit(resp.headers)
            log.info("[DEBUG] page=%d status=%d remaining=%s/%s",
                     page, resp.status_code, remaining, limit)

            if resp.status_code == 429:
                log.warning("[DEBUG] レート制限到達。60秒待機...")
                time.sleep(60)
                continue

            if resp.status_code == 401:
                log.error("[DEBUG] 認証エラー。--token を確認してください")
                break

            if resp.status_code != 200:
                log.warning("[DEBUG] Non-200: %d 停止", resp.status_code)
                break

            users = resp.json()

        except Exception as e:
            log.error("[DEBUG] Exception: %s", e)
            break

        if not users:
            log.info("[DEBUG] レスポンスが空 -> 収集完了")
            break

        new_count = 0
        x_count_page = 0

        for u in users:
            uid = u.get("id", "")
            if uid in seen_ids:
                continue
            seen_ids.add(uid)
            new_count += 1

            followers = u.get("followers_count", 0) or 0
            if followers < min_followers:
                continue

            raw_twitter = u.get("twitter_screen_name") or ""
            x_handle = normalize_x(raw_twitter)

            user_entry = {
                "qiita_id":          uid,
                "name":              u.get("name") or "",
                "twitter_raw":       raw_twitter,
                "x_account":         x_handle,
                "github":            u.get("github_login_name") or "",
                "location":          u.get("location") or "",
                "followers_count":   followers,
                "items_count":       u.get("items_count", 0) or 0,
                "description":       (u.get("description") or "")[:100],
                "profile_url":       "https://qiita.com/" + uid,
            }
            all_users.append(user_entry)

            if x_handle:
                x_count_page += 1
                total_x += 1
                key = x_handle.lower()
                if key not in x_accounts:
                    x_accounts[key] = {
                        "x_account":       x_handle,
                        "qiita_id":        uid,
                        "name":            user_entry["name"],
                        "github":          user_entry["github"],
                        "location":        user_entry["location"],
                        "followers_count": followers,
                        "items_count":     user_entry["items_count"],
                        "profile_url":     user_entry["profile_url"],
                    }

        log.info("[DEBUG] page=%d 新規=%d Xあり=%d 累計=%d ユニークX=%d",
                 page, new_count, x_count_page, len(all_users), len(x_accounts))

        if new_count == 0:
            log.info("[DEBUG] 新規0件 -> 収集完了")
            break

        # ページ上限（通常 per_page 未満なら最終ページ）
        if len(users) < PER_PAGE:
            log.info("[DEBUG] 最終ページ到達（%d件 < %d）", len(users), PER_PAGE)
            break

        time.sleep(sleep_sec)

    return all_users, x_accounts


# ─── 出力 ────────────────────────────────────────────────────
def save(all_users, x_accounts):
    os.makedirs("data", exist_ok=True)

    # 既存データとマージ
    existing_x = {}
    if os.path.exists(OUTPUT_FILE):
        try:
            with open(OUTPUT_FILE, "r", encoding="utf-8") as f:
                prev = json.load(f)
            existing_x = {v["x_account"].lower(): v
                          for v in prev.get("x_accounts", [])}
            log.info("[DEBUG] 既存Xアカウント数: %d", len(existing_x))
        except Exception as e:
            log.warning("[DEBUG] 既存ファイル読み込みエラー: %s", e)

    # マージ（既存優先で上書き）
    merged = dict(existing_x)
    for key, val in x_accounts.items():
        merged[key] = val  # 最新データで上書き

    x_list = sorted(merged.values(), key=lambda v: -v.get("followers_count", 0))

    x_rate = 100.0 * len(x_accounts) / max(len(all_users), 1)
    output = {
        "generated_at":     datetime.now().isoformat(),
        "total_users":      len(all_users),
        "total_x_accounts": len(x_list),
        "x_rate":           "{:.1f}%".format(x_rate),
        "x_accounts":       x_list,
    }

    with open(OUTPUT_FILE, "w", encoding="utf-8") as f:
        json.dump(output, f, ensure_ascii=False, indent=2)

    log.info("=== 保存完了 ===")
    log.info("  ユーザー総数:      %d", len(all_users))
    log.info("  Xアカウント取得数: %d", len(x_list))
    log.info("  X登録率:           %.1f%%", x_rate)
    log.info("  出力先:            %s", OUTPUT_FILE)

    print(json.dumps({
        "total_users":      len(all_users),
        "total_x_accounts": len(x_list),
        "x_rate":           output["x_rate"],
        "top5": [
            {"x": v["x_account"], "qiita": v["qiita_id"],
             "followers": v["followers_count"]}
            for v in x_list[:5]
        ],
    }, ensure_ascii=False, indent=2))


# ─── エントリポイント ─────────────────────────────────────────
def main():
    parser = argparse.ArgumentParser(description="Qiita Xアカウント収集")
    parser.add_argument("--token",         type=str,   default="",
                        help="Qiita アクセストークン（省略可、ただし60req/hに制限）")
    parser.add_argument("--pages",         type=int,   default=MAX_PAGES,
                        help="最大ページ数 (デフォルト: {})".format(MAX_PAGES))
    parser.add_argument("--min-followers", type=int,   default=0,
                        help="最低フォロワー数フィルタ（デフォルト: 0=全件）")
    parser.add_argument("--sleep",         type=float, default=0,
                        help="リクエスト間隔を上書き（秒）")
    args = parser.parse_args()

    log.info("=== Qiita Xアカウント収集開始 ===")
    log.info("  最大ページ: %d  最大取得件数: %d", args.pages, args.pages * PER_PAGE)
    log.info("  認証: %s", "あり" if args.token else "なし")
    if args.min_followers:
        log.info("  フォロワーフィルタ: %d以上", args.min_followers)

    all_users, x_accounts = collect(
        token=args.token,
        max_pages=args.pages,
        min_followers=args.min_followers,
    )
    save(all_users, x_accounts)


if __name__ == "__main__":
    main()
