#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
未来舎 (PowerTite) / 電菱 商品レビュー収集スクリプト
対象: Amazon.co.jp, 価格.com, 楽天, 一般ブログ
出力: JSON
"""

import requests
from bs4 import BeautifulSoup
import json
import time
import re
import sys
import logging
from datetime import datetime
from urllib.parse import urljoin, urlparse, quote

# ─── ロギング設定 ───────────────────────────────────────────
logging.basicConfig(
    level=logging.INFO,
    format="[%(levelname)s] %(message)s",
    handlers=[logging.StreamHandler(sys.stdout)]
)
log = logging.getLogger(__name__)

# ─── 共通設定 ────────────────────────────────────────────────
HEADERS = {
    "User-Agent": (
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
        "AppleWebKit/537.36 (KHTML, like Gecko) "
        "Chrome/122.0.0.0 Safari/537.36"
    ),
    "Accept-Language": "ja,en-US;q=0.9,en;q=0.8",
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
}
SLEEP_SEC = 2.0   # リクエスト間隔（秒）
TIMEOUT   = 15

# ─── 検索キーワード定義 ───────────────────────────────────────
BRANDS = ["未来舎", "PowerTite", "電菱", "Denryo"]
CATEGORIES = ["インバーター", "コンバーター", "充電器", "ソーラーコントローラー"]

# Amazon ASINリスト（既知の未来舎・電菱商品）
AMAZON_ASINS = [
    "B006DUZLDC",  # 未来舎 FI-S353A インバーター
    "B00DIXMNLU",  # 未来舎 FI-S603 インバーター
    # 必要に応じて追加
]

# ─── ユーティリティ ───────────────────────────────────────────
def get_html(url, extra_headers=None):
    """GETリクエストを送りHTMLを返す。失敗時はNone"""
    hdrs = dict(HEADERS)
    if extra_headers:
        hdrs.update(extra_headers)
    try:
        resp = requests.get(url, headers=hdrs, timeout=TIMEOUT)
        log.info("[DEBUG] GET %s -> status=%d", url, resp.status_code)
        if resp.status_code == 200:
            resp.encoding = resp.apparent_encoding or "utf-8"
            return resp.text
        else:
            log.warning("[DEBUG] Non-200: %d for %s", resp.status_code, url)
            return None
    except Exception as e:
        log.error("[DEBUG] Exception fetching %s: %s", url, e)
        return None


def clean_text(text):
    """余分な空白・改行を除去"""
    if not text:
        return ""
    return re.sub(r"\s+", " ", text).strip()


# ─── Amazon.co.jp スクレイパー ──────────────────────────────
class AmazonScraper:
    """
    Amazon商品レビューページを取得する。
    ※ Amazonは利用規約によりスクレイピングを禁じています。
      PA-API（Product Advertising API）の利用を強く推奨します。
      このコードは学習・調査目的のサンプルです。
    """
    BASE_REVIEW_URL = "https://www.amazon.co.jp/product-reviews/{asin}/?pageNumber={page}"

    def fetch_reviews(self, asin, max_pages=3):
        reviews = []
        for page in range(1, max_pages + 1):
            url = self.BASE_REVIEW_URL.format(asin=asin, page=page)
            html = get_html(url)
            if not html:
                break
            parsed = self._parse_page(html, asin)
            if not parsed:
                log.info("[DEBUG] Amazon: no reviews on page %d for ASIN=%s", page, asin)
                break
            reviews.extend(parsed)
            log.info("[DEBUG] Amazon ASIN=%s page=%d -> %d reviews", asin, page, len(parsed))
            time.sleep(SLEEP_SEC)
        return reviews

    def _parse_page(self, html, asin):
        soup = BeautifulSoup(html, "html.parser")
        items = soup.select("[data-hook='review']")
        results = []
        for item in items:
            # 評価
            rating_el = item.select_one("[data-hook='review-star-rating'] span.a-icon-alt")
            rating_raw = rating_el.get_text(strip=True) if rating_el else ""
            rating_match = re.search(r"([\d.]+)", rating_raw)
            rating = float(rating_match.group(1)) if rating_match else None

            # タイトル
            title_el = item.select_one("[data-hook='review-title'] span:last-child")
            title = clean_text(title_el.get_text()) if title_el else ""

            # 本文
            body_el = item.select_one("[data-hook='review-body'] span")
            body = clean_text(body_el.get_text()) if body_el else ""

            # 日付
            date_el = item.select_one("[data-hook='review-date']")
            date_str = clean_text(date_el.get_text()) if date_el else ""

            # 商品名（レビューページ上部から）
            product_el = item.select_one("a[data-hook='product-link']")
            product_name = clean_text(product_el.get_text()) if product_el else ""

            if body:
                results.append({
                    "source": "amazon",
                    "asin": asin,
                    "product_name": product_name,
                    "rating": rating,
                    "title": title,
                    "body": body,
                    "date": date_str,
                    "url": f"https://www.amazon.co.jp/dp/{asin}",
                })
        return results


# ─── 価格.com スクレイパー ──────────────────────────────────
class KakakuScraper:
    SEARCH_URL = "https://kakaku.com/search_results/{keyword}/?category=0007_0089"
    # category=0007_0089 は「インバーター・コンバーター」カテゴリ

    def search_products(self, keyword):
        """キーワードで商品を検索しURLリストを返す"""
        url = f"https://kakaku.com/search_results/{quote(keyword)}/"
        html = get_html(url)
        if not html:
            return []
        soup = BeautifulSoup(html, "html.parser")
        product_links = []
        for a in soup.select("p.itmNm a, .p-item_name a"):
            href = a.get("href", "")
            if href and "/item/" in href:
                full = urljoin("https://kakaku.com", href)
                product_links.append(full)
        log.info("[DEBUG] Kakaku search '%s' -> %d products", keyword, len(product_links))
        return list(set(product_links))[:5]  # 最大5件

    def fetch_reviews(self, product_url, max_pages=2):
        """商品URLからクチコミを取得"""
        # 価格.comのクチコミURLパターン: /item/XXXXX/review/
        review_base = re.sub(r"/$", "", product_url) + "/review/"
        reviews = []
        for page in range(1, max_pages + 1):
            url = review_base if page == 1 else f"{review_base}?page={page}"
            html = get_html(url)
            if not html:
                break
            parsed = self._parse_page(html, product_url)
            if not parsed:
                break
            reviews.extend(parsed)
            log.info("[DEBUG] Kakaku %s page=%d -> %d reviews", review_base, page, len(parsed))
            time.sleep(SLEEP_SEC)
        return reviews

    def _parse_page(self, html, source_url):
        soup = BeautifulSoup(html, "html.parser")
        results = []

        # クチコミブロック
        for item in soup.select(".reviewListItem, .p-review_item, li.reviewItem"):
            # 評価
            rating = None
            rating_el = item.select_one(".p-review_star, .rvwStar, [class*='star']")
            if rating_el:
                m = re.search(r"([\d.]+)", rating_el.get("class", [""])[0] + rating_el.get_text())
                if m:
                    rating = float(m.group(1))

            # タイトル
            title_el = item.select_one(".p-review_title, .rvwTtl, h3")
            title = clean_text(title_el.get_text()) if title_el else ""

            # 本文
            body_el = item.select_one(".p-review_text, .rvwTxt, .reviewBody")
            body = clean_text(body_el.get_text()) if body_el else ""

            # 日付
            date_el = item.select_one(".p-review_date, .rvwDate, time")
            date_str = clean_text(date_el.get_text()) if date_el else ""

            if body:
                results.append({
                    "source": "kakaku",
                    "product_name": "",
                    "rating": rating,
                    "title": title,
                    "body": body,
                    "date": date_str,
                    "url": source_url,
                })
        return results

    def run(self, brands, categories):
        all_reviews = []
        for brand in brands:
            for cat in categories:
                keyword = f"{brand} {cat}"
                time.sleep(SLEEP_SEC)
                product_urls = self.search_products(keyword)
                for purl in product_urls:
                    time.sleep(SLEEP_SEC)
                    reviews = self.fetch_reviews(purl)
                    all_reviews.extend(reviews)
        return all_reviews


# ─── 楽天 スクレイパー ────────────────────────────────────────
class RakutenScraper:
    """楽天市場の商品レビューを収集する"""
    SEARCH_URL = "https://search.rakuten.co.jp/search/mall/{keyword}/"

    def search_products(self, keyword):
        url = self.SEARCH_URL.format(keyword=quote(keyword))
        html = get_html(url)
        if not html:
            return []
        soup = BeautifulSoup(html, "html.parser")
        product_links = []
        # テスト結果: a[href*='item.rakuten.co.jp'] で90件取得確認済み
        for a in soup.select("a[href*='item.rakuten.co.jp']"):
            href = a.get("href", "")
            if href and "item.rakuten.co.jp" in href:
                product_links.append(href)
        log.info("[DEBUG] Rakuten search '%s' -> %d products", keyword, len(product_links))
        return list(set(product_links))[:5]

    def fetch_reviews(self, item_url, max_pages=2):
        """楽天の商品レビューページを取得"""
        # 楽天レビューURL: item.rakuten.co.jp/shop/itemID/ -> review.rakuten.co.jp/item/...
        # まず商品ページからshop_code, item_idを抽出
        html = get_html(item_url)
        if not html:
            return []

        # 楽天レビューリンクを探す
        soup = BeautifulSoup(html, "html.parser")
        review_link = None
        for a in soup.select("a[href*='review.rakuten.co.jp']"):
            review_link = a.get("href")
            break

        if not review_link:
            # URLパターンから構築を試みる
            m = re.search(r"item\.rakuten\.co\.jp/([^/]+)/([^/?]+)", item_url)
            if m:
                shop, item = m.group(1), m.group(2)
                review_link = f"https://review.rakuten.co.jp/item/1/{shop}_{item}/1.1/"

        if not review_link:
            log.warning("[DEBUG] Rakuten: could not find review URL for %s", item_url)
            return []

        reviews = []
        for page in range(1, max_pages + 1):
            paged_url = review_link if page == 1 else re.sub(r"/\d+\.1/$", f"/{page}.1/", review_link)
            rhtml = get_html(paged_url)
            if not rhtml:
                break
            parsed = self._parse_page(rhtml, item_url)
            if not parsed:
                break
            reviews.extend(parsed)
            log.info("[DEBUG] Rakuten review page=%d -> %d reviews", page, len(parsed))
            time.sleep(SLEEP_SEC)
        return reviews

    def _parse_page(self, html, source_url):
        soup = BeautifulSoup(html, "html.parser")
        results = []

        for item in soup.select(".ratReviewItem, .review-item, li[class*='review']"):
            # 評価
            rating = None
            stars_el = item.select_one(".ratStarRating, [class*='star']")
            if stars_el:
                m = re.search(r"(\d+(?:\.\d+)?)", stars_el.get("class", [""])[0] + stars_el.get_text())
                if m:
                    rating = float(m.group(1))

            # タイトル
            title_el = item.select_one(".ratReviewTitle, .review-title, h3")
            title = clean_text(title_el.get_text()) if title_el else ""

            # 本文
            body_el = item.select_one(".ratReviewBody, .review-body, .reviewText")
            body = clean_text(body_el.get_text()) if body_el else ""

            # 日付
            date_el = item.select_one(".ratDate, time, .review-date")
            date_str = clean_text(date_el.get_text()) if date_el else ""

            if body:
                results.append({
                    "source": "rakuten",
                    "product_name": "",
                    "rating": rating,
                    "title": title,
                    "body": body,
                    "date": date_str,
                    "url": source_url,
                })
        return results

    def run(self, brands, categories):
        all_reviews = []
        for brand in brands:
            for cat in categories:
                keyword = f"{brand} {cat}"
                time.sleep(SLEEP_SEC)
                product_urls = self.search_products(keyword)
                for purl in product_urls:
                    time.sleep(SLEEP_SEC)
                    reviews = self.fetch_reviews(purl)
                    all_reviews.extend(reviews)
        return all_reviews


# ─── ブログ・一般サイト スクレイパー ────────────────────────────
class BlogScraper:
    """
    Google/DuckDuckGo検索経由でブログ記事URLを収集し、
    本文テキストをレビューとして抽出する。
    """
    # DuckDuckGo HTML検索（Googleよりレート制限が緩い）
    DDG_URL = "https://html.duckduckgo.com/html/?q={query}"

    # 除外ドメイン（ECサイト・メーカー公式など）
    EXCLUDE_DOMAINS = {
        "amazon.co.jp", "kakaku.com", "rakuten.co.jp", "item.rakuten.co.jp",
        "powertite.co.jp", "denryo.com", "yahoo.co.jp", "mercari.com",
    }

    def search_urls(self, query, max_results=5):
        url = self.DDG_URL.format(query=quote(query))
        html = get_html(url, extra_headers={"Accept": "text/html"})
        if not html:
            return []
        soup = BeautifulSoup(html, "html.parser")
        urls = []
        for a in soup.select("a.result__url, .result__a"):
            href = a.get("href", "")
            # DuckDuckGoのリダイレクトURLを解析
            if "uddg=" in href:
                m = re.search(r"uddg=([^&]+)", href)
                if m:
                    from urllib.parse import unquote
                    href = unquote(m.group(1))
            parsed = urlparse(href)
            domain = parsed.netloc.lstrip("www.")
            if domain and domain not in self.EXCLUDE_DOMAINS and href.startswith("http"):
                urls.append(href)
        log.info("[DEBUG] Blog search '%s' -> %d URLs", query, len(urls))
        return list(dict.fromkeys(urls))[:max_results]  # 重複除去して最大件数

    def fetch_article(self, url):
        """記事URLから本文テキストを抽出"""
        html = get_html(url)
        if not html:
            return None
        soup = BeautifulSoup(html, "html.parser")

        # タイトル
        title_el = soup.select_one("h1, title")
        title = clean_text(title_el.get_text()) if title_el else ""

        # 本文候補セレクター（ブログ共通パターン）
        body_text = ""
        for sel in ["article", ".entry-content", ".post-content", ".article-body",
                    "#content", "main", ".post-body"]:
            el = soup.select_one(sel)
            if el:
                # script/style/nav除去
                for tag in el(["script", "style", "nav", "aside", "footer"]):
                    tag.decompose()
                body_text = clean_text(el.get_text(separator=" "))
                break

        if not body_text:
            # フォールバック: body全体
            for tag in soup(["script", "style", "nav", "header", "footer"]):
                tag.decompose()
            body_text = clean_text(soup.get_text(separator=" "))

        # 最低300文字以上、かつブランド名を含む記事のみ採用
        has_brand = any(b.lower() in body_text.lower() for b in
                        ["未来舎", "powertite", "電菱", "denryo"])
        if len(body_text) < 300 or not has_brand:
            log.info("[DEBUG] Blog: skipping %s (len=%d, brand=%s)", url, len(body_text), has_brand)
            return None

        # 日付（metaタグから）
        date_str = ""
        for sel in ["meta[property='article:published_time']",
                    "meta[name='pubdate']", "time[datetime]"]:
            el = soup.select_one(sel)
            if el:
                date_str = el.get("content") or el.get("datetime") or el.get_text()
                date_str = clean_text(date_str)
                break

        return {
            "source": "blog",
            "product_name": "",
            "rating": None,
            "title": title,
            "body": body_text[:2000],  # 最大2000文字
            "date": date_str,
            "url": url,
        }

    def run(self, brands, categories):
        all_results = []
        for brand in brands[:2]:  # ブランドごと最大2件検索
            for cat in categories[:2]:
                query = f"{brand} {cat} レビュー 使用感"
                time.sleep(SLEEP_SEC)
                urls = self.search_urls(query)
                for url in urls:
                    time.sleep(SLEEP_SEC)
                    article = self.fetch_article(url)
                    if article:
                        all_results.append(article)
                        log.info("[DEBUG] Blog: collected %s", url)
        return all_results


# ─── アメブロ・note XアカウントBlogスクレイパー ─────────────────
class XAccountBlogScraper:
    """
    アメブロ・note に絞ったDuckDuckGo検索で記事URLを収集し、
    著者プロフィールページから X アカウントを取得する。

    フロー:
      DuckDuckGo (site:ameblo.jp / site:note.com) で記事URL取得
        → 記事ページで著者プロフィールURLを抽出
        → プロフィールページでXアカウントURL/IDを抽出
        → レビュー本文 + x_accounts をセットで返す
    """

    DDG_URL = "https://html.duckduckgo.com/html/?q={query}"
    BASE_AMEBLO = "https://ameblo.jp"
    BASE_NOTE   = "https://note.com"

    # site: 検索対象
    TARGETS = ["site:ameblo.jp", "site:note.com"]

    def _ddg_search(self, query, max_results=6):
        """DuckDuckGo HTML検索でURLリストを返す"""
        url = self.DDG_URL.format(query=quote(query))
        html = get_html(url, extra_headers={"Accept": "text/html"})
        if not html:
            return []
        soup = BeautifulSoup(html, "html.parser")
        urls = []
        for a in soup.select("a.result__url, .result__a"):
            href = a.get("href", "")
            if "uddg=" in href:
                m = re.search(r"uddg=([^&]+)", href)
                if m:
                    from urllib.parse import unquote
                    href = unquote(m.group(1))
            if href.startswith("http"):
                urls.append(href)
        return list(dict.fromkeys(urls))[:max_results]

    # ── アメブロ ─────────────────────────────────────────────────
    def _ameblo_profile_url(self, article_url):
        """
        記事URL https://ameblo.jp/{user}/entry-XXXX.html
        → プロフィール https://ameblo.jp/{user}/
        """
        m = re.match(r"(https://ameblo\.jp/[^/]+)/", article_url)
        return m.group(1) + "/" if m else None

    def _ameblo_get_x(self, profile_url):
        """
        アメブロプロフィールページから X アカウントを取得。
        記事下・プロフィールページに twitter.com/x.com リンクが出る。
        """
        html = get_html(profile_url)
        if not html:
            return []
        soup = BeautifulSoup(html, "html.parser")
        return self._extract_x_from_soup(soup, profile_url)

    def _ameblo_fetch_article(self, url):
        """アメブロ記事本文を取得"""
        html = get_html(url)
        if not html:
            return None, None
        soup = BeautifulSoup(html, "html.parser")

        # タイトル
        title_el = soup.select_one("h1.skin-entryTitle, h1[class*='title'], h1")
        title = clean_text(title_el.get_text()) if title_el else ""

        # 本文: アメブロは .skin-entryBody / div[class*='entryBody']
        body = ""
        for sel in [".skin-entryBody", "div[class*='entryBody']",
                    ".entry-body", "article", ".p-entry-body"]:
            el = soup.select_one(sel)
            if el:
                for tag in el(["script", "style"]):
                    tag.decompose()
                body = clean_text(el.get_text())
                if len(body) > 50:
                    break

        # フォールバック: p タグ収集
        if len(body) < 50:
            paras = [clean_text(p.get_text()) for p in soup.select("p") if len(p.get_text(strip=True)) > 20]
            body = " ".join(paras)

        # 日付
        date_str = ""
        for sel in ["time[datetime]", ".skin-entryTimestamp", "[class*='timestamp']"]:
            el = soup.select_one(sel)
            if el:
                date_str = el.get("datetime") or clean_text(el.get_text())
                break

        # 記事内のXリンクも拾う（本文中に貼っている場合）
        x_in_article = self._extract_x_from_soup(soup, url)

        return {
            "source": "ameblo",
            "product_name": "",
            "rating": None,
            "title": title,
            "body": body[:2000],
            "date": date_str,
            "url": url,
        }, x_in_article

    # ── note ────────────────────────────────────────────────────
    def _note_profile_url(self, article_url):
        """
        記事URL https://note.com/{user}/n/XXXX
        → プロフィール https://note.com/{user}
        """
        m = re.match(r"(https://note\.com/[^/]+)(/n/|$)", article_url)
        return m.group(1) if m else None

    def _note_get_x(self, profile_url):
        """
        note クリエイターページから X アカウントを取得。
        X連携すると <a href="https://x.com/username"> が出る。
        """
        html = get_html(profile_url)
        if not html:
            return []
        soup = BeautifulSoup(html, "html.parser")
        return self._extract_x_from_soup(soup, profile_url)

    def _note_fetch_article(self, url):
        """note 記事本文を取得"""
        html = get_html(url)
        if not html:
            return None, None
        soup = BeautifulSoup(html, "html.parser")

        title_el = soup.select_one("h1.o-noteContentHeader__title, h1[class*='title'], h1")
        title = clean_text(title_el.get_text()) if title_el else ""

        body = ""
        for sel in [".note-common-styles__textnote-body", "div[class*='body']",
                    ".p-article__body", "article", "main"]:
            el = soup.select_one(sel)
            if el:
                for tag in el(["script", "style"]):
                    tag.decompose()
                body = clean_text(el.get_text())
                if len(body) > 50:
                    break

        date_str = ""
        el = soup.select_one("time[datetime]")
        if el:
            date_str = el.get("datetime") or clean_text(el.get_text())

        x_in_article = self._extract_x_from_soup(soup, url)

        return {
            "source": "note",
            "product_name": "",
            "rating": None,
            "title": title,
            "body": body[:2000],
            "date": date_str,
            "url": url,
        }, x_in_article

    # ── Xアカウント抽出（共通）────────────────────────────────────
    def _extract_x_from_soup(self, soup, page_url):
        """
        soup から X アカウントを抽出する。
        優先順位:
          1. <a href="https://x.com/username"> または twitter.com/username リンク
          2. <a href="https://twitter.com/username"> リンク
          3. テキスト中の @username パターン（フォールバック）
        """
        found = []
        seen = set()

        # リンクから取得（最も確実）
        for a in soup.select("a[href]"):
            href = a.get("href", "")
            # x.com/username または twitter.com/username
            m = re.search(
                r"(?:x\.com|twitter\.com)/([A-Za-z0-9_]{4,15})(?:[/?#]|$)",
                href
            )
            if m:
                username = m.group(1).lower()
                # 除外: intent, share, hashtag 等の機能URL
                if username not in ("intent", "share", "search", "home",
                                    "explore", "notifications", "messages",
                                    "i", "settings", "login", "signup"):
                    key = username
                    if key not in seen:
                        seen.add(key)
                        found.append("@" + m.group(1))
                        log.info("[DEBUG] XAccount: found via link @%s at %s", m.group(1), page_url)

        # リンクで取れなかった場合: テキスト中の @mention
        if not found:
            text = soup.get_text()
            for m in re.finditer(r'@([A-Za-z0-9_]{4,15})\b', text):
                username = m.group(1).lower()
                noise = {"gmail", "yahoo", "docomo", "softbank", "ameba",
                         "ameblo", "note", "instagram", "facebook", "line"}
                if username not in noise and username not in seen:
                    seen.add(username)
                    found.append("@" + m.group(1))
                    log.info("[DEBUG] XAccount: found via text @%s at %s", m.group(1), page_url)

        return found

    # ── ブランド・レビュー判定 ────────────────────────────────────
    def _is_review(self, text):
        brands = ["未来舎", "powertite", "電菱", "denryo", "cotek", "パワータイト"]
        return any(b in text.lower() for b in brands) and len(text) > 200

    # ── メイン実行 ────────────────────────────────────────────────
    def run(self, brands, categories):
        all_results = []
        seen_urls = set()

        for site in self.TARGETS:
            for brand in brands:
                for cat in categories:
                    query = "{} {} レビュー {}".format(brand, cat, site)
                    log.info("[DEBUG] XAccountBlog: DDG query='%s'", query)
                    time.sleep(SLEEP_SEC)
                    article_urls = self._ddg_search(query, max_results=5)

                    for art_url in article_urls:
                        if art_url in seen_urls:
                            continue
                        seen_urls.add(art_url)
                        time.sleep(SLEEP_SEC)

                        is_ameblo = "ameblo.jp" in art_url
                        is_note   = "note.com" in art_url

                        if is_ameblo:
                            review, x_in_art = self._ameblo_fetch_article(art_url)
                            profile_url = self._ameblo_profile_url(art_url)
                        elif is_note:
                            review, x_in_art = self._note_fetch_article(art_url)
                            profile_url = self._note_profile_url(art_url)
                        else:
                            continue

                        if not review:
                            continue

                        # ブランド・レビュー判定
                        if not self._is_review(review["body"]):
                            log.info("[DEBUG] XAccountBlog: skip (no brand) %s", art_url)
                            continue

                        # プロフィールページから X アカウント取得
                        x_accounts = list(x_in_art) if x_in_art else []
                        if profile_url and profile_url not in seen_urls:
                            seen_urls.add(profile_url)
                            time.sleep(SLEEP_SEC)
                            if is_ameblo:
                                x_from_profile = self._ameblo_get_x(profile_url)
                            else:
                                x_from_profile = self._note_get_x(profile_url)
                            # マージ（重複除去）
                            existing = set(a.lower() for a in x_accounts)
                            for xa in x_from_profile:
                                if xa.lower() not in existing:
                                    x_accounts.append(xa)
                                    existing.add(xa.lower())

                        review["x_accounts"] = x_accounts
                        all_results.append(review)
                        log.info("[DEBUG] XAccountBlog: saved %s x=%s",
                                 art_url, x_accounts)

        log.info("[DEBUG] XAccountBlog: total %d articles, X found in %d",
                 len(all_results),
                 len([r for r in all_results if r.get("x_accounts")]))
        return all_results


# ─── DuckDuckGo経由 楽天レビュー直接パーサー ───────────────────
class RakutenReviewDirectScraper:
    """
    DuckDuckGo検索結果に review.rakuten.co.jp URLが直接出てくるので
    それを直接パースする。テストで8件中6件が楽天レビューURLだった。
    """
    DDG_URL = "https://html.duckduckgo.com/html/?q={query}"

    def search_review_urls(self, query, max_results=8):
        url = self.DDG_URL.format(query=quote(query))
        html = get_html(url, extra_headers={"Accept": "text/html"})
        if not html:
            return []
        soup = BeautifulSoup(html, "html.parser")
        urls = []
        for a in soup.select("a.result__url, .result__a"):
            href = a.get("href", "")
            if "uddg=" in href:
                m = re.search(r"uddg=([^&]+)", href)
                if m:
                    from urllib.parse import unquote
                    href = unquote(m.group(1))
            if "review.rakuten.co.jp/item/" in href and href.startswith("http"):
                urls.append(href)
        log.info("[DEBUG] RakutenReviewDirect DDG '%s' -> %d review URLs", query, len(urls))
        return list(dict.fromkeys(urls))[:max_results]

    def fetch_review_page(self, url):
        """review.rakuten.co.jp のレビュー一覧ページをパース"""
        html = get_html(url)
        if not html:
            return []
        soup = BeautifulSoup(html, "html.parser")
        results = []

        # 商品名（ページタイトルから）
        product_el = soup.select_one("h1, .item-name, .product-name, title")
        product_name = clean_text(product_el.get_text()) if product_el else ""

        # レビューブロック
        for item in soup.select(".ratReviewItem, .review-item, .reivew-item, li[class*='review'], div[class*='review-body']"):
            # 評価
            rating = None
            for star_el in item.select("[class*='star'], [class*='rating']"):
                cls = " ".join(star_el.get("class", []))
                m = re.search(r"(\d)(?:_(\d))?", cls)
                if m:
                    rating = float(m.group(1) + ("." + m.group(2) if m.group(2) else ".0"))
                    break
                m2 = re.search(r"(\d+(?:\.\d+)?)", star_el.get_text())
                if m2:
                    rating = float(m2.group(1))
                    break

            # タイトル
            title_el = item.select_one("[class*='title'], h3, h4")
            title = clean_text(title_el.get_text()) if title_el else ""

            # 本文
            body_el = item.select_one("[class*='body'], [class*='text'], [class*='comment'], p")
            body = clean_text(body_el.get_text()) if body_el else ""

            # 日付
            date_el = item.select_one("time, [class*='date']")
            date_str = ""
            if date_el:
                date_str = date_el.get("datetime") or clean_text(date_el.get_text())

            if body and len(body) > 20:
                results.append({
                    "source": "rakuten_review",
                    "product_name": product_name,
                    "rating": rating,
                    "title": title,
                    "body": body[:2000],
                    "date": date_str,
                    "url": url,
                })

        log.info("[DEBUG] RakutenReviewDirect %s -> %d reviews", url, len(results))
        return results

    def run(self, brands, categories):
        all_reviews = []
        seen_urls = set()
        for brand in brands:
            for cat in categories:
                query = "{} {} レビュー site:review.rakuten.co.jp".format(brand, cat)
                time.sleep(SLEEP_SEC)
                rev_urls = self.search_review_urls(query)
                for rurl in rev_urls:
                    if rurl in seen_urls:
                        continue
                    seen_urls.add(rurl)
                    time.sleep(SLEEP_SEC)
                    reviews = self.fetch_review_page(rurl)
                    all_reviews.extend(reviews)
        return all_reviews


# ─── みんカラ スクレイパー ────────────────────────────────────
class MinkaraScraper:
    """
    みんカラ (minkara.carview.co.jp) のブログ・整備手帳・パーツレビューを収集。

    テスト結果を反映した修正点:
      - パーツレビュー一覧はセレクターが全滅 → サイト内検索に一本化
      - 検索結果のURLが //minkara... 形式 → https: を補完
      - 本文セレクターが全滅 → p タグ収集フォールバックに変更
      - h1 でタイトル取得確認済み
    """
    SEARCH_URL = "https://minkara.carview.co.jp/search/?q={query}"
    BASE       = "https://minkara.carview.co.jp"

    def _is_target(self, text):
        keywords = ["未来舎", "powertite", "パワータイト", "電菱", "denryo", "cotek"]
        return any(kw in text.lower() for kw in keywords)

    def _fix_url(self, href):
        """// 始まりのURLに https: を補完、相対URLは BASE を付与"""
        if not href:
            return None
        if href.startswith("//"):
            return "https:" + href
        if href.startswith("/"):
            return self.BASE + href
        if href.startswith("http"):
            return href
        return None

    def fetch_blog_articles(self, brands, categories, max_results=5):
        """みんカラのサイト内検索でブログ記事・整備手帳・パーツレビューを取得"""
        articles = []
        seen_urls = set()
        article_pattern = re.compile(
            r"/userid/\d+/(blog/\d+|car/\d+/\d+/(note|parts)\.aspx)"
        )

        for brand in brands:
            for cat in categories:
                query = "{} {}".format(brand, cat)
                url = self.SEARCH_URL.format(query=quote(query))
                html = get_html(url)
                if not html:
                    time.sleep(SLEEP_SEC)
                    continue

                soup = BeautifulSoup(html, "html.parser")
                result_links = []
                for a in soup.select("a[href]"):
                    href = a.get("href", "")
                    if article_pattern.search(href):
                        fixed = self._fix_url(href)
                        if fixed and fixed not in seen_urls:
                            result_links.append(fixed)
                            seen_urls.add(fixed)

                log.info("[DEBUG] Minkara search '%s' -> %d links", query, len(result_links))

                for art_url in result_links[:max_results]:
                    time.sleep(SLEEP_SEC)
                    article = self._fetch_article(art_url)
                    if article:
                        articles.append(article)

                time.sleep(SLEEP_SEC)
        return articles

    def _fetch_article(self, url):
        """ブログ記事・整備手帳・パーツレビューページをパース"""
        html = get_html(url)
        if not html:
            return None
        soup = BeautifulSoup(html, "html.parser")

        # タイトル: h1 で取得確認済み
        title_el = soup.select_one("h1")
        title = clean_text(title_el.get_text()) if title_el else ""

        # 本文: 専用クラスが全滅のため p タグを広範囲に収集
        body = ""
        # まず既知セレクターを試す
        for sel in [".blogBody", ".noteBody", ".blogText",
                    "div.blogInner", "div.noteContent", "article",
                    ".p-blog-article__body", ".p-parts-review__body",
                    "div[class*='blog']", "div[class*='note']"]:
            el = soup.select_one(sel)
            if el:
                for tag in el(["script", "style", "nav"]):
                    tag.decompose()
                body = clean_text(el.get_text())
                if len(body) > 100:
                    log.info("[DEBUG] Minkara body selector hit: %s len=%d", sel, len(body))
                    break

        # フォールバック: main相当エリアのpタグをすべて結合
        if len(body) < 100:
            paras = []
            for p in soup.select("p"):
                t = clean_text(p.get_text())
                if len(t) > 20:
                    paras.append(t)
            body = " ".join(paras)
            log.info("[DEBUG] Minkara body fallback (p-tags) len=%d", len(body))

        # ブランド言及チェック
        if not self._is_target(title + " " + body):
            log.info("[DEBUG] Minkara: skip (no brand) %s", url)
            return None

        if len(body) < 50:
            return None

        # 日付
        date_str = ""
        for date_el in soup.select("time[datetime], [class*='date'], [class*='Date']"):
            date_str = date_el.get("datetime") or clean_text(date_el.get_text())
            if date_str:
                break

        # 評価（パーツレビューページのみ存在する可能性）
        rating = None
        for star_el in soup.select("[class*='star'], [class*='rating'], [class*='Star']"):
            cls = " ".join(star_el.get("class", []))
            m = re.search(r"s(\d)(\d)", cls)
            if m:
                rating = float("{}.{}".format(m.group(1), m.group(2)))
                break

        # 記事種別
        src = "minkara_blog"
        if "note.aspx" in url:
            src = "minkara_note"
        elif "parts.aspx" in url:
            src = "minkara_parts"

        return {
            "source":       src,
            "product_name": "",
            "rating":       rating,
            "title":        title,
            "body":         body[:2000],
            "date":         date_str,
            "url":          url,
        }

    def run(self, brands, categories):
        log.info("[DEBUG] Minkara: サイト内検索開始（一覧巡回は廃止）")
        results = self.fetch_blog_articles(brands, categories, max_results=5)
        log.info("[DEBUG] Minkara: %d件取得", len(results))
        return results


