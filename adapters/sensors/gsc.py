"""gsc センサ — Google Search Console の検索アナリティクスを観測する。

集客ループの心臓部 (Phase 4 = MEASURE):
  - 「表示回数はあるがクリックが少ないクエリ」を opportunity として research へ
  - 「2ページ目(11〜20位)にいる高表示クエリ」を優先 opportunity として research へ
  - 記事ページ別の 表示/クリック/順位 を計測し content の成績として観測に残す
  - 成績の悪い公開記事をリライト候補 (refresh://) として research へ
  - 追跡クエリの順位下落を検出して TRIAGE に渡す

認証は2方式 (loopfile: gsc.auth):
  - gcloud (既定): Application Default Credentials。
    ADCファイルの quota_project_id を X-Goog-User-Project として送る(必須)。
  - service_account: サービスアカウントJSON (gsc.credentials_file) をJWT自前署名。
どちらも使えない場合は観測をスキップする(エラーにしない)。
"""
from __future__ import annotations

import base64
import json
import subprocess
import time
import urllib.parse
import urllib.request
from pathlib import Path

from core import state

NAME = "gsc"
TOKEN_URL = "https://oauth2.googleapis.com/token"
SCOPE = "https://www.googleapis.com/auth/webmasters.readonly"
ADC_PATH = Path.home() / ".config/gcloud/application_default_credentials.json"


def _b64url(data: bytes) -> bytes:
    return base64.urlsafe_b64encode(data).rstrip(b"=")


def _sa_access_token(creds: dict) -> str:
    """サービスアカウントJWTでOAuthトークンを取得(RS256署名)。"""
    from cryptography.hazmat.primitives import hashes, serialization
    from cryptography.hazmat.primitives.asymmetric import padding

    now = int(time.time())
    header = _b64url(json.dumps({"alg": "RS256", "typ": "JWT"}).encode())
    claim = _b64url(json.dumps({
        "iss": creds["client_email"],
        "scope": SCOPE,
        "aud": TOKEN_URL,
        "iat": now,
        "exp": now + 3600,
    }).encode())
    signing_input = header + b"." + claim
    key = serialization.load_pem_private_key(creds["private_key"].encode(), password=None)
    sig = _b64url(key.sign(signing_input, padding.PKCS1v15(), hashes.SHA256()))
    jwt = (signing_input + b"." + sig).decode()

    data = urllib.parse.urlencode({
        "grant_type": "urn:ietf:params:oauth:grant-type:jwt-bearer",
        "assertion": jwt,
    }).encode()
    req = urllib.request.Request(TOKEN_URL, data=data,
                                 headers={"Content-Type": "application/x-www-form-urlencoded"})
    with urllib.request.urlopen(req, timeout=30) as r:
        return json.loads(r.read())["access_token"]


def _auth(gc: dict) -> tuple[str, str] | None:
    """(token, quota_project) を返す。認証手段が無ければ None。"""
    mode = gc.get("auth", "gcloud")
    if mode == "gcloud":
        proc = subprocess.run(
            ["gcloud", "auth", "application-default", "print-access-token"],
            capture_output=True, text=True)
        token = proc.stdout.strip()
        if proc.returncode != 0 or not token:
            return None
        qp = gc.get("quota_project", "")
        if not qp and ADC_PATH.exists():
            qp = json.loads(ADC_PATH.read_text()).get("quota_project_id", "")
        return token, qp
    creds_path = gc.get("credentials_file", "")
    if not creds_path or not Path(creds_path).exists():
        return None
    return _sa_access_token(json.loads(Path(creds_path).read_text())), ""


def _query_api(site: str, token: str, quota_project: str, payload: dict) -> list:
    body = json.dumps(payload).encode()
    url = ("https://www.googleapis.com/webmasters/v3/sites/"
           + urllib.parse.quote(site, safe="") + "/searchAnalytics/query")
    headers = {"Authorization": f"Bearer {token}", "Content-Type": "application/json"}
    if quota_project:
        headers["X-Goog-User-Project"] = quota_project
    req = urllib.request.Request(url, data=body, headers=headers)
    with urllib.request.urlopen(req, timeout=60) as r:
        return json.loads(r.read()).get("rows", [])


def _is_writable_query(q: str) -> bool:
    """記事化に向くクエリだけ通す。検索演算子入り・長文(ニュース見出しの
    貼り付け等)は接地しても良い記事にならないため機会に積まない。"""
    if len(q) > 40:
        return False
    if any(tok in q for tok in ("site:", "intitle:", "inurl:", '"', "“", "”")):
        return False
    return True


def _add_research(conn, tick_id: int, source: str, title: str, url: str,
                  summary: str, score: float) -> bool:
    try:
        conn.execute(
            "INSERT INTO research (tick_id, source, title, url, summary, score, created_at)"
            " VALUES (?, ?, ?, ?, ?, ?, ?)",
            (tick_id, source, title, url, summary, score, state.now()))
        return True
    except Exception:
        return False  # 既出URL(UNIQUE)はスキップ


