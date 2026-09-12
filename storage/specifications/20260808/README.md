# 入荷API v2 アプリ検品・EOS連携 資料

作成日: 2026-08-08

## 資料一覧

| ファイル | 内容 |
| --- | --- |
| `incoming-api-v2-eos-inspection-spec.md` | v2 APIの開発仕様、データフロー、テーブル案、例外処理 |
| `handy-incoming-api-v2-spec.md` | HANDYアプリ向けの開発要件、API仕様、ユースケース、通信手順 |
| `incoming-api-v2-test-cases.md` | スナップショット、EOS、非EOS、予定なし、数量、再送、既存影響のテストケース |
| `incoming-api-v2-progress.md` | 作業進捗、実装順序、未確定事項、ロールバック観点 |
| `incoming-api-v2-review.md` | 現行コードとの整合性確認、資料レビュー、リスク一覧 |

## 確定済みの主要方針

- 新APIは `/api/v2/incoming`
- 既存 `/api/incoming/*` は修正しない
- EOS対象のアプリ検品は履歴のみ保存
- EOS確定済み照合用データは検品日を含む過去3日分
- 予定なし入荷は `APP_UNPLANNED` / `予定なし入荷`
- 商品マスタは `/api/v2/incoming/item-master` で倉庫単位に1日1回取得し、端末に永続キャッシュする。ただし倉庫で取扱可能な商品に限定
- WMSに `入荷 > アプリ入荷検品履歴` を追加する

## 実装開始前の必須確認

1. `release/v1.0` から新ブランチを作成する
2. `order_source` ENUM変更のDDLリスクを確認する
3. 倉庫で取扱可能な商品のSQL定義を確定する
4. `APP_UNPLANNED` の仕入連携対象としての扱いを本番データで確認する
5. 既存APIとEOS自動連携の回帰テストを実施する
