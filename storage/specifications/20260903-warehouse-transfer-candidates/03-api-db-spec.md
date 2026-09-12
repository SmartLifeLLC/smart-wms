# 倉庫移動候補 API・DB 仕様書

- 作成日: 2026-09-03
- 対象: WMS API、WMS DB、基幹 `stock_transfer_queue`

## API方針

- 既存HANDY APIと同じく `routes/api.php` 配下で `api.key` と `auth:sanctum` を通す。
- レスポンスは既存 `ApiController` の envelope を使う。
- HANDYからの送信は候補作成/更新まで。実在庫更新はしない。
- Web確定時だけ `stock_transfer_queue` に投入する。

## エンドポイント

### 在庫リスト取得

```http
GET /api/wms/warehouse-transfer/stock-items
```

Query:

| name | required | 説明 |
|---|---:|---|
| `warehouse_id` | yes | 移動元倉庫ID |
| `page` | no | 初期値1 |
| `per_page` | no | 最大500 |
| `compact` | no | `1` なら `search_codes` を省略 |

Response data:

```json
{
  "items": [
    {
      "id": 5001,
      "real_stock_id": 5001,
      "item_id": 1001,
      "item_code": "100001",
      "item_name": "商品A",
      "barcode": "4900000000000",
      "capacity_case": 10,
      "capacity_carton": null,
      "location": {
        "id": 301,
        "floor_name": "1F",
        "location_no": "A-01-01",
        "code1": "A",
        "code2": "01",
        "code3": "01"
      },
      "stock_allocation_code": "1",
      "available_quantity": 120,
      "case_quantity": 12,
      "piece_quantity": 0
    }
  ],
  "meta": {
    "page": 1,
    "per_page": 500,
    "total": 1,
    "last_page": 1
  }
}
```

取得元案:

- `real_stocks` を主にする。
- WMS予約/ピッキング中数量を考慮できる場合は `wms_v_stock_available` 相当の利用可能在庫を使う。
- 倉庫、商品、ロケーション、在庫区分をJOINして、HANDY表示に必要な最小項目だけ返す。
- `select *` は避け、巨大カラムを取得しない。

### JAN辞書取得

```http
GET /api/wms/warehouse-transfer/jan-codes
```

Query:

| name | required | 説明 |
|---|---:|---|
| `warehouse_id` | yes | 移動元倉庫ID |

Response data:

```json
{
  "jan_codes": {
    "4900000000000": [
      {
        "i": 1001,
        "rs": 5001,
        "ct": "JAN",
        "t": "0",
        "q": 1
      }
    ]
  }
}
```

`t` の値:

| value | quantity_type |
|---|---|
| `0` | `PIECE` |
| `1` | `CASE` |
| `2` | `CARTON` |
| `9` | その他 |

棚卸しAPIの `janCodes()` と同じ設計にし、HANDY側の辞書処理を流用しやすくする。

### 倉庫一覧取得

既存マスタAPIがある場合は流用する。ない場合は追加する。

```http
GET /api/wms/warehouse-transfer/warehouses
```

用途:

- HANDYの移動先倉庫選択。
- 自店倉庫と同一の倉庫は選択不可にする。

### 候補送信

```http
POST /api/wms/warehouse-transfer-candidates
```

Request:

```json
{
  "upload_uuid": "d5c6f6c9-1c7d-42a8-9c8c-000000000001",
  "device_id": "DENSO",
  "from_warehouse_id": 91,
  "to_warehouse_id": 12,
  "process_date": "2026-09-03",
  "delivered_date": "2026-09-03",
  "items": [
    {
      "item_id": 1001,
      "item_code": "100001",
      "real_stock_id": 5001,
      "stock_allocation_code": "1",
      "case_quantity": 1,
      "piece_quantity": 2,
      "package_quantity": 10,
      "quantity": 12,
      "search_code": "4900000000000",
      "request_uuid": "line-uuid-1"
    }
  ]
}
```

