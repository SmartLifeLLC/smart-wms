# 入荷API v2 アプリ検品・EOS自動連携対応仕様

作成日: 2026-08-08

## 目的

既存の入荷APIを変更せず、アプリ側で入荷検品を行うための新APIを追加する。

EOS(JX)発注済みの入荷予定は、EOS受信データによる自動入荷確定が正となる。そのため、アプリ側でEOS対象を検品した場合でも入荷予定は直接更新せず、検品履歴のみ保存する。

また、倉庫のネットワーク状態が悪い場合でも作業できるように、商品マスタは倉庫単位で1日1回だけ取得して端末に永続キャッシュし、入荷予定同期では入荷予定・照合用EOS確定済みデータ・ロケーションのみを取得する。

## 対象外

- 既存 `/api/incoming/*` の仕様変更
- 既存 `wms_incoming_work_items` の挙動変更
- EOS受信・照合・適用ロジックの置き換え
- sakemaru-ai-core側の権限制御実装
- アプリ側UIの詳細設計

## 開発ブランチ方針

現在の作業ツリーは detached HEAD であり、この状態から開発を開始しない。

開発開始時は `release/v1.0` から新規ブランチを作成する。

```bash
git switch release/v1.0
git pull --ff-only
git switch -c codex/incoming-api-v2-eos-inspection
```

新しい発注機能ブランチや一時的な修正ブランチから派生しない。

## 既存コードとの関係

### 既存API

既存APIは `routes/api.php` の `/api/incoming/*` に定義されている。

実装は `app/Http/Controllers/Api/IncomingController.php`。

現行の流れ:

1. `GET /api/incoming/schedules` で `PENDING` / `PARTIAL` の入荷予定を取得
2. `POST /api/incoming/work-items` で作業開始
3. `PUT /api/incoming/work-items/{id}` で数量・ロケ・賞味期限を更新
4. `POST /api/incoming/work-items/{id}/complete` で `IncomingConfirmationService` を呼び入荷予定を更新

この既存APIは「アプリ操作で入荷予定を確定する」前提であるため、EOS対象を扱うv2ではそのまま使わない。

### EOS自動連携

EOS取込・入荷予定更新は `app/Services/JX/Eos/JxEosIncomingWorkflowService.php` と `app/Services/AutoOrder/IncomingReceiveService.php` が担当する。

`JxEosIncomingWorkflowService::importAndApply()` は、EOS原本取込、受信ファイル作成、照合、入荷予定適用までを実行する。

EOS対象判定は `App\Models\WmsOrderIncomingSchedule::isEosSent()` および `scopeEosSent()` を基準にする。

### 入荷予定確定サービス

非EOSの確定処理は `app/Services/AutoOrder/IncomingConfirmationService.php` を利用する。

ただし、EOS対象ではこのサービスを呼ばない。EOS対象は検品履歴のみ保存し、入荷予定の状態変更はEOS連携側に任せる。

## API名前空間

新APIは以下の名前空間で追加する。

```text
/api/v2/incoming
```

既存APIと同じ認証方式を使う。

- `api.key`
- `auth:sanctum`

## 基本用語

| 用語 | 内容 |
| --- | --- |
| 入荷予定 | `wms_order_incoming_schedules` の未確定・確定済みレコード |
| EOS対象 | `isEosSent()` が true になる入荷予定 |
| EOS確定済み | EOS受信・適用により `CONFIRMED` になった入荷予定 |
| アプリ検品履歴 | v2 APIで保存する検品実績。EOS対象では入荷予定を更新せず履歴だけ残す |
| 予定なし入荷 | アプリ検品時に該当入荷予定がなく、新規作成して確定する入荷 |
| 要確認 | 自動で既存入荷予定・EOS確定済みへ一意に紐づけできず、Web確認が必要な状態 |

## 追加する order_source

予定なし入荷を表す新しい `order_source` を追加する。