def _sense_article_metrics(cfg: dict, gc: dict, conn, tick_id: int,
                           site: str, token: str, quota_project: str,
                           start: str, end: str) -> list[dict]:
    """記事ページ別のGSC成績を観測し、成績の悪い記事をリライト候補に積む。"""
    rows = _query_api(site, token, quota_project, {
        "startDate": start, "endDate": end,
        "dimensions": ["page"], "rowLimit": 200,
    })
    metrics = []
    for r in rows:
        page = r["keys"][0]
        if "/articles/" not in page:
            continue
        slug = page.rsplit("/", 1)[-1].removesuffix(".html")
        if not slug or slug == "index":
            continue
        metrics.append({"slug": slug, "impressions": r["impressions"],
                        "clicks": r["clicks"], "ctr": round(r.get("ctr", 0), 4),
                        "position": round(r.get("position", 0), 1)})
    metrics.sort(key=lambda m: -m["impressions"])
    state.record(conn, tick_id, NAME, "article_metrics", len(metrics),
                 {"articles": metrics[:50]})

    # リライト候補: 公開から一定日数を経て、表示はあるのに成果が悪い記事。
    # 月に1回まで(refresh://slug/YYYYMM のURL一意性で自然に抑制)。
    min_age_days = int(gc.get("refresh_min_age_days", 7))
    min_imp = int(gc.get("refresh_min_impressions", 30))
    month = time.strftime("%Y%m")
    cutoff = time.strftime("%Y-%m-%d %H:%M:%S",
                           time.localtime(time.time() - min_age_days * 86400))
    added = 0
    for m in metrics:
        if m["impressions"] < min_imp:
            continue
        if not (m["position"] > 10 or m["ctr"] < 0.01):
            continue
        row = conn.execute(
            "SELECT slug FROM content WHERE slug=? AND status='published'"
            " AND published_at <= ?", (m["slug"], cutoff)).fetchone()
        if row is None:
            continue
        pending = conn.execute(
            "SELECT 1 FROM research WHERE source='gsc_refresh' AND used=0"
            " AND url LIKE ?", (f"refresh://{m['slug']}/%",)).fetchone()
        if pending:
            continue
        if _add_research(
                conn, tick_id, "gsc_refresh",
                f"記事リライト: {m['slug']}",
                f"refresh://{m['slug']}/{month}",
                f"GSC 28日間: 表示{m['impressions']}回・クリック{m['clicks']}回"
                f"(CTR {m['ctr']:.1%})・平均順位{m['position']}。検索意図への回答を強化し、"
                f"タイトル・冒頭・見出しを改善して順位/CTRを上げる。",
                11.0 + m["impressions"] / 200):
            added += 1
    conn.commit()
    state.record(conn, tick_id, NAME, "gsc_refresh_added", added)
    return metrics


def _inspect_index(site: str, token: str, quota_project: str, url: str) -> str:
    """URL検査APIでcoverageState文字列を返す。"""
    body = json.dumps({"inspectionUrl": url, "siteUrl": site}).encode()
    headers = {"Authorization": f"Bearer {token}", "Content-Type": "application/json"}
    if quota_project:
        headers["X-Goog-User-Project"] = quota_project
    req = urllib.request.Request(
        "https://searchconsole.googleapis.com/v1/urlInspection/index:inspect",
        data=body, headers=headers)
    with urllib.request.urlopen(req, timeout=60) as r:
        res = json.loads(r.read())
    return res["inspectionResult"]["indexStatusResult"].get("coverageState", "unknown")


def _sense_index_states(gc: dict, conn, tick_id: int,
                        site: str, token: str, quota_project: str,
                        per_tick: int = 3) -> None:
    """公開2日以上の記事を、検査が最も古いものから数本ずつURL検査する。

    結果は observations の idx_state:<slug> に 1(indexed)/0(not indexed) で残し、
    TRIAGEの not_indexed ルールとACTの再公開処置がこれを読む。
    """
    rows = conn.execute(
        "SELECT slug FROM content WHERE status='published'"
        " AND published_at <= datetime('now', 'localtime', '-2 days')"
        " AND slug NOT LIKE 'loop-weekly%' ORDER BY id").fetchall()
    last_seen = {
        r["key"][len("idx_state:"):]: r["latest"] for r in conn.execute(
            "SELECT key, MAX(created_at) latest FROM observations"
            " WHERE key LIKE 'idx_state:%' GROUP BY key")}
    # 未検査 → 検査が古い順
    targets = sorted((r["slug"] for r in rows),
                     key=lambda s: last_seen.get(s, ""))[:per_tick]
    for slug in targets:
        url = site.rstrip("/") + f"/articles/{slug}.html"
        try:
            cov = _inspect_index(site, token, quota_project, url)
        except Exception as e:
            state.record(conn, tick_id, NAME, "index_inspect_error", 1,
                         {"slug": slug, "error": str(e)[:150]})
            continue
        indexed = 1 if "indexed" in cov.lower() and "not indexed" not in cov.lower() else 0
        state.record(conn, tick_id, NAME, f"idx_state:{slug}", indexed,
                     {"coverage": cov})