Validation:

| field | rule |
|---|---|
| `upload_uuid` | required string max:255 |
| `device_id` | nullable string max:100 |
| `from_warehouse_id` | required integer exists-like validation |
| `to_warehouse_id` | required integer exists-like validation, different from source |
| `process_date` | required date |
| `delivered_date` | required date |
| `items` | required array min:1 max:500 |
| `items.*.item_id` | required integer |
| `items.*.item_code` | required string |
| `items.*.stock_allocation_code` | nullable string default `1` |
| `items.*.case_quantity` | nullable numeric |
| `items.*.piece_quantity` | nullable numeric |
| `items.*.package_quantity` | nullable integer min:1 |
| `items.*.quantity` | required numeric min:0.001 |
| `items.*.request_uuid` | required string max:255 |

Response data:

```json
{
  "candidate": {
    "id": 1,
    "candidate_no": "WT202609030001",
    "status": "PENDING",
    "from_warehouse_id": 91,
    "from_warehouse_code": "91",
    "to_warehouse_id": 12,
    "to_warehouse_code": "12",
    "process_date": "2026-09-03",
    "delivered_date": "2026-09-03",
    "item_count": 1,
    "total_quantity": 12
  },
  "accepted_count": 1,
  "missing_item_ids": []
}
```

### 候補取得

HANDYの送信済み履歴確認用。MVPではWebのみでもよい。

```http
GET /api/wms/warehouse-transfer-candidates/{id}
```

## DB DDL案

Laravel migrationで作成する。外部キーは付けない。