```text
APP_UNPLANNED
```

表示名:

```text
予定なし入荷
```

既存の `RECEIVED` はJX/EOS受信データ由来の意味で使われているため、アプリで作成した予定なし入荷とは分離する。

### マイグレーション注意

現在 `wms_order_incoming_schedules.order_source` は MySQL ENUM である。

`APP_UNPLANNED` 追加には `ALTER TABLE ... MODIFY COLUMN order_source ENUM(...)` が必要になる。テーブルサイズによってはロックやテーブル再構築のリスクがあるため、実装前に本番テーブル件数とDDL実行方式を確認する。

可能であれば低負荷時間帯に実行し、既存アプリが `APP_UNPLANNED` を知らない状態でも落ちないよう、Enumクラスと表示系を先に対応してからDDLを適用する。

## データ取得方針

### スナップショット取得

アプリは倉庫単位で作業前に一括ダウンロードする。

予定API:

```text
GET /api/v2/incoming/item-master
GET /api/v2/incoming/snapshot
```

パラメータ:

| 項目 | 必須 | 内容 |
| --- | --- | --- |
| warehouse_id | 必須 | 作業倉庫ID |
| inspection_date | 任意 | 検品日。未指定時はシステム日付 |

取得対象:

`GET /api/v2/incoming/item-master`

1. 倉庫で取扱可能な商品マスタ

`GET /api/v2/incoming/snapshot`

1. 未確定入荷予定
2. EOS確定済み照合用データ
3. 倉庫別ロケーション情報

### 仮想倉庫対応

既存 `/api/incoming/schedules` と同様に、作業倉庫が実倉庫の場合は同一実倉庫に紐づく仮想倉庫分も入荷予定・EOS確定済み照合対象に含める。

- 履歴バッチの `warehouse_id` は、リクエストされた作業倉庫IDを保存する
- 未確定入荷予定の取得は、`WarehouseResolver::resolveAllWarehouseIds(warehouse_id)` の結果で検索する
- 同期時の入荷予定照合も同じ倉庫ID配列で検索する
- 予定なし入荷を新規作成する場合の `warehouse_id` は、リクエストされた作業倉庫IDを使う
- 仮想倉庫から実倉庫へ紐づく場合、発注先マッピングは実倉庫側の `item_contractors` を参照する

### 未確定入荷予定

未確定入荷予定は全期間取得する。

理由:

- 入荷予定は二週間前のものが自動整理される
- 未確定で残っているものは検品対象になる可能性がある
- アプリ側で検索できる必要がある

対象ステータス:

- `PENDING`
- `PARTIAL`

ただし、移動由来の入荷予定はアプリ確定対象にしない。表示する場合は `confirmation_policy = TRANSFER_WEB_ONLY` とし、Webまたは既存の倉庫移動導線で対応する。

### EOS確定済み照合用データ

EOS確定済み照合用データは、検品日を含む過去3日分だけ取得する。

例:

検品日が `2026-08-08` の場合、対象は以下。

- `2026-08-06`
- `2026-08-07`
- `2026-08-08`

目的:

- EOS入荷確定がアプリ検品より先に完了していた場合に、予定なし入荷として新規作成されることを防ぐ
- オフライン作業中のスナップショットに存在しなかった確定済みデータでも、同期時にサーバ側で再照合できるようにする
- 同一実倉庫配下の仮想倉庫分も照合対象にする

返却項目は軽量にする。

- schedule_id
- warehouse_id
- item_id
- item_code
- search_code
- jan_codes
- slip_number
- contractor_id
- expected_arrival_date
- actual_arrival_date
- received_quantity
- received_total_pieces
- confirmed_at
- eos_sent
- eos_applied

### 商品マスタ

商品マスタは `/api/v2/incoming/item-master` で取得する。通常の入荷予定同期では毎回全件取得しない。

