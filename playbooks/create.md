# Playbook: CREATE

目的: 未使用の素材から、素材に忠実な考察記事を1本生成する。

手順:
1. 日次予算を確認(articles_per_day / max_attempts_per_day)。超過なら何もしない
2. 未使用素材をscore順に3件選び、生成エージェント(generator)に渡す
3. 出力契約: TITLE / SLUG / --- / 本文markdown。契約違反はパース失敗として破棄
4. 生成物は必ず品質ゲート(gates/verify_article)を通す
   - ルール検査: 長さ・slug・URLホワイトリスト・参考セクション
   - 検証エージェント(verifier・生成者とは別モデル)が素材への忠実性を判定
5. ゲート不合格は status=rejected で台帳に残す(黙って捨てない)

原則: 素材にない事実・数字・日付を書かせない。不合格の理由は必ず記録する。
