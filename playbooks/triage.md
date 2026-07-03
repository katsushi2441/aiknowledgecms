# Playbook: TRIAGE

目的: 観測値を優先度つきの課題キュー(issues)へ変換する。

手順:
1. loopfile.yaml の `thresholds` に従いルールを適用する
2. issueは fingerprint で重複排除する。同じ課題は last_seen_tick を更新するだけ
3. 条件が解消した課題は resolved に遷移させる(勝手に消さない・履歴を残す)
4. severity は critical(サイト機能が壊れている) / warning(劣化・注意) / info

Phase 1 のルール:
- 監視ページが非2xx/3xx → critical `page_down:<page>`
- 応答が slow_page_ms 超 → warning `page_slow:<page>`
- 24h PVが前tick比 pv_drop_pct% 超下落(前値20PV以上のとき) → warning `pv_drop`

エスカレーション:
- criticalが新規に開いたら loopfile の escalation.email へ通知する
