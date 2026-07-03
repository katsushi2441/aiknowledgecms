"""simpletrack センサ — heteml上の access.log をFTPで末尾だけ取得して解析する。

ログ形式: "YYYY-mm-dd HH:MM:SS | ip | url | ref | ua"
全量(数十MB)はダウンロードせず、RESTオフセットで末尾 tail_bytes のみ取得する。
"""
from __future__ import annotations

import ftplib
import io
import time

from core import state

NAME = "simpletrack"

BOT_WORDS = (
    "bot", "crawler", "spider", "slurp", "crawl", "mediapartners", "curl", "wget",
    "python", "httpclient", "scrapy", "headless", "phantom", "selenium",
    "playwright", "puppeteer", "facebookexternalhit", "meta-externalagent",
    "twitterbot", "slackbot", "discordbot", "linebot", "googlebot", "googleother",
    "bingbot", "duckduckbot", "baiduspider", "yandexbot", "ahrefsbot", "semrushbot",
    "mj12bot", "petalbot", "bytespider", "claudebot", "gptbot", "oai-searchbot",
    "ccbot", "perplexitybot", "applebot", "amazonbot",
)


def _is_bot(ua: str) -> bool:
    ua = ua.lower()
    return ua == "" or any(w in ua for w in BOT_WORDS)


def sense(cfg: dict, conn, tick_id: int) -> dict:
    sc = cfg.get("simpletrack", {})
    env = cfg["_env"]
    log_path = sc["ftp_log_path"]
    tail = int(sc.get("tail_bytes", 2_000_000))

    buf = io.BytesIO()
    with ftplib.FTP(env["FTP_HOST"], timeout=60) as ftp:
        ftp.login(env["FTP_USER"], env["FTP_PASS"])
        ftp.voidcmd("TYPE I")
        size = ftp.size(log_path)
        rest = max(0, size - tail)
        ftp.retrbinary(f"RETR {log_path}", buf.write, rest=rest)

    lines = buf.getvalue().decode("utf-8", errors="ignore").splitlines()
    if rest > 0 and lines:
        lines = lines[1:]  # 先頭は途中で切れた行

    # 新システム(フレームワークサイト)のパス。旧システムの遺産PVと分離して測る
    new_paths = tuple(sc.get("new_system_paths",
        ["/", "/index.html", "/aiknowledgecms.html", "/articles/", "/loop/"]))

    def _is_new_system(url: str) -> bool:
        try:
            path = url.split("//", 1)[-1].split("/", 1)
            path = "/" + (path[1] if len(path) > 1 else "")
        except Exception:
            return False
        path = path.split("?")[0]
        return any(path == np or (np.endswith("/") and np != "/" and path.startswith(np))
                   for np in new_paths)

    cutoff = time.time() - 24 * 3600
    pv = 0
    pv_new = 0
    ips: set = set()
    ips_new: set = set()
    urls: dict = {}
    for line in lines:
        parts = line.split(" | ")
        if len(parts) < 5:
            continue
        try:
            ts = time.mktime(time.strptime(parts[0], "%Y-%m-%d %H:%M:%S"))
        except ValueError:
            continue
        if ts < cutoff or _is_bot(parts[4]):
            continue
        pv += 1
        ips.add(parts[1])
        url = parts[2].split("?")[0]
        urls[url] = urls.get(url, 0) + 1
        if _is_new_system(parts[2]):
            pv_new += 1
            ips_new.add(parts[1])

    top = sorted(urls.items(), key=lambda kv: -kv[1])[:10]
    state.record(conn, tick_id, NAME, "pv_24h", pv, {"log_bytes": size})
    state.record(conn, tick_id, NAME, "pv_new_24h", pv_new)
    state.record(conn, tick_id, NAME, "uniq_ips_24h", len(ips))
    state.record(conn, tick_id, NAME, "uniq_ips_new_24h", len(ips_new))
    state.record(conn, tick_id, NAME, "top_urls_24h", None,
                 {"top": [{"url": u, "pv": c} for u, c in top]})
    return {"pv_24h": pv, "pv_new_24h": pv_new,
            "uniq_ips_24h": len(ips), "uniq_ips_new_24h": len(ips_new), "top": top}
