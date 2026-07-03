# Playbook: SENSE

目的: サイトの状態を観測値としてループのメモリ(SQLite observations)に取り込む。

手順:
1. loopfile.yaml の `sensors` に列挙されたセンサのみを実行する
2. センサは観測値を `state.record()` で記録する。判断はしない(判断はTRIAGEの仕事)
3. センサが失敗しても他のセンサとtickは続行する。失敗は `sensor_error` として記録する

禁止事項:
- 観測時に外部への書き込みを行わない(SENSEは読み取り専用)
- 生ログ全量のダウンロード(access.logは末尾 tail_bytes のみ)
