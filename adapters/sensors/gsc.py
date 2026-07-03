"""gsc センサ — Google Search Console の検索アナリティクスを観測する。

集客ループの心臓部: 「表示回数はあるがクリックが少ないクエリ」を
opportunity として research テーブルに流し込み、CREATE のテーマ選定に使う。

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
    body = json.dumps({
        "startDate": start, "endDate": end,
        "dimensions": ["query"], "rowLimit": 100,
    }).encode()
    url = ("https://www.googleapis.com/webmasters/v3/sites/"
           + urllib.parse.quote(site, safe="") + "/searchAnalytics/query")
    headers = {"Authorization": f"Bearer {token}", "Content-Type": "application/json"}
    if quota_project:
        headers["X-Goog-User-Project"] = quota_project
    req = urllib.request.Request(url, data=body, headers=headers)
    with urllib.request.urlopen(req, timeout=60) as r:
        rows = json.loads(r.read()).get("rows", [])

    total_impressions = sum(r["impressions"] for r in rows)
    total_clicks = sum(r["clicks"] for r in rows)
    state.record(conn, tick_id, NAME, "gsc_impressions_28d", total_impressions)
    state.record(conn, tick_id, NAME, "gsc_clicks_28d", total_clicks)

    # opportunity: 表示はあるのにクリックが取れていないクエリ → CREATEの題材へ
    min_imp = int(gc.get("opportunity_min_impressions", 20))
    opportunities = [r for r in rows
                     if r["impressions"] >= min_imp and r.get("ctr", 0) < 0.02]
    opportunities.sort(key=lambda r: -r["impressions"])
    added = 0
    for r in opportunities[:10]:
        q = r["keys"][0]
        try:
            conn.execute(
                "INSERT INTO research (tick_id, source, title, url, summary, score, created_at)"
                " VALUES (?, 'gsc_opportunity', ?, ?, ?, ?, ?)",
                (tick_id,
                 f"検索クエリ「{q}」の解説記事",
                 f"gsc://query/{urllib.parse.quote(q)}",
                 f"GSCで28日間に表示{r['impressions']}回・クリック{r['clicks']}回(CTR {r.get('ctr', 0):.1%})。"
                 f"このクエリで検索した読者の疑問に答える記事を書く。",
                 10.0 + r["impressions"] / 100,  # RSSより高スコア=優先
                 state.now()),
            )
            added += 1
        except Exception:
            pass  # 既出クエリはスキップ
    conn.commit()
    state.record(conn, tick_id, NAME, "gsc_opportunities_added", added)
    return {"impressions_28d": total_impressions, "clicks_28d": total_clicks,
            "opportunities_added": added}
