"""Loop memory — SQLite に tick / observations / issues を永続化する。

すべての判断材料と結果がここに残り、監査できることがフレームワークの要件。
"""
from __future__ import annotations

import json
import sqlite3
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DATA = ROOT / "data"
DB_PATH = DATA / "loop.sqlite"

SCHEMA = """
CREATE TABLE IF NOT EXISTS ticks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  started_at TEXT NOT NULL,
  finished_at TEXT,
  dry_run INTEGER NOT NULL DEFAULT 0,
  summary TEXT
);
CREATE TABLE IF NOT EXISTS observations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tick_id INTEGER NOT NULL,
  sensor TEXT NOT NULL,
  key TEXT NOT NULL,
  value REAL,
  meta TEXT,
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_obs_tick ON observations(tick_id);
CREATE INDEX IF NOT EXISTS idx_obs_key ON observations(key, id);
CREATE TABLE IF NOT EXISTS research (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tick_id INTEGER NOT NULL,
  source TEXT NOT NULL,
  title TEXT NOT NULL,
  url TEXT NOT NULL UNIQUE,
  summary TEXT,
  score REAL NOT NULL DEFAULT 0,
  used INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS content (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  slug TEXT NOT NULL UNIQUE,
  title TEXT NOT NULL,
  status TEXT NOT NULL,               -- draft / published / rejected
  body_md TEXT,
  sources TEXT,                       -- json: 参照したresearch URL群
  gate_result TEXT,                   -- json: ゲート判定の詳細
  created_tick INTEGER NOT NULL,
  published_at TEXT,
  created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS issues (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  fingerprint TEXT NOT NULL UNIQUE,
  severity TEXT NOT NULL,             -- critical / warning / info
  title TEXT NOT NULL,
  detail TEXT,
  status TEXT NOT NULL DEFAULT 'open', -- open / resolved
  first_seen_tick INTEGER NOT NULL,
  last_seen_tick INTEGER NOT NULL,
  resolved_tick INTEGER,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);
"""


def now() -> str:
    return time.strftime("%Y-%m-%d %H:%M:%S")


def connect() -> sqlite3.Connection:
    DATA.mkdir(exist_ok=True)
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA journal_mode=WAL")
    conn.executescript(SCHEMA)
    return conn


def begin_tick(conn: sqlite3.Connection, dry_run: bool) -> int:
    cur = conn.execute(
        "INSERT INTO ticks (started_at, dry_run) VALUES (?, ?)",
        (now(), 1 if dry_run else 0),
    )
    conn.commit()
    return cur.lastrowid


def finish_tick(conn: sqlite3.Connection, tick_id: int, summary: str) -> None:
    conn.execute(
        "UPDATE ticks SET finished_at = ?, summary = ? WHERE id = ?",
        (now(), summary, tick_id),
    )
    conn.commit()


def record(conn, tick_id: int, sensor: str, key: str, value=None, meta=None) -> None:
    conn.execute(
        "INSERT INTO observations (tick_id, sensor, key, value, meta, created_at)"
        " VALUES (?, ?, ?, ?, ?, ?)",
        (tick_id, sensor, key,
         None if value is None else float(value),
         json.dumps(meta, ensure_ascii=False) if meta is not None else None,
         now()),
    )


def latest_value(conn, key: str, before_tick: int | None = None):
    """前tickまでの最新観測値(トレンド比較用)。"""
    q = "SELECT value FROM observations WHERE key = ?"
    args: list = [key]
    if before_tick is not None:
        q += " AND tick_id < ?"
        args.append(before_tick)
    q += " ORDER BY id DESC LIMIT 1"
    row = conn.execute(q, args).fetchone()
    return None if row is None else row["value"]


def value_before(conn, key: str, cutoff: str):
    """cutoff時刻("YYYY-mm-dd HH:MM:SS")以前の最新観測値(24h前比較などに使う)。"""
    row = conn.execute(
        "SELECT value FROM observations WHERE key = ? AND created_at <= ?"
        " ORDER BY id DESC LIMIT 1", (key, cutoff)).fetchone()
    return None if row is None else row["value"]


def open_issue(conn, tick_id: int, fingerprint: str, severity: str,
               title: str, detail: str) -> bool:
    """issueを開く/継続する。新規に開いた場合 True を返す。"""
    row = conn.execute(
        "SELECT id, status FROM issues WHERE fingerprint = ?", (fingerprint,)
    ).fetchone()
    if row is None:
        conn.execute(
            "INSERT INTO issues (fingerprint, severity, title, detail,"
            " first_seen_tick, last_seen_tick, created_at, updated_at)"
            " VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            (fingerprint, severity, title, detail, tick_id, tick_id, now(), now()),
        )
        return True
    if row["status"] == "resolved":
        conn.execute(
            "UPDATE issues SET status='open', severity=?, detail=?,"
            " last_seen_tick=?, resolved_tick=NULL, updated_at=? WHERE id=?",
            (severity, detail, tick_id, now(), row["id"]),
        )
        return True
    conn.execute(
        "UPDATE issues SET detail=?, last_seen_tick=?, updated_at=? WHERE id=?",
        (detail, tick_id, now(), row["id"]),
    )
    return False


def resolve_issue(conn, tick_id: int, fingerprint: str) -> bool:
    """条件が解消したissueを閉じる。閉じた場合 True。"""
    cur = conn.execute(
        "UPDATE issues SET status='resolved', resolved_tick=?, updated_at=?"
        " WHERE fingerprint=? AND status='open'",
        (tick_id, now(), fingerprint),
    )
    return cur.rowcount > 0


def open_issues(conn):
    return conn.execute(
        "SELECT * FROM issues WHERE status='open' ORDER BY "
        "CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END, id"
    ).fetchall()


def tick_observations(conn, tick_id: int):
    return conn.execute(
        "SELECT * FROM observations WHERE tick_id=? ORDER BY id", (tick_id,)
    ).fetchall()
