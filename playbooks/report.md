# Playbook: REPORT

目的: tickの観測・判断・結果を人間が監査できる形で公開する。

手順:
1. reports/tick-<n>.md にMarkdownで保存する(ローカル・gitignore)
2. HTML化して publisher.remote_dir (= サイトの /loop/) に公開する
   - tick-<n>.html (恒久) / latest.html (最新) / index.html (一覧)
3. dry-run のときは公開しない

原則:
- レポートには観測値・開閉したissue・現在のキューを必ず含める(隠さない)
- 失敗したセンサも失敗として載せる
