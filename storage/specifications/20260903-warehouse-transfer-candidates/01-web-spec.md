# WMS Web 倉庫移動候補 仕様書

- 作成日: 2026-09-03
- メニュー: WMS Web `在庫 => 倉庫移動候補`
- URL案: `/admin/wms-warehouse-transfer-candidates`
- 対象: HANDYから届いた倉庫移動候補の確認、集約、修正、確定

## 目的

HANDYでスキャンされた「A倉庫からB倉庫へ移動したい商品・数量」をWMS Webで候補として受け取り、必要に応じて明細修正したうえで、基幹の倉庫移動伝票を `stock_transfer_queue` 経由で作成する。

## 用語

| 用語 | 意味 |
|---|---|
| 倉庫移動候補 | HANDY送信またはWeb手入力から作られるWMS上の未確定移動データ |
| 移動元倉庫 | 在庫を減らす倉庫。HANDYの自店/ログイン倉庫を初期値にする |
| 移動先倉庫 | 在庫を移す先。HANDY作業開始時またはWeb編集で指定する |
| 倉庫移動伝票 | 基幹 `stock_transfers` と `trade_items` で管理される正式な移動伝票 |
| queue | 基幹が処理する `stock_transfer_queue` |

## 対象外

- 棚卸し差異の反映。
- 横持ち出荷の欠品割当処理。
- 自動発注・安全在庫ベースの店間補充候補。
- 移動先での入荷検品/入荷予定作成。MVPでは正式伝票作成まで。

## 状態遷移

| 状態 | ラベル | 編集 | 確定 | 説明 |
|---|---|---:|---:|---|
| `PENDING` | 未確定 | 可 | 可 | HANDYから受信し、Webで修正可能 |
| `CONFIRMED` | 確定済 | 不可 | 不可 | Webで確定し、queue作成済み |
| `EXECUTED` | 伝票作成済 | 不可 | 不可 | 基幹queueが成功し、`stock_transfer_id` が入った |
| `FAILED` | 伝票作成失敗 | 不可 | 再投入可 | 基幹queueが失敗した |
| `CANCELLED` | 取消 | 不可 | 不可 | Webで候補を破棄 |

`CONFIRMED` と `EXECUTED/FAILED` は `stock_transfer_queue` の状態から表示を補完する。候補テーブルの状態更新は、一覧表示時の投影または同期コマンドで行う。

## データモデル案

既存 `wms_stock_transfer_candidates` は流用しない。新規に以下を作る。

### `wms_warehouse_transfer_candidates`

| カラム | 型 | 説明 |
|---|---|---|
| `id` | bigint unsigned | 主キー |
| `candidate_no` | varchar(32) | WMS候補番号 |
| `client_id` | bigint unsigned | クライアント |
| `source_type` | varchar(20) | `HANDY` / `WEB` |
| `from_warehouse_id` | bigint unsigned | 移動元倉庫ID |
| `from_warehouse_code` | varchar(32) | 移動元倉庫CD |
| `from_warehouse_name` | varchar(255) | 移動元倉庫名 |
| `to_warehouse_id` | bigint unsigned | 移動先倉庫ID |
| `to_warehouse_code` | varchar(32) | 移動先倉庫CD |
| `to_warehouse_name` | varchar(255) | 移動先倉庫名 |
| `delivery_course_id` | bigint unsigned nullable | 倉庫間配送コース |
| `process_date` | date | 処理日 |
| `delivered_date` | date | 納品日/移動予定日 |
| `status` | varchar(20) | 状態 |
| `submitted_by_picker_id` | bigint unsigned nullable | HANDY送信者 |
| `submitted_device_id` | varchar(100) nullable | 端末 |
| `submitted_at` | datetime nullable | 初回受信日時 |
| `confirmed_by` | bigint unsigned nullable | Web確定者 |
| `confirmed_at` | datetime nullable | 確定日時 |
| `queue_request_id` | varchar(255) nullable | queue冪等キー |
| `stock_transfer_queue_id` | bigint unsigned nullable | queue ID |
| `stock_transfer_id` | bigint unsigned nullable | 基幹移動ID |
| `memo` | text nullable | 備考 |
| timestamps |  |  |

