#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
scraper_test.py
===============
review_scraper.py が実際にデータを取れるか確認する診断スクリプト。
サーバー上で実行してください:
  python3 scraper_test.py 2>&1 | tee scraper_test.log
"""

import requests
from bs4 import BeautifulSoup
import re, time, sys

HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/122.0.0.0 Safari/537.36",
    "Accept-Language": "ja,en-US;q=0.9,en;q=0.8",
}
TIMEOUT  = 15
SLEEP    = 2.0
BRANDS   = ["未来舎", "電菱", "PowerTite"]

OK   = "  ✓"
NG   = "  ✗"
WARN = "  △"

def get(url, extra=None):
    h = dict(HEADERS)
    if extra: h.update(extra)
    try:
        r = requests.get(url, headers=h, timeout=TIMEOUT)
        return r.status_code, r.text
    except Exception as e:
        return 0, str(e)

def brand_hit(html):
    return [b for b in BRANDS if b.lower() in html.lower() or b in html]

def section(title):
    print("\n" + "="*60)
    print(f"  {title}")
    print("="*60)

# ──────────────────────────────────────────────────────────────
# 1. みんカラ
# ──────────────────────────────────────────────────────────────
section("1. みんカラ パーツレビュー一覧")
url = "https://minkara.carview.co.jp/partsreview/review.aspx?bi=19&ci=842&pn=1"
status, html = get(url)
print(f"  URL   : {url}")
print(f"  Status: {status}  len={len(html)}")

if status == 200:
    soup = BeautifulSoup(html, "html.parser")
    selectors = [
        "div.partsReviewItem",
        "li.reviewListItem",
        ".p-review_item",
        ".reviewItem",
        "table.reviewList tr",
        "div[class*='review']",
        "li[class*='review']",
    ]
    print("  --- セレクター調査 ---")
    best = None
    for sel in selectors:
        cards = soup.select(sel)
        hit = "★" if cards else " "
        print(f"  {hit} {sel!r:45s} -> {len(cards)} 件")
        if cards and best is None:
            best = (sel, cards)

    hits = brand_hit(html)
    print(f"\n  ブランド検出: {hits if hits else '(なし)'}")

    if best:
        sel, cards = best
        print(f"\n  最有力セレクター: {sel!r}  ({len(cards)}件)")
        print(f"  先頭カード HTML (500文字):")
        print("  " + str(cards[0])[:500].replace("\n", "\n  "))
    else:
        print(f"\n{NG} カードが見つかりません。HTMLスニペット (2000-3000文字):")
        print("  " + html[2000:3000].replace("\n", "\n  "))
else:
    print(f"{NG} 取得失敗")

time.sleep(SLEEP)

# ──────────────────────────────────────────────────────────────
# 2. みんカラ サイト内検索
# ──────────────────────────────────────────────────────────────
section("2. みんカラ サイト内検索")
from urllib.parse import quote
query = "未来舎 インバーター"
url = f"https://minkara.carview.co.jp/search/?q={quote(query)}"
status, html = get(url)
print(f"  URL   : {url}")
print(f"  Status: {status}  len={len(html)}")

if status == 200:
    soup = BeautifulSoup(html, "html.parser")
    # ブログ/整備手帳リンクを探す
    pattern = re.compile(r'/userid/\d+/(blog/\d+|car/\d+/\d+/(note|parts)\.aspx)')
    found_links = []
    for a in soup.select("a[href]"):
        href = a.get("href","")
        if pattern.search(href):
            found_links.append(href)
    found_links = list(dict.fromkeys(found_links))
    print(f"  記事リンク数: {len(found_links)}")
    for l in found_links[:5]:
        print(f"    {l}")

    if not found_links:
        print(f"{NG} リンクが見つかりません。HTML (1500-2500):")
        print("  " + html[1500:2500].replace("\n","\n  "))
else:
    print(f"{NG} 取得失敗")

time.sleep(SLEEP)

# ──────────────────────────────────────────────────────────────
# 3. みんカラ 個別パーツレビューページ
# ──────────────────────────────────────────────────────────────
section("3. みんカラ 個別パーツレビュー")
# 先ほどのブログ記事（未来舎/電菱言及が確認済み）
url = "https://minkara.carview.co.jp/userid/894030/blog/40876063/"
status, html = get(url)
print(f"  URL   : {url}")
print(f"  Status: {status}  len={len(html)}")

if status == 200:
    soup = BeautifulSoup(html, "html.parser")
    selectors = [
        "h1.blogTitle", "h2.blogTitle", "h1", "h2",
        ".blogBody", ".noteBody", ".blogText", "div.blogInner", "article",
        "time[datetime]", ".blogDate",
    ]
    print("  --- セレクター調査 ---")
    for sel in selectors:
        el = soup.select_one(sel)
        val = el.get_text()[:60].replace("\n"," ").strip() if el else ""
        hit = "★" if el else " "
        print(f"  {hit} {sel!r:35s} -> {val!r}")
    hits = brand_hit(html)
    print(f"\n  ブランド検出: {hits}")
else:
    print(f"{NG} 取得失敗")

time.sleep(SLEEP)

# ──────────────────────────────────────────────────────────────
# 4. 価格.com 検索
# ──────────────────────────────────────────────────────────────
section("4. 価格.com 商品検索")
url = f"https://kakaku.com/search_results/{quote('未来舎 インバーター')}/"
status, html = get(url)
print(f"  URL   : {url}")
print(f"  Status: {status}  len={len(html)}")

if status == 200:
    soup = BeautifulSoup(html, "html.parser")
    selectors = ["p.itmNm a", ".p-item_name a", "a[href*='/item/']", "h3 a"]
    print("  --- 商品リンク セレクター ---")
    for sel in selectors:
        items = soup.select(sel)
        hit = "★" if items else " "
        sample = items[0].get("href","")[:80] if items else ""
        print(f"  {hit} {sel!r:30s} -> {len(items)}件  {sample}")
else:
    print(f"{NG} 取得失敗 (status={status})")

time.sleep(SLEEP)

# ──────────────────────────────────────────────────────────────
# 5. Amazon パーツレビュー
# ──────────────────────────────────────────────────────────────
section("5. Amazon レビューページ")
asin = "B006DUZLDC"
url  = f"https://www.amazon.co.jp/product-reviews/{asin}/?pageNumber=1"
status, html = get(url)
print(f"  URL   : {url}")
print(f"  Status: {status}  len={len(html)}")

if status == 200:
    soup = BeautifulSoup(html, "html.parser")
    reviews = soup.select("[data-hook='review']")
    print(f"  レビュー件数: {len(reviews)}")
    if reviews:
        r = reviews[0]
        rating_el = r.select_one("[data-hook='review-star-rating'] span.a-icon-alt")
        body_el   = r.select_one("[data-hook='review-body'] span")
        print(f"  評価: {rating_el.get_text()[:30] if rating_el else '(なし)'}")
        print(f"  本文: {body_el.get_text()[:80] if body_el else '(なし)'}...")
    else:
        # ブロックされているかチェック
        if "robot" in html.lower() or "captcha" in html.lower():
            print(f"{WARN} CAPTCHA/ボット検知の可能性")
        print(f"{NG} レビューカードが取得できません")
elif status == 503:
    print(f"{WARN} 503 - Amazonにブロックされています（想定内）")
else:
    print(f"{NG} 取得失敗")

time.sleep(SLEEP)

# ──────────────────────────────────────────────────────────────
# 6. DuckDuckGo ブログ検索
# ──────────────────────────────────────────────────────────────
section("6. DuckDuckGo ブログ検索")
url = f"https://html.duckduckgo.com/html/?q={quote('未来舎 インバーター レビュー 使用感')}"
status, html = get(url, {"Accept": "text/html"})
print(f"  URL   : {url}")
print(f"  Status: {status}  len={len(html)}")

if status == 200:
    soup = BeautifulSoup(html, "html.parser")
    links = []
    for a in soup.select("a.result__url, .result__a"):
        href = a.get("href","")
        if "uddg=" in href:
            m = re.search(r"uddg=([^&]+)", href)
            if m:
                from urllib.parse import unquote
                href = unquote(m.group(1))
        if href.startswith("http"):
            links.append(href)
    links = list(dict.fromkeys(links))[:8]
    print(f"  検索結果URL数: {len(links)}")
    for l in links:
        print(f"    {l}")
else:
    print(f"{NG} 取得失敗")

time.sleep(SLEEP)

# ──────────────────────────────────────────────────────────────
# 7. 楽天 商品検索
# ──────────────────────────────────────────────────────────────
section("7. 楽天 商品検索")
url = f"https://search.rakuten.co.jp/search/mall/{quote('未来舎 インバーター')}/"
status, html = get(url)
print(f"  URL   : {url}")
print(f"  Status: {status}  len={len(html)}")

if status == 200:
    soup = BeautifulSoup(html, "html.parser")
    selectors = ["a.title--3Dzgx", "a.item-name", ".searchresultitems a", "a[href*='item.rakuten.co.jp']"]
    print("  --- 商品リンク セレクター ---")
    for sel in selectors:
        items = soup.select(sel)
        hit = "★" if items else " "
        sample = items[0].get("href","")[:80] if items else ""
        print(f"  {hit} {sel!r:40s} -> {len(items)}件  {sample}")
else:
    print(f"{NG} 取得失敗")

# ──────────────────────────────────────────────────────────────
# まとめ
# ──────────────────────────────────────────────────────────────
section("テスト完了")
print("  scraper_test.log を確認して、")
print("  ★ のセレクターを review_scraper.py に反映してください。")
print()
