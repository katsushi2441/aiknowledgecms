"""aixsns アナウンサ — 公開した記事をAIxSNSへ告知する。"""
from __future__ import annotations

import json
import urllib.request


def announce(cfg: dict, title: str, url: str) -> bool:
    ac = cfg.get("announcer", {})
    if ac.get("kind") != "aixsns":
        return False
    body = (f"【Loop自動公開】{title}\n\n"
            f"AIKnowledgeCMSのエージェントループが収集→生成→検証→公開まで自律実行した記事です🤖\n"
            f"記事: {url}\n"
            f"ループの実行記録: {cfg['site'].rstrip('/')}/loop/")
    req = urllib.request.Request(
        ac["api"],
        data=json.dumps({"author": ac.get("author", "codex"),
                         "content": body}).encode(),
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=30) as r:
        return r.status in (200, 201)
