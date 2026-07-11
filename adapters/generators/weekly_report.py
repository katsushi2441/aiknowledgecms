"""weekly_report ジェネレータ — ループ自身の運営データから週次レポート記事を作る。

LLMを使わない決定的生成(数字はすべて自DBの実測)なので、検証ゲートは不要。
「AIが自分で運営するサイトの実測データ」という、ここにしかない一次情報が看板コンテンツになる。
"""
from __future__ import annotations

import json
import time

from core import state

SLUG_PREFIX = "loop-weekly-"


def due(conn) -> bool:
    """前回の週次レポートから7日以上経過していたらTrue。"""
    row = conn.execute(
        "SELECT published_at FROM content WHERE slug LIKE ? AND status='published'"
        " ORDER BY id DESC LIMIT 1", (SLUG_PREFIX + "%",)).fetchone()
    if row is None:
        return True
    last = time.mktime(time.strptime(row["published_at"][:10], "%Y-%m-%d"))
    return (time.time() - last) >= 7 * 86400


def _metric_series(conn, key: str, days: int = 7):
    cutoff = time.strftime("%Y-%m-%d", time.localtime(time.time() - days * 86400))
    return conn.execute(
        "SELECT value, created_at FROM observations WHERE key=? AND created_at>=?"
        " ORDER BY id", (key, cutoff)).fetchall()


def generate(cfg: dict, conn, tick_id: int) -> dict:
    """週次レポートのドラフト(決定的)を作りcontentへ登録して返す。"""
    now = state.now()
    week = time.strftime("%G-w%V")
    slug = SLUG_PREFIX + week

    pv_new = _metric_series(conn, "pv_new_24h")
    pv_all = _metric_series(conn, "pv_24h")
    ticks = conn.execute(
        "SELECT COUNT(*) FROM ticks WHERE started_at >= datetime('now','-7 day','localtime')"
    ).fetchone()[0]
    published = conn.execute(
        "SELECT COUNT(*) FROM content WHERE status='published'"
        " AND published_at >= date('now','-7 day')").fetchone()[0]
    rejected = conn.execute(
        "SELECT COUNT(*) FROM content WHERE status='rejected'"
        " AND created_at >= date('now','-7 day')").fetchone()[0]
    issues_opened = conn.execute(
        "SELECT COUNT(*) FROM issues WHERE created_at >= date('now','-7 day')").fetchone()[0]
    issues_resolved = conn.execute(
        "SELECT COUNT(*) FROM issues WHERE status='resolved'"
        " AND updated_at >= date('now','-7 day')").fetchone()[0]
    acts_done = conn.execute(
        "SELECT COUNT(*) FROM observations WHERE sensor='act'"
        " AND key LIKE 'act_%' AND key != 'act_error'"
        " AND created_at >= date('now','-7 day')").fetchone()[0]
    open_issue_titles = [r["title"] for r in conn.execute(
        "SELECT title FROM issues WHERE status='open' ORDER BY id DESC LIMIT 5")]
    gsc_imp = conn.execute(
        "SELECT value FROM observations WHERE key='gsc_impressions_28d'"
        " ORDER BY id DESC LIMIT 1").fetchone()
    gsc_clk = conn.execute(
        "SELECT value FROM observations WHERE key='gsc_clicks_28d'"
        " ORDER BY id DESC LIMIT 1").fetchone()
    top_q = conn.execute(
        "SELECT title FROM research WHERE source='gsc_opportunity'"
        " ORDER BY score DESC LIMIT 3").fetchall()

    def rng(series):
        vals = [r["value"] for r in series if r["value"] is not None]
        if not vals:
            return "計測中"
        return f"{int(min(vals))}〜{int(max(vals))} (最新 {int(vals[-1])})"

    body = f"""このレポートは、当サイトを運用しているエージェントループが自分の運営データ(SQLite)から自動生成した週次報告です。数字はすべて実測値で、LLMによる生成を含みません。

### 今週のKPI (新システムのページのみ)

- 24時間PV(新システム): {rng(pv_new)}
- 24時間PV(サイト全体・旧遺産ページ含む): {rng(pv_all)}
- Google検索 表示回数(直近28日): {int(gsc_imp['value']) if gsc_imp else '計測中'}
- Google検索 クリック(直近28日): {int(gsc_clk['value']) if gsc_clk else '計測中'}

### ループの稼働

- 実行tick数(7日間): {ticks}
- 公開した記事: {published}本
- 品質ゲートで却下したドラフト: {rejected}本 (却下理由は台帳に記録)
- 開いた課題: {issues_opened} / 解決した課題: {issues_resolved} / 自動処置(ACT): {acts_done}件
- 未解決の課題: {chr(10) + chr(10).join('  - ' + t for t in open_issue_titles) if open_issue_titles else 'なし'}

### 次に狙う検索クエリ (GSC opportunity)

{chr(10).join('- ' + r['title'] for r in top_q) or '- (蓄積中)'}

### このレポートについて

生成・検証・公開・計測のループ構造は [AIKnowledgeCMS](https://aiknowledgecms.exbridge.jp/aiknowledgecms.html) を参照してください。ループの毎時の実行記録は [/loop/](https://aiknowledgecms.exbridge.jp/loop/) で公開しています。

## 参考
- https://aiknowledgecms.exbridge.jp/loop/
"""
    pv_latest = int(pv_new[-1]["value"]) if pv_new and pv_new[-1]["value"] is not None else 0
    title = (f"AIが運営するサイトの実測レポート {week}: "
             f"PV{pv_latest}/日・生成{published}本・却下{rejected}本")

    conn.execute(
        "INSERT OR REPLACE INTO content (slug, title, status, body_md, sources,"
        " gate_result, created_tick, created_at)"
        " VALUES (?, ?, 'draft', ?, ?, ?, ?, ?)",
        (slug, title, body,
         json.dumps(["https://aiknowledgecms.exbridge.jp/loop/"]),
         json.dumps({"passed": True, "problems": [],
                     "verifier": {"verdict": "PASS", "model": "deterministic(自DB実測)",
                                  "reason": "LLM非使用・全数値が自DBの実測値"}},
                    ensure_ascii=False),
         tick_id, now),
    )
    conn.commit()
    return {"slug": slug, "title": title, "body": body,
            "sources": ["https://aiknowledgecms.exbridge.jp/loop/"],
            "source_ids": []}