```sql
CREATE TABLE wms_warehouse_transfer_candidates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_no VARCHAR(32) NOT NULL,
  client_id BIGINT UNSIGNED NOT NULL,
  source_type VARCHAR(20) NOT NULL DEFAULT 'HANDY',
  from_warehouse_id BIGINT UNSIGNED NOT NULL,
  from_warehouse_code VARCHAR(32) NOT NULL,
  from_warehouse_name VARCHAR(255) NULL,
  to_warehouse_id BIGINT UNSIGNED NOT NULL,
  to_warehouse_code VARCHAR(32) NOT NULL,
  to_warehouse_name VARCHAR(255) NULL,
  delivery_course_id BIGINT UNSIGNED NULL,
  process_date DATE NOT NULL,
  delivered_date DATE NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
  submitted_by_picker_id BIGINT UNSIGNED NULL,
  submitted_device_id VARCHAR(100) NULL,
  submitted_at DATETIME NULL,
  confirmed_by BIGINT UNSIGNED NULL,
  confirmed_at DATETIME NULL,
  queue_request_id VARCHAR(255) NULL,
  stock_transfer_queue_id BIGINT UNSIGNED NULL,
  stock_transfer_id BIGINT UNSIGNED NULL,
  memo TEXT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uniq_wms_wh_transfer_candidate_no (candidate_no),
  UNIQUE KEY uniq_wms_wh_transfer_queue_request (queue_request_id),
  INDEX idx_wms_wh_transfer_status_dates (status, process_date, delivered_date),
  INDEX idx_wms_wh_transfer_from_to (from_warehouse_id, to_warehouse_id, status),
  INDEX idx_wms_wh_transfer_queue (stock_transfer_queue_id, stock_transfer_id)
);

CREATE TABLE wms_warehouse_transfer_candidate_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_id BIGINT UNSIGNED NOT NULL,
  item_id BIGINT UNSIGNED NOT NULL,
  item_code VARCHAR(64) NOT NULL,
  item_name VARCHAR(255) NOT NULL,
  barcode VARCHAR(255) NULL,
  real_stock_id BIGINT UNSIGNED NULL,
  location_id BIGINT UNSIGNED NULL,
  location_no VARCHAR(255) NULL,
  stock_allocation_code VARCHAR(32) NOT NULL DEFAULT '1',
  case_quantity DECIMAL(12,3) NOT NULL DEFAULT 0,
  piece_quantity DECIMAL(12,3) NOT NULL DEFAULT 0,
  package_quantity INT NOT NULL DEFAULT 1,
  transfer_quantity DECIMAL(12,3) NOT NULL DEFAULT 0,
  available_quantity_at_sync DECIMAL(12,3) NULL,
  available_quantity_at_confirm DECIMAL(12,3) NULL,
  scanned_code VARCHAR(255) NULL,
  source_line_count INT NOT NULL DEFAULT 0,
  line_note VARCHAR(255) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uniq_wms_wh_transfer_item_merge (candidate_id, item_id, stock_allocation_code),
  INDEX idx_wms_wh_transfer_item_candidate (candidate_id, sort_order),
  INDEX idx_wms_wh_transfer_item_lookup (item_id, stock_allocation_code),
  INDEX idx_wms_wh_transfer_item_code (item_code)
);

CREATE TABLE wms_warehouse_transfer_candidate_item_sources (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_id BIGINT UNSIGNED NOT NULL,
  candidate_item_id BIGINT UNSIGNED NOT NULL,
  upload_id BIGINT UNSIGNED NOT NULL,
  source_request_uuid VARCHAR(255) NOT NULL,
  real_stock_id BIGINT UNSIGNED NULL,
  case_quantity DECIMAL(12,3) NOT NULL DEFAULT 0,
  piece_quantity DECIMAL(12,3) NOT NULL DEFAULT 0,
  package_quantity INT NOT NULL DEFAULT 1,
  transfer_quantity DECIMAL(12,3) NOT NULL DEFAULT 0,
  scanned_code VARCHAR(255) NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uniq_wms_wh_transfer_source_uuid (source_request_uuid),
  INDEX idx_wms_wh_transfer_source_candidate (candidate_id),
  INDEX idx_wms_wh_transfer_source_item (candidate_item_id),
  INDEX idx_wms_wh_transfer_source_upload (upload_id)
);

CREATE TABLE wms_warehouse_transfer_candidate_uploads (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  candidate_id BIGINT UNSIGNED NOT NULL,
  upload_uuid VARCHAR(255) NOT NULL,
  device_id VARCHAR(100) NULL,
  picker_id BIGINT UNSIGNED NULL,
  item_count INT NOT NULL DEFAULT 0,
  accepted_count INT NOT NULL DEFAULT 0,
  missing_item_ids JSON NULL,
  payload_hash CHAR(64) NOT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uniq_wms_wh_transfer_upload_uuid (upload_uuid),
  INDEX idx_wms_wh_transfer_upload_candidate (candidate_id)
);
```

`queue_request_id` はnullable uniqueにする。MySQLではnullable uniqueは複数NULLを許容するため、確定前候補を複数作れる。

## 候補作成サービス

Service案:

- `App\Services\WarehouseTransfer\WarehouseTransferCandidateReceiveService`
- `App\Services\WarehouseTransfer\WarehouseTransferQueueService`
- `App\Services\WarehouseTransfer\WarehouseTransferStockListService`

受信処理:

```text
receive(payload, picker)
  validate upload_uuid not processed
  lock open candidate group
  create candidate if absent
  for each item:
    validate item/stock allocation
    skip if source_request_uuid exists in item_sources
    aggregate by candidate + item_id + stock_allocation_code
    create item_source row
  create upload log
  return candidate summary
```

候補番号:

```text
WT{YYYYMMDD}{4桁連番}
```

連番は同日内の最大番号 + 1。競合を避けるため、候補作成はtransaction内で行う。番号衝突時は再採番する。

## queue投入仕様

Service案:

- `WarehouseTransferQueueService::enqueue(WmsWarehouseTransferCandidate $candidate, int $confirmedBy): int`

処理:

