"""kurage_clicks センサ — kurage.exbridge.jp への送客KPIを測る。

AIKnowledgeCMSは自サイトの成長に加えて、Kurageエコシステムの他サイト
(まずは kurage.exbridge.jp の動画コンテンツ)への集客も役割に持つ。
kurage側の access.log (simpletrack形式) をFTPで末尾だけ取得し、
当サイト発のクリックを ref= パラメータで数える:

  - ref=akc   … ダイジェスト記事内の動画リンク (video_digest / link_ref)
  - ref=akc-w … 高PVページ設置の「最新動画」ウィジェット (videos_widget)

refが落ちるケースの参考値として、リファラに aiknowledgecms を含む
クリックも数える。動画ページ全体のPVも文脈用に記録する。
"""
from __future__ import annotations

import ftplib
import io
import re
import time

from core import state
from adapters.sensors.simpletrack import _is_bot

NAME = "kurage_clicks"

_REF_RE = re.compile(r"[?&]ref=([\w-]+)")


def sense(cfg: dict, conn, tick_id: int) -> dict:
    kc = cfg.get("kurage_clicks", {})
    env = cfg["_env"]
    log_path = kc.get("ftp_log_path", "/web/kurage_exbridge_jp/access.log")
    tail = int(kc.get("tail_bytes", 2_000_000))
    video_path = kc.get("video_path", "kuragev.php")

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

    cutoff = time.time() - 24 * 3600
    video_pv = 0
    clicks_article = 0   # ref=akc
    clicks_widget = 0    # ref=akc-w
    ref_referrer = 0     # リファラがaiknowledgecms(ref無しの参考値)
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
        url = parts[2]
        if video_path not in url:
            continue
        video_pv += 1
        m = _REF_RE.search(url)
        ref = m.group(1) if m else ""
        if ref == "akc":
            clicks_article += 1
        elif ref == "akc-w":
            clicks_widget += 1
        elif "aiknowledgecms" in parts[3].lower():
            ref_referrer += 1

    sent = clicks_article + clicks_widget
    state.record(conn, tick_id, NAME, "kurage_video_pv_24h", video_pv,
                 {"log_bytes": size})
    state.record(conn, tick_id, NAME, "kurage_clicks_sent_24h", sent,
                 {"article": clicks_article, "widget": clicks_widget,
                  "referrer_only": ref_referrer})
    return {"video_pv_24h": video_pv, "sent_24h": sent,
            "article_24h": clicks_article, "widget_24h": clicks_widget,
            "referrer_only_24h": ref_referrer}
