"""AIKnowledgeCMS Loop — tick ランナー (Phase 1: SENSE / TRIAGE / REPORT)

使い方:
    python3 -m core.loop            # 1 tick 実行(公開・通知あり)
    python3 -m core.loop --dry-run  # 副作用なし(FTP公開・メールをしない)

ガードレール:
    - data/KILL が存在したら何もせず終了する (kill switch)
    - dry-run は観測と判断を行うが、外部への副作用を持たない
"""
from __future__ import annotations

import argparse
import ftplib
import html
import json
import smtplib
import ssl
import sys
import traceback
from email.mime.text import MIMEText
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from core import loopfile, state
from adapters.sensors import http_health, simpletrack

ROOT = Path(__file__).resolve().parents[1]
REPORTS = ROOT / "reports"
KILL = ROOT / "data" / "KILL"

SENSORS = {
    "http_health": http_health,
    "simpletrack": simpletrack,
}


# ---------------------------------------------------------------- triage
def triage(cfg: dict, conn, tick_id: int, sensed: dict) -> dict:
    """観測値をルールで課題キューへ変換する。Phase 1 はルールベース。"""
    th = cfg.get("thresholds", {})
    opened: list[dict] = []
    resolved: list[str] = []

    def _open(fp, severity, title, detail):
        if state.open_issue(conn, tick_id, fp, severity, title, detail):
            opened.append({"fingerprint": fp, "severity": severity, "title": title})

    def _resolve(fp):
        if state.resolve_issue(conn, tick_id, fp):
            resolved.append(fp)

    # ページ死活と応答速度
    for page in sensed.get("http_health", {}).get("pages", []):
        fp_down = f"page_down:{page['page']}"
        fp_slow = f"page_slow:{page['page']}"
        ok = 200 <= page["status"] < 400
        if not ok:
            _open(fp_down, "critical",
                  f"ページ異常: {page['page']} (HTTP {page['status']})",
                  json.dumps(page, ensure_ascii=False))
        else:
            _resolve(fp_down)
            if page["ms"] > int(th.get("slow_page_ms", 4000)):
                _open(fp_slow, "warning",
                      f"応答が遅い: {page['page']} ({page['ms']}ms)",
                      json.dumps(page, ensure_ascii=False))
            else:
                _resolve(fp_slow)

    # PVトレンド (前tick比)
    st = sensed.get("simpletrack")
    if st is not None:
        prev = state.latest_value(conn, "pv_24h", before_tick=tick_id)
        cur = st["pv_24h"]
        drop_pct = int(th.get("pv_drop_pct", 30))
        if prev is not None and prev >= 20 and cur < prev * (1 - drop_pct / 100):
            _open("pv_drop", "warning",
                  f"PV下落: 24h PVが {int(prev)} → {int(cur)} ({drop_pct}%閾値超え)",
                  json.dumps({"prev": prev, "cur": cur}))
        else:
            _resolve("pv_drop")

    conn.commit()
    return {"opened": opened, "resolved": resolved}


# ---------------------------------------------------------------- report
def build_report_md(cfg, conn, tick_id, sensed, triaged, dry_run) -> str:
    lines = [f"# Loop Report — tick {tick_id}", ""]
    lines.append(f"- 実行時刻: {state.now()}")
    lines.append(f"- モード: {'dry-run' if dry_run else 'live'}")
    lines.append("")
    lines.append("## KPI")
    st = sensed.get("simpletrack") or {}
    hh = sensed.get("http_health") or {}
    lines.append(f"- pv_24h: **{st.get('pv_24h', 'n/a')}**")
    lines.append(f"- uniq_ips_24h: **{st.get('uniq_ips_24h', 'n/a')}**")
    lines.append(f"- pages_healthy: **{hh.get('healthy', '?')}/{hh.get('total', '?')}**")
    lines.append("")
    if st.get("top"):
        lines.append("## 24h Top URLs")
        for url, c in st["top"]:
            lines.append(f"- {c} pv — {url}")
        lines.append("")
    lines.append("## ページ死活")
    for p in hh.get("pages", []):
        mark = "✅" if 200 <= p["status"] < 400 else "🛑"
        lines.append(f"- {mark} {p['page']} — HTTP {p['status']} / {p['ms']}ms"
                     + (f" / {p['error']}" if p["error"] else ""))
    lines.append("")
    lines.append("## TRIAGE")
    lines.append(f"- 新規/再発 issue: {len(triaged['opened'])}")
    for o in triaged["opened"]:
        lines.append(f"  - [{o['severity']}] {o['title']}")
    lines.append(f"- 解決 issue: {len(triaged['resolved'])}")
    lines.append("")
    lines.append("## Open Issues (キュー)")
    rows = state.open_issues(conn)
    if not rows:
        lines.append("- なし 🎉")
    for r in rows:
        lines.append(f"- [{r['severity']}] {r['title']} (初出 tick {r['first_seen_tick']})")
    return "\n".join(lines) + "\n"


