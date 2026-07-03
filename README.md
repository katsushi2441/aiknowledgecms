# AIKnowledgeCMS

**A CMS operated by an agent loop, not by you.**

AIKnowledgeCMS is a loop-engineering framework for website growth.
An AI agent runs an endless growth loop over a knowledge site — sensing its health,
researching what matters, triaging issues, acting on them, creating and distributing
content, then feeding every result back into the next cycle.

There is no admin panel. There is a loop.

- 🇬🇧 https://aiknowledgecms.exbridge.jp/
- 🇯🇵 https://aiknowledgecms.exbridge.jp/aiknowledgecms.html
- 📊 Live loop reports (dogfooding): https://aiknowledgecms.exbridge.jp/loop/

## The Growth Loop

One cycle is a resumable **tick** — not a runaway `while(true)`:

```
SENSE → RESEARCH → TRIAGE → ACT → CREATE → DISTRIBUTE
  ↑                                            |
  └──────────────── MEASURE ←──────────────────┘
```

| Stage | What happens | Status |
|---|---|---|
| SENSE | Analytics / Search Console / access logs / uptime become structured observations | ✅ Phase 1 |
| RESEARCH | Collector adapters gather trends and sources | ✅ Phase 2 |
| TRIAGE | Observations become a prioritized issue queue | ✅ Phase 1 (rule-based) |
| ACT | Playbook-driven fixes; site changes land as worktree PRs | Phase 3 |
| CREATE | Content generation behind quality gates (separate verifier agent) | ✅ Phase 2 |
| DISTRIBUTE | Publisher / announcer adapters ship the output | ✅ Phase 2 (articles + reports + AIxSNS) |

## Quick start (Phase 1)

```bash
# 1. Declare your site's loop
$EDITOR loopfile.yaml

# 2. One tick, no side effects
python3 -m core.loop --dry-run

# 3. Live tick: sense → triage → publish the loop report
python3 -m core.loop

# 4. Run it forever (hourly)
cp systemd/aiknowledgecms-loop.{service,timer} ~/.config/systemd/user/
systemctl --user enable --now aiknowledgecms-loop.timer
```

Requirements: Python 3.10+, PyYAML. No other dependencies — memory is SQLite.

## Loopfile

The whole loop — KPIs, cadence, budgets, gates, escalation — is declared in one file:

```yaml
site: https://aiknowledgecms.exbridge.jp
kpi: [pv_24h, uniq_ips_24h, pages_healthy]
sensors: [http_health, simpletrack]
thresholds: { pv_drop_pct: 30, slow_page_ms: 4000 }
publisher: { kind: ftp, remote_dir: /web/.../loop }
escalate_when: [critical_issue_opened]
escalation: { email: you@example.com }
```

## Guardrails — loops amplify judgment

Brakes are part of the spec, not bolted on:

- **Kill switch** — `touch data/KILL` halts the loop; state is persisted, resume is safe
- **Dry-run** — full reasoning, zero side effects
- **Budgets** — hard caps per tick (LLM cost, output volume)
- **Escalation** — declared conditions page a human by email; verification stays human

## Repository layout

```
loopfile.yaml        # declarative loop definition (one per site)
core/
  loop.py            # tick runner: SENSE → TRIAGE → REPORT
  state.py           # SQLite memory: ticks / observations / issues
  loopfile.py        # loopfile loader
adapters/sensors/    # http_health, simpletrack (pluggable)
playbooks/           # first-class procedures per stage
systemd/             # hourly timer units
reports/             # per-tick markdown reports (generated)
legacy/              # previous AIGM Ecosystem site (pre-framework)
```

## Dogfooding

aiknowledgecms.exbridge.jp is the framework's reference deployment.
The loop that runs this repository publishes its own execution reports at
[/loop/](https://aiknowledgecms.exbridge.jp/loop/) — the framework's proof is the site itself.

## Roadmap

- **Phase 0** — Concept & spec (done)
- **Phase 1** — Core tick runner + SENSE / TRIAGE / REPORT (done, running hourly)
- **Phase 2** — CREATE / DISTRIBUTE adapters + quality gates ← **you are here**
  (first gated article published autonomously: [/articles/](https://aiknowledgecms.exbridge.jp/articles/))
- **Phase 3** — ACT via worktrees + shareable growth cards / dashboards

## License

MIT (see LICENSE)