### `wms_warehouse_transfer_candidate_items`

| カラム | 型 | 説明 |
|---|---|---|
| `id` | bigint unsigned | 主キー |
| `candidate_id` | bigint unsigned | 候補ヘッダID |
| `item_id` | bigint unsigned | 商品ID |
| `item_code` | varchar(64) | 商品CD |
| `item_name` | varchar(255) | 商品名 |
| `barcode` | varchar(255) nullable | バーコード |
| `real_stock_id` | bigint unsigned nullable | スキャン元在庫ID |
| `location_id` | bigint unsigned nullable | ロケーションID |
| `location_no` | varchar(255) nullable | ロケーション表示 |
| `stock_allocation_code` | varchar(32) | 在庫区分CD。初期値 `1` |
| `case_quantity` | decimal(12,3) | 画面表示用ケース数 |
| `piece_quantity` | decimal(12,3) | 画面表示用バラ数 |
| `package_quantity` | int | 入数 |
| `transfer_quantity` | decimal(12,3) | 総バラ数 |
| `available_quantity_at_sync` | decimal(12,3) nullable | HANDY同期時点の在庫 |
| `available_quantity_at_confirm` | decimal(12,3) nullable | Web確定時点の在庫 |
| `scanned_code` | varchar(255) nullable | 読取CD |
| `source_line_count` | int | 集約元のHANDY行数 |
| `line_note` | varchar(255) nullable | 明細備考 |
| `sort_order` | int | 表示順 |
| timestamps |  |  |

### `wms_warehouse_transfer_candidate_item_sources`

HANDY送信行単位の監査ログ。Web表示用の明細は同一商品で集約するため、行単位の冪等性はこのテーブルで担保する。

| カラム | 型 | 説明 |
|---|---|---|
| `id` | bigint unsigned | 主キー |
| `candidate_id` | bigint unsigned | 候補ヘッダID |
| `candidate_item_id` | bigint unsigned | 集約先明細ID |
| `upload_id` | bigint unsigned | 送信バッチログID |
| `source_request_uuid` | varchar(255) | HANDY行冪等キー |
| `real_stock_id` | bigint unsigned nullable | スキャン元在庫ID |
| `case_quantity` | decimal(12,3) | HANDY入力ケース数 |
| `piece_quantity` | decimal(12,3) | HANDY入力バラ数 |
| `package_quantity` | int | 入数 |
| `transfer_quantity` | decimal(12,3) | 総バラ数 |
| `scanned_code` | varchar(255) nullable | 読取CD |
| timestamps |  |  |

### `wms_warehouse_transfer_candidate_uploads`

HANDY送信単位の監査ログ。二重送信検知と、どの端末送信がどの候補に取り込まれたかの追跡に使う。

| カラム | 型 | 説明 |
|---|---|---|
| `id` | bigint unsigned | 主キー |
| `candidate_id` | bigint unsigned | 候補ヘッダID |
| `upload_uuid` | varchar(255) | HANDY送信バッチUUID |
| `device_id` | varchar(100) nullable | 端末 |
| `picker_id` | bigint unsigned nullable | 送信者 |
| `item_count` | int | 受信明細数 |
| `accepted_count` | int | 反映件数 |
| `missing_item_ids` | json nullable | 対象外の商品 |
| `payload_hash` | char(64) | 送信内容ハッシュ |
| timestamps |  |  |

外部キーは付けない。検索用インデックスと `upload_uuid` / `wms_warehouse_transfer_candidate_item_sources.source_request_uuid` の一意制約で冪等性を担保する。

## HANDY受信時の集約

