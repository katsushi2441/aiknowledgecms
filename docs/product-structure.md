# AIKnowledgeCMS product structure

AIKnowledgeCMSは、単体CMSではなく「AIが知識を集め、蓄積し、人が読める入口へ出す」プロダクトとして整理する。

## 役割

- `url2ai`
  - 知識収集エンジン
  - OSS、Zenn、FinReport、Polymarketなどを自律的に収集・解析する

- `data/`
  - 生成されたJSONの置き場
  - ローカル生成データ・作業用データとしてGit管理しない

- `aiknowledgecms.php`
  - 既存CMSとして現状維持
  - キーワード、日次知識、蓄積された知識の管理面

- `aiknowledgesns.php`
  - 人が読む入口
  - 発信・投稿機能は当面持たせない
  - knowradar的なポータル、カテゴリ、タイムライン、アカウント一覧を担う

- `airadarx.php`
  - 参加型、X連携、拡散要素を持つ別プロダクト
  - 現時点ではAIKnowledgeSNSに統合しない

## aiknowledgesns.php の方針

- URL2AIのUIトーンに寄せる
- ログイン前提にしない
- 投稿、いいね、アソシエイト設定などのSNS機能は入れない
- `data/keyword_*.json` からアカウント一覧を表示する
- `data/oss_posts.json`、`data/oss_*.json`、`data/finreport_*.json`、`data/polymarket_*.json` を横断表示する
- 「裏側でurl2aiが集め、表側でAIKnowledgeSNSが読ませる」構造を明確にする
