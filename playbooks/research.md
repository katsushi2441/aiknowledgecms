# Playbook: RESEARCH

目的: ニッチ(AIエージェント経済)に合う素材を継続的に蓄積する。

手順:
1. loopfile.yaml `research.feeds` のRSS/Atomのみから取得する
2. `niche_keywords` に1語もマッチしないアイテムは保存しない
3. URLで重複排除。scoreはキーワードヒット数
4. 1tickの追加は `max_items_per_tick` まで(予算)

禁止事項: フィード以外のスクレイピング、本文の全文取得(タイトル+概要のみ)
