"""Loopfile (loopfile.yaml) の読み込みと env の解決。"""
from __future__ import annotations

from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[1]


def load(path: str | Path | None = None) -> dict:
    p = Path(path) if path else ROOT / "loopfile.yaml"
    cfg = yaml.safe_load(p.read_text(encoding="utf-8"))
    if not isinstance(cfg, dict) or "site" not in cfg:
        raise ValueError(f"invalid loopfile: {p}")
    cfg["_path"] = str(p)
    cfg["_env"] = _load_env(cfg.get("env_file"))
    return cfg


def _load_env(path: str | None) -> dict:
    env: dict = {}
    if not path or not Path(path).exists():
        return env
    for line in Path(path).read_text(encoding="utf-8", errors="ignore").splitlines():
        line = line.strip()
        if line and not line.startswith("#") and "=" in line:
            k, v = line.split("=", 1)
            env[k.strip()] = v.strip()
    return env
