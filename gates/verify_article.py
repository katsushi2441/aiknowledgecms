"""品質ゲート — 生成者とは別のエージェントが記事を検査する。

2段構え:
  1. ルール検査(決定的): 長さ・タイトル・slug・URLホワイトリスト
  2. 検証エージェント(別モデル): 素材への忠実性を判定 (VERDICT: PASS/FAIL)
どちらかに落ちたら公開しない。判定の詳細はcontent.gate_resultに残す。
"""
from __future__ import annotations

import json
import re
import urllib.request

DEFAULT_OLLAMA = "http://127.0.0.1:11434"


def rule_checks(draft: dict) -> list[str]:
    problems = []
    title, slug, body = draft["title"], draft["slug"], draft["body"]
    if not (10 <= len(title) <= 80):
        problems.append(f"タイトル長が不正: {len(title)}字")
    if not re.fullmatch(r"[a-z0-9\-]{8,50}", slug):
        problems.append(f"slugが不正: {slug}")
    if len(body) < 500:
        problems.append(f"本文が短すぎる: {len(body)}字")
    if len(body) > 6000:
        problems.append(f"本文が長すぎる: {len(body)}字")
    allowed = set(draft["sources"])
    # URLはASCII印字可能文字のみ(全角文字で必ず終端し、日本語の巻き込みを防ぐ)
    for url in re.findall(r"https?://[!-~]+", body):
        url = url.rstrip(".,;:)\"'）")
        if url not in allowed:
            problems.append(f"素材にないURLを引用: {url}")
    if "## 参考" not in body and "##参考" not in body:
        problems.append("参考セクションがない")
    return problems


def verifier_agent(cfg: dict, draft: dict, sources_text: str, timeout: int = 600) -> dict:
    vcfg = cfg["create"]["verifier"]
    prompt = f"""あなたは記事の検証担当です。生成担当とは独立に、以下の記事が素材に忠実かを検査してください。

# 素材
{sources_text}

# 記事タイトル
{draft['title']}

# 記事本文
{draft['body']}

# 検査項目
1. 素材にない具体的事実・数字・日付・固有名詞の断定がないか(一般論・可能性の表現は許容)
2. 素材の内容を歪めていないか
3. 日本語として公開に耐える品質か

# 出力形式(厳守・1行目に必ずVERDICT)
VERDICT: PASS または FAIL
REASON: <100字以内の理由>
"""
    api = cfg["create"].get("ollama_api", DEFAULT_OLLAMA).rstrip("/") + "/api/generate"
    req = urllib.request.Request(
        api,
        data=json.dumps({
            "model": vcfg["model"], "prompt": prompt, "stream": False,
            "think": False,  # 思考型モデル対策(隠れ推論で空応答になるのを防ぐ)
            "options": {"temperature": 0.1, "num_predict": 256},
        }).encode(),
        headers={"Content-Type": "application/json"},
    )
    with urllib.request.urlopen(req, timeout=timeout) as r:
        text = json.loads(r.read())["response"]
    m = re.search(r"VERDICT:\s*(PASS|FAIL)", text)
    reason = ""
    rm = re.search(r"REASON:\s*(.+)", text)
    if rm:
        reason = rm.group(1).strip()[:200]
    return {"verdict": m.group(1) if m else "FAIL",
            "reason": reason or text[:200],
            "model": vcfg["model"]}


def run(cfg: dict, conn, draft: dict) -> dict:
    """ゲート実行。結果dict {passed, problems, verifier} を返しDBに記録する。"""
    problems = rule_checks(draft)
    result: dict = {"problems": problems}
    if problems:
        result["passed"] = False
        result["verifier"] = None
    else:
        # 送客計測のref=はresearch.urlの台帳(正規形)には無いので外して引く
        lookup = [re.sub(r"[?&]ref=[\w-]+", "", u) for u in draft["sources"]]
        rows = conn.execute(
            "SELECT title, url, summary FROM research WHERE url IN ({})".format(
                ",".join("?" * len(lookup))), lookup).fetchall()
        sources_text = "\n".join(
            f"- {r['title']} ({r['url']})\n  {r['summary'] or ''}" for r in rows)
        v = verifier_agent(cfg, draft, sources_text)
        result["verifier"] = v
        result["passed"] = v["verdict"] == "PASS"
    conn.execute(
        "UPDATE content SET gate_result=?, status=? WHERE slug=?",
        (json.dumps(result, ensure_ascii=False),
         "draft" if result["passed"] else "rejected", draft["slug"]),
    )
    conn.commit()
    return result