HTML_SHELL = """<!doctype html>
<html lang="ja"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{title}</title>
<style>
body{{margin:0;background:#f7f9fc;color:#101828;font-family:-apple-system,"Segoe UI","Hiragino Sans","Noto Sans JP",sans-serif;line-height:1.75}}
.wrap{{max-width:860px;margin:0 auto;padding:34px 20px 60px}}
.card{{background:#fff;border:1px solid #e9edf3;border-radius:16px;padding:28px 30px;box-shadow:0 1px 3px rgba(16,24,40,.05)}}
h1{{font-size:22px;letter-spacing:-.01em;margin:0 0 4px}}
h2{{font-size:15px;color:#4f46e5;letter-spacing:.08em;text-transform:uppercase;margin:26px 0 8px}}
ul{{margin:0;padding-left:20px}} li{{margin:3px 0;font-size:14px;color:#475467}}
li strong{{color:#101828}}
.meta{{font-size:12.5px;color:#98a2b3;margin-bottom:6px}}
a{{color:#4f46e5;text-decoration:none;font-weight:600}}
.nav{{display:flex;justify-content:space-between;margin-bottom:14px;font-size:13px}}
</style></head><body><div class="wrap">
<div class="nav"><a href="index.html">← Loop Reports</a><a href="/aiknowledgecms.html">AIKnowledgeCMS</a></div>
<div class="card">{body}</div>
</div></body></html>
"""


def md_to_simple_html(md: str) -> str:
    out = []
    in_list = False
    for line in md.splitlines():
        esc = html.escape(line)
        # 最小限の整形(見出し・リスト・太字)のみ
        while "**" in esc:
            esc = esc.replace("**", "<strong>", 1).replace("**", "</strong>", 1)
        if esc.startswith("# "):
            out.append(f"<h1>{esc[2:]}</h1>")
        elif esc.startswith("## "):
            if in_list:
                out.append("</ul>"); in_list = False
            out.append(f"<h2>{esc[3:]}</h2>")
        elif esc.lstrip().startswith("- "):
            if not in_list:
                out.append("<ul>"); in_list = True
            out.append(f"<li>{esc.lstrip()[2:]}</li>")
        elif esc.strip() == "":
            if in_list:
                out.append("</ul>"); in_list = False
        else:
            out.append(f'<div class="meta">{esc}</div>')
    if in_list:
        out.append("</ul>")
    return "\n".join(out)


def publish_reports(cfg: dict, conn, tick_id: int, report_md: str) -> list[str]:
    """レポートHTMLを /loop/ にFTP公開する。公開したリモートパスを返す。"""
    env = cfg["_env"]
    remote_dir = cfg["publisher"]["remote_dir"].rstrip("/")
    body = md_to_simple_html(report_md)
    page = HTML_SHELL.format(title=f"Loop Report tick {tick_id} — AIKnowledgeCMS", body=body)

    # index: 直近50tickの一覧
    rows = conn.execute(
        "SELECT id, started_at, dry_run, summary FROM ticks"
        " WHERE finished_at IS NOT NULL OR id = ? ORDER BY id DESC LIMIT 50", (tick_id,)
    ).fetchall()
    items = "\n".join(
        f'<li><a href="tick-{r["id"]}.html">tick {r["id"]}</a>'
        f' <span class="meta">{html.escape(r["started_at"])}'
        f'{" (dry-run)" if r["dry_run"] else ""} — {html.escape(r["summary"] or "")}</span></li>'
        for r in rows if not r["dry_run"] or r["id"] == tick_id
    )
    index_page = HTML_SHELL.format(
        title="Loop Reports — AIKnowledgeCMS",
        body=f"<h1>Loop Reports</h1>"
             f'<div class="meta">このサイトを運用しているエージェントループの実行レポート(自動生成)。</div>'
             f"<ul>{items}</ul>")

    uploaded = []
    with ftplib.FTP(env["FTP_HOST"], timeout=60) as ftp:
        ftp.login(env["FTP_USER"], env["FTP_PASS"])
        try:
            ftp.mkd(remote_dir)
        except ftplib.error_perm:
            pass
        for name, content in [
            (f"tick-{tick_id}.html", page),
            ("latest.html", page),
            ("index.html", index_page),
        ]:
            bio = __import__("io").BytesIO(content.encode("utf-8"))
            ftp.storbinary(f"STOR {remote_dir}/{name}", bio)
            uploaded.append(f"{remote_dir}/{name}")
    return uploaded