1. APIは `upload_uuid` が既存なら同じレスポンスを返す。
2. `from_warehouse_id`、`to_warehouse_id`、`process_date`、`delivered_date`、`status=PENDING`、`source_type=HANDY` が一致する未確定候補を探す。
3. あればその候補に明細を追加/加算する。なければ新規ヘッダを作る。
4. `source_request_uuid` が `wms_warehouse_transfer_candidate_item_sources` に既存なら再加算しない。
5. 同一候補内で同一 `item_id + stock_allocation_code` の明細は、`transfer_quantity` を加算する。
6. 加算元のHANDY行は `wms_warehouse_transfer_candidate_item_sources` に残す。
7. 元の送信単位は `wms_warehouse_transfer_candidate_uploads` に残す。

## 一覧画面

Filament Resource:

- Resource案: `WmsWarehouseTransferCandidateResource`
- Navigation group: 在庫管理
- Navigation label: 倉庫移動候補
- Model label: 倉庫移動候補
- Icon案: `heroicon-o-arrow-right-left`
- Table: `striped()` + `extraAttributes(['class' => 'sticky-actions'])`

表示カラム:

| カラム | 表示 |
|---|---|
| 状態 | badge |
| 候補番号 | `candidate_no` |
| 受信日時 | `m/d H:i` |
| 移動元倉庫CD | code |
| 移動元倉庫名 | name |
| 移動先倉庫CD | code |
| 移動先倉庫名 | name |
| 処理日 | `m/d` |
| 納品日 | `m/d` |
| 明細数 | item count |
| 総バラ | sum transfer quantity |
| 送信者 | picker/device |
| queue | BEFORE/PROCESSING/FINISHED/ERROR |
| 移動ID | 基幹 `stock_transfer_id` |

検索/フィルター:

- 状態。
- 移動元倉庫。
- 移動先倉庫。
- 受信日。
- 確定日。
- 商品CD/商品名。
- 端末/送信者。

レコードアクション:

- `詳細`: 詳細ページへ。
- `確定`: `PENDING` のみ。確認モーダルを出す。
- `取消`: `PENDING` のみ。
- `再投入`: `FAILED` のみ。既存queueが失敗済みの場合は新しい `request_id` ではなく、既存queueを再処理できる状態へ戻す設計を優先する。基幹側再処理仕様が不足する場合は別途決める。

## 詳細画面

構成:

- ヘッダー: 状態、候補番号、移動元/移動先、日付、queue状態、基幹移動ID。
- ヘッダー編集: `PENDING` のみ、移動先倉庫、処理日、納品日、配送コース、備考を編集可。
- 明細テーブル: 商品CD、商品名、ロケーション、現在在庫、ケース、バラ、総バラ、在庫区分、読取CD、備考。
- 明細操作: 追加、数量修正、削除。`PENDING` のみ。
- 確定ボタン: ヘッダー右側またはテーブル上部。

明細編集ルール:

- 商品名は `grow()` で表示し、`wrap()` は使わない。
- コード系ラベルは `商品CD`、`倉庫CD`、`在庫区分CD` に統一する。
- 倉庫はCDと名前を別カラムにする。
- 日時は24時間表記。
- 明細アクション列は右固定。

## 確定処理

確定ボタン押下時:

1. 候補を `lockForUpdate()` で取得する。
2. 状態が `PENDING` であることを確認する。
3. 明細が1件以上あり、全行 `transfer_quantity > 0` であることを確認する。
4. 移動元/移動先倉庫が存在し、同一ではないことを確認する。
5. 配送コースを解決する。
6. 商品CDと在庫区分CDが基幹マスタで有効であることを確認する。
7. 移動元の利用可能在庫を再計算し、在庫不足があれば確認モーダルに警告表示する（確定は可能）。
8. `stock_transfer_queue` に `action_type=CREATE`、`status=BEFORE` で登録する。
9. 候補を `CONFIRMED` にし、`queue_request_id` と `stock_transfer_queue_id` を保存する。

queue request id:

```text
wms-warehouse-transfer-{candidate_id}
```

queue note:

```text
WMS倉庫移動候補: {candidate_no}
```

queue items:

```json
[
  {
    "item_code": "100001",
    "quantity": 12,
    "quantity_type": "PIECE",
    "stock_allocation_code": "1",
    "note": "WMS倉庫移動候補ID: 1 / 明細ID: 10"
  }
]
```