def sense(cfg: dict, conn, tick_id: int) -> dict:
    gc = cfg.get("gsc", {})
    auth = None
    try:
        auth = _auth(gc)
    except Exception as e:
        state.record(conn, tick_id, NAME, "gsc_skipped", 1,
                     {"reason": f"auth error: {str(e)[:200]}"})
        return {"skipped": True}
    if auth is None:
        state.record(conn, tick_id, NAME, "gsc_skipped", 1, {"reason": "認証手段なし"})
        return {"skipped": True}
    token, quota_project = auth

    site = gc.get("property", cfg["site"].rstrip("/") + "/")
    end = time.strftime("%Y-%m-%d", time.localtime(time.time() - 2 * 86400))
    start = time.strftime("%Y-%m-%d", time.localtime(time.time() - 30 * 86400))
    rows = _query_api(site, token, quota_project, {
        "startDate": start, "endDate": end,
        "dimensions": ["query"], "rowLimit": 100,
    })

    total_impressions = sum(r["impressions"] for r in rows)
    total_clicks = sum(r["clicks"] for r in rows)
    state.record(conn, tick_id, NAME, "gsc_impressions_28d", total_impressions)
    state.record(conn, tick_id, NAME, "gsc_clicks_28d", total_clicks)

    # 追跡クエリの順位を記録し、前回観測より大きく落ちたものを検出(TRIAGE材料)
    pos_drop_th = float(gc.get("position_drop_threshold", 5))
    track_min_imp = int(gc.get("track_min_impressions", 20))
    position_drops = []
    for r in rows:
        if r["impressions"] < track_min_imp or not r.get("position"):
            continue
        q = r["keys"][0]
        key = f"q_pos:{q}"
        prev = state.latest_value(conn, key, before_tick=tick_id)
        cur = round(r["position"], 1)
        state.record(conn, tick_id, NAME, key, cur)
        if prev is not None and cur - prev >= pos_drop_th:
            position_drops.append({"query": q, "prev": prev, "cur": cur})

    # opportunity(1): 表示はあるのにクリックが取れていないクエリ → CREATEの題材へ
    min_imp = int(gc.get("opportunity_min_impressions", 20))
    opportunities = [r for r in rows
                     if r["impressions"] >= min_imp and r.get("ctr", 0) < 0.02]
    opportunities.sort(key=lambda r: -r["impressions"])
    added = 0
    for r in opportunities[:10]:
        q = r["keys"][0]
        if not _is_writable_query(q):
            continue
        if _add_research(
                conn, tick_id, "gsc_opportunity",
                f"検索クエリ「{q}」の解説記事",
                f"gsc://query/{urllib.parse.quote(q)}",
                f"GSCで28日間に表示{r['impressions']}回・クリック{r['clicks']}回(CTR {r.get('ctr', 0):.1%})。"
                f"このクエリで検索した読者の疑問に答える記事を書く。",
                10.0 + r["impressions"] / 100):  # RSSより高スコア=優先
            added += 1

    # opportunity(2): 2ページ目(11〜20位)の高表示クエリ = あと一押しで1ページ目。
    # 最優先スコアを付けて記事化を促す。
    band_min_imp = int(gc.get("position_band_min_impressions", 10))
    band = [r for r in rows
            if r["impressions"] >= band_min_imp
            and 11 <= r.get("position", 0) <= 20]
    band.sort(key=lambda r: -r["impressions"])
    band_added = 0
    for r in band[:10]:
        q = r["keys"][0]
        if not _is_writable_query(q):
            continue
        if _add_research(
                conn, tick_id, "gsc_opportunity",
                f"検索クエリ「{q}」の解説記事",
                f"gsc://query/{urllib.parse.quote(q)}",
                f"GSCで28日間に表示{r['impressions']}回・平均順位{r['position']:.1f}(2ページ目)。"
                f"1ページ目まであと一歩のクエリ。検索意図に正面から答える記事で押し上げる。",
                12.0 + r["impressions"] / 100):
            band_added += 1
    conn.commit()
    state.record(conn, tick_id, NAME, "gsc_opportunities_added", added + band_added)
    state.record(conn, tick_id, NAME, "gsc_band_opportunities_added", band_added)

    # 記事ページ別の成績計測 + リライト候補(失敗しても全体は続行)
    article_metrics = []
    try:
        article_metrics = _sense_article_metrics(
            cfg, gc, conn, tick_id, site, token, quota_project, start, end)
    except Exception as e:
        state.record(conn, tick_id, NAME, "article_metrics_error", 1,
                     {"error": str(e)[:200]})

    # 記事のインデックス状態を巡回検査(1tick数本・検査が古い記事から)。
    # 「クロール済み・未インデックス」をTRIAGEが課題化できるようにする。
    try:
        _sense_index_states(gc, conn, tick_id, site, token, quota_project)
    except Exception as e:
        state.record(conn, tick_id, NAME, "index_inspect_error", 1,
                     {"error": str(e)[:200]})

    return {"impressions_28d": total_impressions, "clicks_28d": total_clicks,
            "opportunities_added": added + band_added,
            "band_opportunities_added": band_added,
            "position_drops": position_drops,
            "article_metrics": article_metrics}
