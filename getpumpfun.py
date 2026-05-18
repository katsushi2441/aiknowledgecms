#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
pumpfun_x_collector.py
======================
pump.fun の公式 REST API からトークン一覧を取得し、
twitter フィールドに登録された X アカウントを大量収集する。

エンドポイント（認証不要・無料）:
  GET https://pump.fun/api/coins
    ?limit=50&offset=0
    &sort=last_trade_timestamp   # 最近取引されたもの順
    &order=DESC
    &includeNsfw=false

レスポンスに含まれる主なフィールド:
  mint          - トークンのコントラクトアドレス
  name          - トークン名
  symbol        - シンボル
  description   - 説明文
  twitter       - XアカウントURL (例: https://x.com/username)
  telegram      - Telegramリンク
  website       - ウェブサイト
  market_cap    - 時価総額(USD)
  created_timestamp - 作成日時(Unix ms)

出力: data/pumpfun_x_accounts.json
"""

import requests
import json
import time
import re
import sys
import os
import logging
from datetime import datetime

logging.basicConfig(
    level=logging.INFO,
    format="[%(levelname)s] %(message)s",
    handlers=[logging.StreamHandler(sys.stdout)]
)
log = logging.getLogger(__name__)

# ─── 設定 ────────────────────────────────────────────────────
API_BASE    = "https://frontend-api-v3.pump.fun/coins"
# フォールバック候補（順に試す）
API_CANDIDATES = [
    "https://frontend-api-v3.pump.fun/coins",
    "https://frontend-api-v2.pump.fun/coins",
    "https://frontend-api.pump.fun/coins",
]
OUTPUT_FILE = "data/pumpfun_x_accounts.json"

LIMIT       = 50       # 1リクエストあたりの取得件数（最大50）
MAX_PAGES   = 100      # 最大ページ数 → 最大5000件
SLEEP_SEC   = 1.5      # リクエスト間隔
SORT        = "last_trade_timestamp"  # last_trade_timestamp / created_timestamp / market_cap

HEADERS = {
    "User-Agent": (
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
        "AppleWebKit/537.36 Chrome/122.0.0.0 Safari/537.36"
    ),
    "Accept": "application/json",
    "Referer": "https://pump.fun/",
}


# ─── XアカウントURL → @username 正規化 ───────────────────────
def normalize_x_account(raw_url):
    """
    twitter/x フィールドの値から @username を抽出して返す。
    入力例:
      https://x.com/username
      https://twitter.com/username
      @username
      username
    """
    if not raw_url:
        return None
    s = raw_url.strip()

    # URL形式
    m = re.search(
        r"(?:x\.com|twitter\.com)/([A-Za-z0-9_]{1,15})(?:[/?#]|$)",
        s
    )
    if m:
        username = m.group(1)
        # 機能URL除外
        if username.lower() in ("intent", "share", "search", "home",
                                 "explore", "i", "settings", "login"):
            return None
        return "@" + username

    # @username 形式
    m2 = re.match(r"@([A-Za-z0-9_]{1,15})$", s)
    if m2:
        return "@" + m2.group(1)

    # 素のusername形式（4文字以上・英数字のみ）
    m3 = re.match(r"([A-Za-z0-9_]{4,15})$", s)
    if m3:
        return "@" + m3.group(1)

    return None


def detect_api_base():
    """動作するAPIエンドポイントを自動検出する"""
    test_params = {"limit": 1, "offset": 0, "sort": "last_trade_timestamp", "order": "DESC"}
    for url in API_CANDIDATES:
        try:
            r = requests.get(url, headers=HEADERS, params=test_params, timeout=10)
            log.info("[DEBUG] probe %s -> %d", url, r.status_code)
            if r.status_code == 200:
                data = r.json()
                # レスポンスがリストまたは辞書（offsetキー付き）なら有効
                if isinstance(data, list) or isinstance(data, dict):
                    log.info("[DEBUG] 使用エンドポイント: %s", url)
                    return url, "GET"
        except Exception as e:
            log.warning("[DEBUG] probe error %s: %s", url, e)

    # search エンドポイント（POST）を試す
    search_url = "https://frontend-api-v3.pump.fun/coins/search"
    try:
        body = {"limit": 1, "offset": 0, "sort": "market_cap", "order": "DESC",
                "includeNsfw": False, "complete": False}
        r = requests.post(search_url, headers={**HEADERS, "Content-Type": "application/json"},
                          json=body, timeout=10)
        log.info("[DEBUG] probe POST %s -> %d", search_url, r.status_code)
        if r.status_code == 200:
            log.info("[DEBUG] 使用エンドポイント(POST): %s", search_url)
            return search_url, "POST"
    except Exception as e:
        log.warning("[DEBUG] probe POST error: %s", e)

    return None, None


# ─── メイン収集 ──────────────────────────────────────────────
def collect(max_pages=MAX_PAGES, sort=SORT):
    # エンドポイント自動検出
    api_url, method = detect_api_base()
    if not api_url:
        log.error("[DEBUG] 有効なAPIエンドポイントが見つかりません")
        return [], {}

    all_tokens  = []
    x_accounts  = {}
    seen_mints  = set()
    total_fetched = 0

    for page in range(max_pages):
        offset = page * LIMIT

        try:
            if method == "POST":
                body = {"limit": LIMIT, "offset": offset, "sort": sort,
                        "order": "DESC", "includeNsfw": False, "complete": False}
                resp = requests.post(
                    api_url,
                    headers={**HEADERS, "Content-Type": "application/json"},
                    json=body, timeout=15
                )
            else:
                params = {"limit": LIMIT, "offset": offset, "sort": sort,
                          "order": "DESC", "includeNsfw": "false"}
                resp = requests.get(api_url, headers=HEADERS, params=params, timeout=15)

            log.info("[DEBUG] page=%d offset=%d status=%d", page, offset, resp.status_code)

            if resp.status_code == 429:
                wait = int(resp.headers.get("Retry-After", 15))
                log.warning("[DEBUG] Rate limited. %d秒待機...", wait)
                time.sleep(wait)
                continue

            if resp.status_code == 401:
                log.error("[DEBUG] 401 認証必要 - JWTトークンが必要な可能性があります")
                break

            if resp.status_code != 200:
                log.warning("[DEBUG] Non-200: %d, 停止", resp.status_code)
                break

            raw = resp.json()
            # レスポンスが辞書の場合（offset文字列キー）はvalues()で取得
            if isinstance(raw, dict):
                coins = list(raw.values())
            else:
                coins = raw

        except Exception as e:
            log.error("[DEBUG] Exception: %s", e)
            break

        if not coins:
            log.info("[DEBUG] レスポンスが空 -> 収集完了")
            break

        new_count = 0
        for coin in coins:
            if not isinstance(coin, dict):
                continue
            mint = coin.get("mint", "")
            if mint in seen_mints:
                continue
            seen_mints.add(mint)
            new_count += 1

            raw_twitter = coin.get("twitter") or ""
            # descriptionにTwitterリンクが埋め込まれている場合も抽出
            desc = coin.get("description") or ""
            if not raw_twitter:
                m = re.search(r"https?://(?:twitter\.com|x\.com)/([A-Za-z0-9_]{4,15})", desc)
                if m:
                    raw_twitter = m.group(0)

            x_handle = normalize_x_account(raw_twitter)

            token_entry = {
                "mint":        mint,
                "name":        coin.get("name", ""),
                "symbol":      coin.get("symbol", ""),
                "description": desc[:200],
                "twitter_raw": raw_twitter,
                "x_account":   x_handle,
                "telegram":    coin.get("telegram") or "",
                "website":     coin.get("website") or "",
                "market_cap":  coin.get("usd_market_cap") or coin.get("market_cap") or 0,
                "created_at":  coin.get("created_timestamp", ""),
                "url":         "https://pump.fun/coin/" + mint,
            }
            all_tokens.append(token_entry)

            if x_handle:
                key = x_handle.lower()
                if key not in x_accounts:
                    x_accounts[key] = {"x_account": x_handle, "coins": []}
                x_accounts[key]["coins"].append({
                    "mint":       mint,
                    "name":       coin.get("name", ""),
                    "symbol":     coin.get("symbol", ""),
                    "market_cap": token_entry["market_cap"],
                    "url":        token_entry["url"],
                })

        total_fetched += new_count
        x_count = len([t for t in all_tokens if t["x_account"]])
        log.info("[DEBUG] page=%d 新規=%d 累計=%d うちX登録=%d ユニークX=%d",
                 page, new_count, total_fetched, x_count, len(x_accounts))

        if new_count == 0:
            log.info("[DEBUG] 新規0件 -> 収集完了")
            break

        time.sleep(SLEEP_SEC)

    return all_tokens, x_accounts


# ─── 出力 ────────────────────────────────────────────────────
def save(all_tokens, x_accounts):
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

    # マージ（同一アカウントはコインリストを追記）
    merged_x = dict(existing_x)
    for key, val in x_accounts.items():
        if key in merged_x:
            existing_mints = set(c["mint"] for c in merged_x[key]["coins"])
            for coin in val["coins"]:
                if coin["mint"] not in existing_mints:
                    merged_x[key]["coins"].append(coin)
        else:
            merged_x[key] = val

    x_list = sorted(merged_x.values(), key=lambda v: v["x_account"].lower())

    output = {
        "generated_at":    datetime.now().isoformat(),
        "total_tokens":    len(all_tokens),
        "total_x_accounts": len(x_list),
        "x_rate":          "{:.1f}%".format(
            100 * len(x_accounts) / max(len(all_tokens), 1)
        ),
        "x_accounts":      x_list,
    }

    with open(OUTPUT_FILE, "w", encoding="utf-8") as f:
        json.dump(output, f, ensure_ascii=False, indent=2)

    log.info("=== 保存完了 ===")
    log.info("  トークン総数:       %d", len(all_tokens))
    log.info("  Xアカウント取得数:  %d", len(x_list))
    log.info("  X登録率:            %s", output["x_rate"])
    log.info("  出力先:             %s", OUTPUT_FILE)

    # サマリーをstdoutにも出力
    print(json.dumps({
        "total_tokens":     len(all_tokens),
        "total_x_accounts": len(x_list),
        "x_rate":           output["x_rate"],
    }, ensure_ascii=False, indent=2))


# ─── エントリポイント ─────────────────────────────────────────
def main():
    global SLEEP_SEC
    import argparse
    parser = argparse.ArgumentParser(description="pump.fun Xアカウント収集")
    parser.add_argument("--pages",  type=int, default=MAX_PAGES,
                        help="最大ページ数 (デフォルト: {})".format(MAX_PAGES))
    parser.add_argument("--sort",   type=str, default=SORT,
                        choices=["last_trade_timestamp", "created_timestamp", "market_cap"],
                        help="ソート順")
    parser.add_argument("--sleep",  type=float, default=SLEEP_SEC,
                        help="リクエスト間隔(秒)")
    args = parser.parse_args()
    SLEEP_SEC = args.sleep
    log.info("  最大ページ数: %d  最大取得件数: %d", args.pages, args.pages * LIMIT)
    log.info("  ソート: %s  間隔: %.1f秒", args.sort, SLEEP_SEC)

    all_tokens, x_accounts = collect(max_pages=args.pages, sort=args.sort)
    save(all_tokens, x_accounts)


if __name__ == "__main__":
    main()