HANDYアプリは倉庫ごとに1日1回だけ取得し、アプリ再起動後も使えるよう端末に永続保存する。入荷予定にない商品コードを登録しようとしてローカルマスタに存在しない場合、ユーザ確認後に最新マスタを再取得する。

取得対象は、倉庫で取扱可能な商品に限定する。

データ量を抑えるため、入荷検品に必要な項目だけ返す。

返却項目案:

- item_id
- item_code
- item_name
- kana
- volume
- volume_unit
- packaging
- capacity_case
- capacity_carton
- uses_expiration_date
- search_codes
- jan_codes
- item_quantity_codes
- default_location

返さない項目:

- 売価
- 原価
- 仕入単価
- 在庫詳細
- 商品説明文
- 画像URL

倉庫で取扱可能な商品の定義は実装前に確定する。

候補:

1. `item_incoming_default_locations` に当該倉庫の商品ロケが存在する
2. 当該倉庫に `real_stocks` または `real_stock_lots` が存在する
3. 発注・入荷予定・出荷実績の対象になったことがある

初期案は 1 と 2 の OR とし、必要に応じて 3 を追加する。

## スナップショットのレスポンス構造

```json
{
  "warehouse": {},
  "inspection_date": "2026-08-08",
  "generated_at": "2026-08-08T10:00:00+09:00",
  "rules": {
    "matching_warehouse_ids": [91, 92, 93]
  },
  "schedules": [],
  "confirmed_eos_index": [],
  "items": [],
  "locations": [],
  "summary": {}
}
```

### schedules

未確定入荷予定を返す。

主な項目:

- schedule_id
- warehouse_id
- contractor_id
- supplier_id
- order_source
- source_label
- slip_number
- item_id
- item_code
- search_code
- item_name
- expected_arrival_date
- order_date
- expected_quantity
- received_quantity
- remaining_quantity
- expected_total_pieces
- received_total_pieces
- remaining_total_pieces
- quantity_type
- capacity_case
- capacity_carton
- location_id
- expiration_date
- status
- eos_sent
- confirmation_policy

### confirmation_policy

| 値 | 内容 |
| --- | --- |
| `APP_CONFIRM_ALLOWED` | アプリから確定可能 |
| `EOS_HISTORY_ONLY` | EOS対象。履歴のみ保存し、入荷予定は更新しない |
| `EOS_ALREADY_CONFIRMED` | すでにEOS確定済み。履歴のみ保存 |
| `TRANSFER_WEB_ONLY` | 店間移動。アプリ確定対象外 |
| `PURCHASE_TRANSMITTED_LOCKED` | 仕入連携済み。履歴のみ保存または要確認 |
| `NEEDS_REVIEW` | 自動判定不可。Web確認対象 |

## 検品同期API

予定API:

```text
POST /api/v2/incoming/inspection-batches/sync
```

アプリはオフライン作業後、検品バッチ単位で同期する。

必須項目:

- warehouse_id
- picker_id または user_id
- device_id
- client_batch_uuid
- inspection_date
- inspected_at
- details[]

明細項目:

- client_line_uuid
- incoming_schedule_id nullable
- item_id nullable
- scanned_code nullable
- item_code nullable
- slip_number nullable
- contractor_id nullable
- case_quantity
- piece_quantity
- total_pieces
- location_id nullable
- expiration_date nullable
- app_note nullable

## 同期時のサーバ側再照合

アプリから送信された状態をそのまま信用しない。同期時にサーバ側で現在のDB状態を再確認する。

照合順:

1. `incoming_schedule_id` が指定されていれば、その入荷予定をロックして確認
2. 指定入荷予定がEOS対象または確定済みなら履歴のみ
3. 倉庫・商品・伝票番号・仕入先・入荷日で未確定入荷予定を検索
4. 検品日を含む過去3日分のEOS確定済みデータを検索
5. 一意に見つかれば、その入荷予定に履歴を紐付け
6. 複数候補なら `NEEDS_REVIEW`
7. 見つからなければ `APP_UNPLANNED` の新規入荷予定を作成して確定

