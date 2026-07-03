# Playbook: DISTRIBUTE

目的: ゲート通過記事を公開し、告知する。

手順:
1. /articles/<slug>.html と /articles/index.html をFTP公開
2. 記事ページには「エージェントループが自動生成・検証済み」の表示と
   使用モデル(creator/verifier)を必ず明記する(透明性)
3. AIxSNSへ告知(announcer設定)。告知失敗は記録するが公開は取り消さない
4. 使用した素材はused=1にして再利用しない
