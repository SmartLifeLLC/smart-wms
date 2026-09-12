# 入荷API v2 資料レビュー・現行コード整合性確認

作成日: 2026-08-08

## レビュー結果

結論:

仕様は現行コードの責務分離と整合している。

ただし、`APP_UNPLANNED` の追加は `wms_order_incoming_schedules.order_source` のENUM変更を伴うため、実装前にDDLリスク確認が必要。

## 確認した主なコード

| ファイル | 確認内容 |
| --- | --- |
| `routes/api.php` | 既存入荷APIは `/api/incoming/*`。v2追加余地あり |
| `app/Http/Controllers/Api/IncomingController.php` | 既存APIは作業完了時に入荷予定を直接更新する |
| `app/Models/WmsOrderIncomingSchedule.php` | EOS対象判定 `isEosSent()` / `scopeEosSent()` が存在 |
| `app/Models/WmsIncomingWorkItem.php` | 既存作業テーブルはv1作業状態用で、v2履歴用途には不足 |
| `app/Enums/AutoOrder/OrderSource.php` | 既存値は `AUTO`, `MANUAL`, `TRANSFER`, `RECEIVED` |
| `app/Services/AutoOrder/IncomingConfirmationService.php` | 非EOS確定に利用可能。EOS対象では呼ばない設計が必要 |
| `app/Services/JX/Eos/JxEosIncomingWorkflowService.php` | EOS取込・照合・適用の既存責務を確認 |
| `app/Http/Controllers/Api/MasterDataController.php` | 商品検索・JAN・ロケ取得の既存実装を確認 |
| `app/Services/OutboundInspection/OutboundInspectionSnapshotService.php` | オフライン用スナップショット形式の参考実装 |

## 整合性確認

### 既存APIを修正しない方針

整合している。

現行 `/api/incoming/*` は `IncomingController` が直接担当している。新規ルートを `/api/v2/incoming/*` に追加すれば、既存APIの挙動を変えずに実装できる。

### EOS対象は履歴のみ保存

整合している。

現行のEOS自動連携は `JxEosIncomingWorkflowService::importAndApply()` が `IncomingReceiveService::applyMatched()` まで実行する。v2側でEOS対象を直接確定すると二重責務になるため、履歴のみ保存する設計が正しい。

### EOS確定済みの重複作成防止

仕様上の追加対応が必要。

現行APIは `PENDING` / `PARTIAL` の予定を取得し、作業完了時に確定する。EOS確定済みの軽量インデックスをアプリに返す仕組みは現行APIにはない。

v2で「過去3日分のEOS確定済み照合用データ」と「同期時のサーバ側再照合」を追加する必要がある。

### `APP_UNPLANNED`

追加対応が必要。

現在の `OrderSource` は以下のみ。

- `AUTO`
- `MANUAL`
- `TRANSFER`
- `RECEIVED`

予定なし入荷を `RECEIVED` に混ぜると、JX受信由来の不明伝票と区別できなくなるため、新値 `APP_UNPLANNED` の追加は妥当。

注意点:

- `OrderSource` Enumの追加
- `wms_order_incoming_schedules.order_source` ENUMのDDL変更
- `match` 表示を使っているFilamentテーブルの追加対応
- `IncomingConfirmationService::resolvePurchaseSlipNumber()` の許可リストに入れる
- 仕入連携対象に含める

### 商品マスタ全件取得

部分的に既存実装を流用可能。

`MasterDataController::itemLocations()` は商品検索型であり、倉庫全体の取扱可能商品を一括返却するAPIではない。

ただし、以下のロジックは流用できる。

- `item_search_information` の取得
- `item_quantity_information` の取得
- `item_incoming_default_locations` の取得
- ロケーション整形

倉庫で取扱可能な商品の定義は新規サービスで明確にする必要がある。

### 数量換算

既存ロジックと整合している。

`WmsOrderIncomingSchedule::quantityAsPieces()` は `quantity_type` と `capacity_case` / `capacity_carton` で総バラ換算する。

EOS受信側では `IncomingReceivedQuantityNormalizer` がJANに紐づく入数を見て総数を正規化している。

v2でも履歴にはケース数・バラ数・総バラ数・スキャンコード入数を保存し、比較は総バラ基準にする必要がある。

### 分納・超過

仕様上の注意が必要。

現行 `IncomingConfirmationService::recordPartialIncoming()` は、非EOSで条件を満たす場合に入荷完了データを分割作成するロジックを持つ。

v2ではユーザ要件に合わせて、非EOSの予定数量未満は `PARTIAL` 継続ではなく「一部欠品として完了」とする。既存サービスをそのまま呼ぶと `PARTIAL` になる可能性があるため、v2用の確定サービスで明示的に分岐する必要がある。

### 仕入連携済みデータ

整合している。

既存仕様でも `purchase_queue_id` 設定済みや `TRANSMITTED` は修正不可扱い。v2でも更新せず履歴のみ保存または `NEEDS_REVIEW` とする。

## リスク一覧

| リスク | 内容 | 対策 |
| --- | --- | --- |
| ENUM DDL | `order_source` 追加でテーブルロックの可能性 | 本番件数確認、低負荷時間帯、DDL方式確認 |
| 既存表示落ち | `APP_UNPLANNED` を既存matchが扱えない | 全 `OrderSource` match に追加 |
| 重複予定作成 | EOS確定済みを予定なしとして作る | 過去3日インデックス + 同期時再照合 |
| 仕入連携混入 | APP_UNPLANNEDが意図せず仕入連携される | 予定なし入荷は仕入連携対象として扱い、区分表示で識別する |
| 大量商品レスポンス | 毎回全件取得で遅い | 項目削減、gzip、必要なら分割 |
| 部分失敗 | 同期バッチの一部だけ失敗 | 明細単位結果保存、バッチPARTIAL_FAILED |
| 既存API影響 | v1 APIの挙動変化 | 既存ファイルを触らずv2追加、回帰テスト |

## 資料レビュー観点

| 観点 | 結果 |
| --- | --- |
| 既存APIを修正しない | OK |
| EOS実績を優先 | OK |
| EOS対象は履歴のみ | OK |
| EOS確定済み重複防止 | OK。ただし実装時に再照合必須 |
| 予定なし入荷の識別 | OK。`APP_UNPLANNED` |
| 商品マスタ全件取得 | OK。ただしデータ量対策必須 |
| 分納・超過 | OK。ただしv2専用分岐が必要 |
| 仕入連携済み保護 | OK |
| 権限制御 | sakemaru-ai-core側に委譲 |
| テストケース | 主要ケースを網羅 |

## 実装前に必ず再確認すること

1. 本番 `wms_order_incoming_schedules` の件数と `order_source` ENUM変更の安全性
2. 倉庫取扱可能商品のSQL定義
3. 商品不明・仕入先不明をWeb画面で後から解決する導線の要否

## 判定

実装に進む前の仕様資料としては利用可能。

ただし、DB設計フェーズで `APP_UNPLANNED` 追加のDDL安全性と、倉庫取扱可能商品の抽出SQLを先に確定すること。