この再照合により、EOS確定済みデータがアプリ側で「予定なし入荷」として重複作成されることを防ぐ。

## ケース別処理

### EOS対象の未確定入荷予定を検品した場合

処理:

- アプリ検品履歴を保存
- `wms_order_incoming_schedules` は更新しない
- `received_quantity`、`location_id`、`expiration_date` は変更しない
- 処理結果は `HISTORY_ONLY`

理由:

EOSの実績が正であり、アプリ検品は確認履歴として扱うため。

### EOS確定済みの入荷を検品した場合

処理:

- 確定済み入荷予定に検品履歴を紐付ける
- 新規入荷予定は作成しない
- 処理結果は `EOS_ALREADY_CONFIRMED`

差異があってもEOS実績を優先し、Web画面で確認・修正する。

### 非EOSの予定通り入荷

処理:

- アプリ検品履歴を保存
- `IncomingConfirmationService::confirmIncoming()` で入荷確定
- 処理結果は `CONFIRMED`

### 非EOSの入荷予定数未満

処理:

- 入荷した数量で元入荷予定を `CONFIRMED` にする
- 不足分は欠品として扱う
- 後日追加納品された場合は、新規入荷予定を作成して確定する

既存APIの `PARTIAL` 継続とは異なり、v2ではユーザ要件に合わせて「一部欠品として完了」とする。

### 非EOSの入荷予定数超過

処理:

- 元入荷予定は予定数量まで確定
- 超過分は同じ伝票番号で `APP_UNPLANNED` の新規入荷予定を作成して確定
- 検品履歴には元入荷予定IDと新規作成入荷予定IDの両方を記録する

### 入荷予定なし

処理:

- 商品をJAN、商品CD、検索CDから特定
- 過去3日分のEOS確定済みデータを再検索
- 一意にEOS確定済みが見つかれば履歴のみ保存
- 見つからない場合は `APP_UNPLANNED` の入荷予定を作成して確定
- 複数候補または商品不明の場合は `NEEDS_REVIEW`

### 商品不明

処理:

- 入荷予定は作成しない
- 検品履歴は `NEEDS_REVIEW` で保存
- Webの「アプリ入荷検品履歴」から確認する

### 仕入連携済み

処理:

- `purchase_queue_id` が設定済み、または `TRANSMITTED` の場合は入荷予定を更新しない
- 検品履歴のみ保存
- 数量差異があれば `NEEDS_REVIEW`

## 新規テーブル案

### `wms_incoming_app_inspection_batches`

アプリの同期単位を保存する。

主な項目:

- id
- warehouse_id
- picker_id nullable
- user_id nullable
- device_id
- client_batch_uuid
- inspection_date
- snapshot_generated_at nullable
- synced_at nullable
- status `RECEIVED`, `PARTIAL_FAILED`, `COMPLETED`
- detail_count
- confirmed_count
- history_only_count
- app_unplanned_count
- review_count
- error_count
- metadata JSON nullable
- timestamps

推奨制約・インデックス:

- unique: `device_id, client_batch_uuid`
- index: `warehouse_id, inspection_date`
- index: `status, synced_at`

### `wms_incoming_app_inspection_details`

検品明細を保存する。

主な項目:

- id
- batch_id
- client_line_uuid
- warehouse_id
- incoming_schedule_id nullable
- linked_confirmed_schedule_id nullable
- created_schedule_id nullable
- item_id nullable
- scanned_code nullable
- item_code nullable
- slip_number nullable
- contractor_id nullable
- case_quantity
- piece_quantity
- total_pieces
- quantity_type_snapshot
- capacity_case_snapshot
- capacity_carton_snapshot nullable
- location_id nullable
- expiration_date nullable
- eos_sent
- eos_applied
- confirmation_policy
- result_status
- review_reason nullable
- app_note nullable
- server_message nullable
- processed_at nullable
- timestamps