# ─── メイン処理 ──────────────────────────────────────────────
def main():
    output_file = f"reviews_{datetime.now().strftime('%Y%m%d_%H%M%S')}.json"
    all_reviews = []

    target_brands = ["未来舎", "電菱"]
    target_cats   = ["インバーター", "コンバーター"]

    # ── 1. Amazon（CAPTCHA確定のためスキップ、PA-API取得後に有効化）──
    log.info("=== Amazon スキップ（CAPTCHA対策のため無効化中）===")

    # ── 2. 価格.com（404のためスキップ）──
    log.info("=== 価格.com スキップ（URLパターン変更のため無効化中）===")

    # ── 3. 楽天（商品ページ経由）──
    log.info("=== 楽天 スクレイピング開始 ===")
    rakuten = RakutenScraper()
    rakuten_reviews = rakuten.run(target_brands, target_cats)
    all_reviews.extend(rakuten_reviews)
    log.info("楽天: %d件取得", len(rakuten_reviews))

    # ── 4. 楽天レビュー直接（DuckDuckGo経由）──
    log.info("=== 楽天レビュー直接取得 開始 ===")
    rakuten_direct = RakutenReviewDirectScraper()
    rakuten_direct_reviews = rakuten_direct.run(target_brands, target_cats)
    all_reviews.extend(rakuten_direct_reviews)
    log.info("楽天レビュー直接: %d件取得", len(rakuten_direct_reviews))

    # ── 4. ブログ（一般）──
    log.info("=== ブログ スクレイピング開始 ===")
    blog = BlogScraper()
    blog_results = blog.run(target_brands, target_cats)
    all_reviews.extend(blog_results)
    log.info("ブログ: %d件取得", len(blog_results))

    # ── 5. アメブロ・note（Xアカウント取得付き）──
    log.info("=== アメブロ・note Xアカウント取得 開始 ===")
    xblog = XAccountBlogScraper()
    xblog_results = xblog.run(target_brands, target_cats)
    all_reviews.extend(xblog_results)
    x_found = len([r for r in xblog_results if r.get("x_accounts")])
    log.info("アメブロ・note: %d件取得 うちXアカウント取得済み %d件", len(xblog_results), x_found)

    # ── 5. みんカラ ──
    log.info("=== みんカラ スクレイピング開始 ===")
    minkara = MinkaraScraper()
    minkara_results = minkara.run(target_brands, target_cats)
    all_reviews.extend(minkara_results)
    log.info("みんカラ: %d件取得", len(minkara_results))

    # ── 出力 ──
    minkara_count = len([r for r in all_reviews if r["source"].startswith("minkara")])
    output = {
        "generated_at": datetime.now().isoformat(),
        "total": len(all_reviews),
        "breakdown": {
            "amazon":          0,
            "kakaku":          0,
            "rakuten":         len([r for r in all_reviews if r["source"] == "rakuten"]),
            "rakuten_review":  len([r for r in all_reviews if r["source"] == "rakuten_review"]),
            "blog":            len([r for r in all_reviews if r["source"] == "blog"]),
            "ameblo":          len([r for r in all_reviews if r["source"] == "ameblo"]),
            "note":            len([r for r in all_reviews if r["source"] == "note"]),
            "ameblo_note_x":   len([r for r in all_reviews
                                    if r["source"] in ("ameblo", "note")
                                    and r.get("x_accounts")]),
            "minkara_parts":   len([r for r in all_reviews if r["source"] == "minkara_parts"]),
            "minkara_blog":    len([r for r in all_reviews if r["source"] == "minkara_blog"]),
            "minkara_note":    len([r for r in all_reviews if r["source"] == "minkara_note"]),
            "minkara_total":   minkara_count,
        },
        "reviews": all_reviews,
    }

    with open(output_file, "w", encoding="utf-8") as f:
        json.dump(output, f, ensure_ascii=False, indent=2)

    log.info("=== 完了: %d件 -> %s ===", len(all_reviews), output_file)
    print(json.dumps(output["breakdown"], ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