1. 候補と明細をtransaction内でロックする。
2. `PENDING` 以外なら既存queue IDを返すかエラーにする。
3. 倉庫/配送コース/商品/在庫区分/在庫数を検証する。
4. `stock_transfer_queue` にINSERTまたはBEFORE状態の既存queueをUPDATEする。
5. 候補を `CONFIRMED` に更新する。

`stock_transfer_queue`:

```sql
INSERT INTO stock_transfer_queue (
  client_id,
  slip_number,
  process_date,
  delivered_date,
  note,
  items,
  from_warehouse_code,
  to_warehouse_code,
  delivery_course_id,
  request_id,
  status,
  action_type,
  created_at,
  updated_at
) VALUES (...);
```

値:

| カラム | 値 |
|---|---|
| `client_id` | `config('app.client_id')` |
| `slip_number` | `null` |
| `process_date` | 候補の `process_date` |
| `delivered_date` | 候補の `delivered_date` |
| `note` | `WMS倉庫移動候補: {candidate_no}` |
| `items` | 下記JSON |
| `from_warehouse_code` | 候補の移動元倉庫CD |
| `to_warehouse_code` | 候補の移動先倉庫CD |
| `delivery_course_id` | 解決済み配送コースID |
| `request_id` | `wms-warehouse-transfer-{candidate_id}` |
| `status` | `BEFORE` |
| `action_type` | `CREATE` |

items JSON:

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

`purchase_price` は必須ではない。基幹 `ProcessStockTransfer` が商品マスタの現行仕入価格にフォールバックする。WMS側で取得できる場合のみ入れてよい。

## 在庫数検証

確定時に移動元倉庫の利用可能在庫を再計算する。

推奨:

- `real_stocks.quantity - wms_reserved_qty - wms_picking_qty` 相当を使う。
- 同一商品・同一在庫区分で明細を集約した後の総量と比較する。
- 管理対象外商品は候補受信時点で除外またはWeb確認対象にする。

エラー例:

```json
{
  "code": "INSUFFICIENT_STOCK",
  "message": "移動元在庫が不足しています",
  "errors": {
    "items.0.quantity": ["利用可能在庫 5 に対して移動数 12 が指定されています"]
  }
}
```

## queue結果同期

同期方法:

- 一覧/詳細表示時に `stock_transfer_queue` を `queue_request_id` で参照し、状態を投影する。
- 必要に応じて artisan command を追加し、`stock_transfer_id` と状態を候補に反映する。

同期ルール:

| queue状態 | candidate更新 |
|---|---|
| `BEFORE` | `CONFIRMED` のまま |
| `PROCESSING` | `CONFIRMED` のまま |
| `FINISHED` + success | `EXECUTED`, `stock_transfer_id` 保存 |
| `FINISHED` + failure | `FAILED`, error表示 |

## セキュリティ

- API key と Sanctum認証を必須にする。
- `from_warehouse_id` はpickerのdefault warehouseまたは権限倉庫に限定する。
- `to_warehouse_id` は有効倉庫に限定する。
- `device_id` は監査用途で保存するが、認可判断の主キーにしない。
- Web確定は専用権限を必須にする。

## マイグレーション安全性

- 新規テーブル追加のみ。
- 既存テーブルの破壊的変更なし。
- 外部キーなし。
- 既存 `stock_transfer_queue` は変更しない。
- 既存データ移行なし。
- 禁止コマンド `migrate:fresh` / `migrate:refresh` / `migrate:reset` / `db:wipe` / `db:fresh` は使用しない。

## 実装時の確認SQL

読み取り専用の確認例:

```sql
SELECT id, request_id, status, action_type, is_success, stock_transfer_id
FROM stock_transfer_queue
WHERE request_id = 'wms-warehouse-transfer-{candidate_id}';
```

```sql
SELECT id, candidate_no, status, stock_transfer_queue_id, stock_transfer_id
FROM wms_warehouse_transfer_candidates
ORDER BY id DESC
LIMIT 20;
```