`quantity_type` はMVPでは `PIECE` 固定にする。HANDYやWebではケース/バラを表示してよいが、queueへは総バラ数で渡す。

## 配送コース

配送コース解決:

1. `warehouse_stock_transfer_delivery_courses` に `from_warehouse_id + to_warehouse_id` の設定があれば使う。
2. 候補に手動選択済みの `delivery_course_id` があれば使う。
3. 解決できなければ確定をブロックし、詳細画面で選択させる。

`ProcessStockTransfer` は `delivery_course_id` を `UpdateStockTransfer` に渡す。基幹側では picking date の自動算出にも使われる。

## queue処理結果の表示

一覧/詳細は `stock_transfer_queue` を `queue_request_id` で参照する。

| queue状態 | Web表示 |
|---|---|
| なし | 未投入 |
| `BEFORE` | queue待ち |
| `PROCESSING` | 処理中 |
| `FINISHED` + `is_success=1` | 伝票作成済 |
| `FINISHED` + `is_success=0` | 伝票作成失敗 |

成功時は `stock_transfer_queue.stock_transfer_id` を表示し、基幹の移動画面への参照を用意する。

## バリデーション

候補受信:

- 移動元倉庫はログインpickerの利用可能倉庫、またはdefault warehouseと一致する。
- 移動先倉庫は必須。
- 移動元と移動先は同一不可。
- 明細は1件以上。
- 商品はWMS対象の管理商品。
- 総バラ数は正数。0や負数は送信不可。

Web編集:

- 確定済み以降は編集不可。
- 明細削除後に0件になった候補は確定不可。
- ケース/バラ変更時は `transfer_quantity = case_quantity * package_quantity + piece_quantity` を再計算する。

確定:

- 在庫不足は警告のみ（確定可能）。基幹側で在庫マイナスになる可能性を確認モーダルに明示する。
- queueの同一 `request_id` が存在する場合、`BEFORE` なら内容更新可、`PROCESSING/FINISHED` なら再作成しない。

## 権限

権限案:

- `wms.warehouse-transfer-candidates.view`
- `wms.warehouse-transfer-candidates.create`
- `wms.warehouse-transfer-candidates.update`
- `wms.warehouse-transfer-candidates.confirm`
- `wms.warehouse-transfer-candidates.cancel`

確定は在庫移動を実行するため、閲覧/編集とは分ける。

## モーダル仕様

確定モーダル:

- `modalWidth('5xl')`
- `extraModalWindowAttributes(['class' => 'incoming-detail-modal'])`
- 実行ボタン: `移動を確定`
- 実行ボタン色: `danger`
- キャンセル: `確定せず閉じる`
- 表示内容: 移動元、移動先、配送コース、日付、明細数、総バラ、在庫不足チェック結果。

明細追加モーダル:

- 商品選択が大量になるため、単純な全件Selectではなく検索APIまたは `ViewField + Alpine.js` パターンを使う。
- 商品候補は `[商品CD]商品名` 形式で検索結果に出してよいが、テーブル列ではCD/名称を分ける。

## 実装単位

1. migration/model/enum/service を追加。
2. HANDY受信APIを追加。
3. queue投入サービスを追加。
4. Filament Resourceと一覧/詳細画面を追加。
5. menu/navigationに `在庫 => 倉庫移動候補` を追加。
6. queue結果の投影/同期を追加。
7. テストを追加。

## テスト観点

- HANDY受信APIが候補を新規作成する。
- 同じ `upload_uuid` の再送が二重加算されない。
- 同じ候補内で同一商品が加算される。
- `source_request_uuid` の重複が二重加算されない。
- Web編集で総バラが再計算される。
- 確定時に正しい `stock_transfer_queue` JSONができる。
- 同じ候補を二重確定してもqueueが1件だけ。
- 在庫不足、倉庫同一、配送コース未解決、商品無効は確定できない。

テストでは `RefreshDatabase` を使わず、トランザクションまたは対象限定の作成/削除で処理する。