推奨制約・インデックス:

- unique: `batch_id, client_line_uuid`
- index: `warehouse_id, item_id, processed_at`
- index: `incoming_schedule_id`
- index: `linked_confirmed_schedule_id`
- index: `created_schedule_id`
- index: `result_status, processed_at`
- index: `slip_number, warehouse_id`

## 冪等性

同じ同期リクエストが複数回送信されても、入荷予定・検品履歴・新規予定が重複して作成されないようにする。

ルール:

- バッチは `device_id + client_batch_uuid` で一意
- 明細は `batch_id + client_line_uuid` で一意
- 既に処理済みの明細は再処理せず、前回結果を返す
- 新規 `APP_UNPLANNED` 作成時は `created_schedule_id` を保存し、再送時に再利用する

## 数量仕様

アプリ入力はケース数・バラ数を許可する。

保存時は必ず総バラ数も保存する。

計算:

```text
total_pieces = case_quantity * capacity_case + piece_quantity
```

JANや検索CDが6缶パック、4缶パックなど入数付きコードを表す場合は、商品検索コード側の数量情報も履歴に保存する。最終的な入荷数量判定では、既存のEOS数量正規化と同じく総バラ数基準で比較する。

## WMS画面

新規メニュー:

```text
入荷 > アプリ入荷検品履歴
```

表示対象:

- `wms_incoming_app_inspection_batches`
- `wms_incoming_app_inspection_details`

一覧カラム案:

- 検品日時
- 同期日時
- 倉庫CD
- 倉庫名
- 担当者
- 端末ID
- 商品CD
- 商品名
- JAN/スキャンCD
- 伝票番号
- ケース
- バラ
- 総バラ
- EOS対象
- 処理結果
- 要確認理由

詳細画面・モーダルでは、紐付いた入荷予定、EOS確定済み入荷、作成された予定なし入荷を確認できるようにする。

## 権限

権限制御は sakemaru-ai-core 側の共通制御に合わせる。

WMS側では画面・データを用意し、具体的な権限ロール名は実装時に既存管理画面の権限体系に合わせる。

## 例外処理

### 自動作成しない条件

以下は入荷予定を自動作成せず、検品履歴を `NEEDS_REVIEW` にする。

- 商品が特定できない
- 倉庫が不正
- 数量が0以下
- EOS確定済み候補が複数ある
- 未確定入荷予定候補が複数ある
- 仕入先が一意に決まらない
- 移動由来の可能性がある
- 仕入連携済みの入荷予定しか候補がない

### エラー時

バッチ全体を止めず、明細単位で処理結果を保存する。

DB例外など継続不能なエラーだけバッチを `PARTIAL_FAILED` または `FAILED` にする。

`Log::error()` には以下を含める。

- batch_id
- client_batch_uuid
- detail_id
- client_line_uuid
- warehouse_id
- item_id
- incoming_schedule_id
- error message

## 既存データとの整合性

- EOS対象判定は既存 `isEosSent()` を利用する
- EOS確定済み判定は `source_received_detail_id`、`source_incoming_schedule_id`、JX伝票番号割当、入荷受信明細との紐付きを確認する
- 仕入連携済みは `purchase_queue_id` と `status = TRANSMITTED` を更新不可条件にする
- 予定なし入荷は `APP_UNPLANNED` で既存の `RECEIVED` と混在させない
- `APP_UNPLANNED` で作成した入荷予定は、手動発注・受信データと同じく仕入データ送信対象に含める

## 実装前確認事項

1. 倉庫で取扱可能な商品のSQL定義
2. EOS確定済み照合で、仕入先不明の場合に商品・倉庫・日付だけで紐付けてよいか
3. スナップショットの最大レスポンスサイズとタイムアウト許容値