# ---------------------------------------------------------------- escalate
def escalate(cfg: dict, opened: list[dict]) -> bool:
    criticals = [o for o in opened if o["severity"] == "critical"]
    if not criticals or "critical_issue_opened" not in cfg.get("escalate_when", []):
        return False
    env = cfg["_env"]
    to = cfg.get("escalation", {}).get("email", "")
    host = env.get("SMTP_HOST"); user = env.get("SMTP_USER") or env.get("SMTP_FROM")
    pw = env.get("SMTP_PASSWORD"); frm = env.get("SMTP_FROM", user)
    if not (to and host and user and pw):
        return False
    body = "AIKnowledgeCMS Loopがcriticalなissueを検出しました。\n\n" + \
        "\n".join(f"- {c['title']}" for c in criticals) + \
        f"\n\nレポート: {cfg['site'].rstrip('/')}/loop/latest.html"
    msg = MIMEText(body, "plain", "utf-8")
    msg["Subject"] = "[AIKnowledgeCMS Loop] critical issue"
    msg["From"] = frm
    msg["To"] = to
    ctx = ssl.create_default_context()
    with smtplib.SMTP_SSL(host, int(env.get("SMTP_PORT", "465")), context=ctx, timeout=30) as s:
        s.login(user, pw)
        s.sendmail(frm, [to], msg.as_string())
    return True


# ---------------------------------------------------------------- tick
def run_tick(cfg: dict, dry_run: bool) -> int:
    if KILL.exists():
        print("KILL switch present — loop halted. (data/KILL を削除すると再開)")
        return 2

    conn = state.connect()
    tick_id = state.begin_tick(conn, dry_run)
    print(f"tick {tick_id} start (dry_run={dry_run})")

    # SENSE
    sensed: dict = {}
    for name in cfg.get("sensors", []):
        mod = SENSORS.get(name)
        if mod is None:
            print(f"  sensor {name}: unknown — skip")
            continue
        try:
            sensed[name] = mod.sense(cfg, conn, tick_id)
            print(f"  SENSE {name}: ok")
        except Exception:
            print(f"  SENSE {name}: FAILED")
            traceback.print_exc()
            state.record(conn, tick_id, name, "sensor_error", 1,
                         {"error": traceback.format_exc()[-500:]})
    conn.commit()

    # TRIAGE
    triaged = triage(cfg, conn, tick_id, sensed)
    print(f"  TRIAGE: opened={len(triaged['opened'])} resolved={len(triaged['resolved'])}")

    # REPORT
    report_md = build_report_md(cfg, conn, tick_id, sensed, triaged, dry_run)
    REPORTS.mkdir(exist_ok=True)
    (REPORTS / f"tick-{tick_id}.md").write_text(report_md, encoding="utf-8")

    st = sensed.get("simpletrack") or {}
    hh = sensed.get("http_health") or {}
    summary = (f"pv24h={st.get('pv_24h', '?')} healthy={hh.get('healthy', '?')}/"
               f"{hh.get('total', '?')} open_issues={len(state.open_issues(conn))}")
    state.finish_tick(conn, tick_id, summary)

    if not dry_run:
        uploaded = publish_reports(cfg, conn, tick_id, report_md)
        print(f"  REPORT published: {len(uploaded)} files")
        if escalate(cfg, triaged["opened"]):
            print("  ESCALATE: email sent")
    else:
        print("  (dry-run: 公開・通知はスキップ)")

    print(f"tick {tick_id} done — {summary}")
    return 0


def main() -> int:
    ap = argparse.ArgumentParser(description="AIKnowledgeCMS loop tick runner")
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--loopfile", default=None)
    args = ap.parse_args()
    cfg = loopfile.load(args.loopfile)
    return run_tick(cfg, args.dry_run)


if __name__ == "__main__":
    sys.exit(main())
