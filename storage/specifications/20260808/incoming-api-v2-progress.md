# 入荷API v2 作業進捗管理

作成日: 2026-08-08

## 現在の状態

ステータス: 初期実装完了・ランタイム検証待ち

コード実装: v2 API・履歴テーブル・履歴画面の初期実装まで完了

検証状況: PHP構文チェックと差分チェックは完了。現在のworktreeに `vendor/autoload.php` がないため、`artisan route:list`、migration status、Filament画面表示、API実行テストは未実施。

ブランチ: `codex/incoming-api-v2-eos-inspection`

## 作業ブランチ予定

```text
codex/incoming-api-v2-eos-inspection
```

## 進捗チェックリスト

| No | 作業 | 状態 | リスク | 検証方法 | ロールバック |
| --- | --- | --- | --- | --- | --- |
| 1 | 仕様書作成 | 完了 | 低 | 資料レビュー | 資料修正 |
| 2 | テストケース作成 | 完了 | 低 | ケース網羅レビュー | 資料修正 |
| 3 | 既存コード整合性確認 | 完了 | 低 | コード参照一覧確認 | 資料修正 |
| 4 | ブランチ作成 | 完了 | 低 | `git status --branch` | ブランチ削除 |
| 5 | DB設計確定 | 完了 | 中 | migration案レビュー | migration未適用なら破棄 |
| 6 | `APP_UNPLANNED` Enum追加 | 完了 | 高 | 既存画面・既存APIの表示確認 | Enum追加コミットをrevert |
| 7 | 検品履歴テーブル追加 | 完了 | 中 | migration status、index確認 | 追加テーブルdrop |
| 8 | v2ルート追加 | 完了 | 低 | `/api/v2/incoming/item-master` と `/api/v2/incoming/snapshot` ルート確認 | ルート削除 |
| 9 | スナップショットサービス追加 | 完了 | 中 | 本番相当件数でレスポンス時間確認 | サービス未使用化 |
| 10 | 同期サービス追加 | 完了 | 高 | 冪等性・競合テスト | v2同期ルート無効化 |
| 11 | 非EOS確定連携 | 完了 | 高 | 確定・分納・超過テスト | v2確定分岐無効化 |
| 12 | EOS履歴のみ処理 | 完了 | 高 | EOS対象で入荷予定が更新されないこと | v2同期ルート無効化 |
| 13 | WMS履歴画面追加 | 完了 | 中 | 一覧・詳細・フィルタ確認 | Resource削除 |
| 14 | 既存API回帰テスト | 未実施 | 高 | `/api/incoming/*` の既存テスト | v2変更をrevert |
| 15 | EOS自動連携回帰テスト | 未実施 | 高 | JX受信から適用まで確認 | v2変更をrevert |

## 実装順序

1. `release/v1.0` から新ブランチ作成
2. `OrderSource` 追加方針とDDLリスクを最終確認
3. 検品履歴テーブルのmigration作成
4. v2 Controller / Service / Resource の空枠追加
5. スナップショット取得サービス作成
6. 同期受信と履歴保存を実装
7. EOS対象は履歴のみになることを実装
8. 非EOS確定・予定なし作成を実装
9. WMS「アプリ入荷検品履歴」画面を追加
10. テストケースに沿って確認

## 実装時の重要ルール

- 既存 `/api/incoming/*` は修正しない
- EOS対象では `IncomingConfirmationService` を呼ばない
- アプリから予定なしとして来ても、同期時に必ずサーバ側で再照合する
- 同じ `device_id + client_batch_uuid` は重複処理しない
- 同じ `batch_id + client_line_uuid` は重複処理しない
- 自動判定できないものは新規入荷予定を作らず `NEEDS_REVIEW`
- `purchase_queue_id` 設定済みは更新しない
- `TRANSMITTED` は更新しない

## 未確定事項

| 項目 | 現在案 | 確認内容 |
| --- | --- | --- |
| 倉庫で取扱可能な商品の定義 | デフォルトロケあり OR 在庫実績あり | 業務上これで過不足ないか |
| `APP_UNPLANNED` の仕入連携対象 | 仕入連携対象に含める | `withoutTransferSource()` と仕入連携選択可否へ追加済み |
| 検品者の主キー | user_id + 任意 picker_id | API認証ユーザを主、picker_idがあればピッカーとして保存 |
| 伝票番号なし予定なし入荷 | 自動採番 | `WmsOrderIncomingSchedule::generateSlipNumber()` を利用 |
| 商品不明のWeb修正 | 初期は履歴表示のみ | 商品確定導線が必要か |

## レビュー履歴

| 日時 | 内容 | 結果 |
| --- | --- | --- |
| 2026-08-08 | 初回仕様整理 | 仕様・テストケース・レビュー資料作成 |
| 2026-08-08 | 初期実装 | v2 API、履歴保存、予定なし入荷作成、WMS履歴一覧を追加 |
| 2026-08-08 | 静的検証 | 変更PHPの `php -l` と `git diff --check` は成功。artisan系はvendor未配置で未実施 |
| 2026-08-08 | API仕様コメント追加 | `IncomingV2Controller` に既存APIと同じOpenAPIコメントを追加 |
| 2026-08-08 | 仮想倉庫対応 | 作業倉庫はリクエスト倉庫のまま、入荷予定・EOS確定済み照合対象を同一実倉庫配下へ拡張 |
| 2026-08-08 | HANDY向け仕様書追加 | HANDYアプリ開発者向けに、開発要件・API仕様・ユースケース・表示要件を別資料化 |
| 2026-08-09 | 商品マスタ同期方針変更 | スナップショットから商品マスタ全件を外し、`/api/v2/incoming/item-master` の日次端末キャッシュ方式へ変更 |
