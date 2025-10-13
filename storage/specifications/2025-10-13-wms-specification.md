
# WMS 開発仕様書（MVP・段階実装）

## 1. 概要／方針

* 共通DB（基幹）を参照しつつ、WMSは **作業・予約・実績・監査のみ** を `wms_` テーブルに保持。
* **外部キー・一意キーは付与しない**（整合はアプリ/ジョブで担保）。
* 引当優先は **FEFO（賞味期限）→ FIFO（received_at）**。`purchase_id` は NULL を許容。
* 容器（空樽・瓶等）は WMSで履歴化のみ、伝票は基幹側を参照。
* 基幹(sakemaru)の `real_stocks` には `wms_reserved_qty / wms_picking_qty / wms_lock_version` を追加済み。

## 基幹DB `real_stocks` テーブル
* 目的: WMSと基幹間で在庫拘束状況を共有し、在庫一貫性を確保する。
* 対応項目:
* WMS拘束情報（`wms_reserved_qty`, `wms_picking_qty`, `wms_lock_version`）の保持

機能説明
 カラム概要

| カラム名               | 型   | 初期値 | 説明                    |
| ------------------ | --- | --- | --------------------- |
| `wms_reserved_qty` | INT | 0   | WMSで引当済み（ピッキング前）の拘束数量 |
| `wms_picking_qty`  | INT | 0   | ピッキング進行中の拘束数量         |
| `wms_lock_version` | INT | 0   | 楽観ロック制御用のバージョン番号      |

---

 運用ルール

* 引当時に `wms_reserved_qty += qty`、ピッキング開始時に `wms_reserved_qty -= qty`・`wms_picking_qty += qty`。
* 出荷確定時に `wms_picking_qty -= qty`。基幹在庫の確定減算は既存の出荷確定処理で実施。
* `wms_lock_version` は並行更新検出に使用（更新ごとに +1）。同一在庫への競合更新を防ぐ。

---



## 2. WMS システム　テーブル定義（DDL 抜粋／FKなし）

### 2.1 予約・冪等

```sql
CREATE TABLE wms_reservations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id BIGINT UNSIGNED NOT NULL,
  warehouse_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NULL,
  real_stock_id BIGINT UNSIGNED NULL,
  item_id BIGINT UNSIGNED NOT NULL,
  expiry_date DATE NULL,
  received_at DATETIME NULL,
  purchase_id BIGINT UNSIGNED NULL,
  unit_cost DECIMAL(12,4) NULL,
  qty_each INT NOT NULL,
  source_type ENUM('EARNING','PURCHASE','REPLENISH','COUNT','MOVE') NOT NULL,
  source_id BIGINT UNSIGNED NOT NULL,
  source_line_id BIGINT UNSIGNED NULL,
  wave_id BIGINT UNSIGNED NULL,
  status ENUM('RESERVED','RELEASED','CONSUMED','CANCELLED') NOT NULL DEFAULT 'RESERVED',
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  INDEX idx_resv_main (warehouse_id, item_id, expiry_date, received_at, status),
  INDEX idx_resv_source (source_type, source_line_id)
);

CREATE TABLE wms_idempotency_keys (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  scope VARCHAR(64) NOT NULL,
  key_hash CHAR(64) NOT NULL,
  created_at TIMESTAMP NULL,
  UNIQUE KEY uniq_scope_key (scope, key_hash)
);
```

### 2.2 波動・ピッキング・出荷

```sql
CREATE TABLE wms_waves (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id BIGINT UNSIGNED NOT NULL,
  warehouse_id BIGINT UNSIGNED NOT NULL,
  wave_no VARCHAR(32) NOT NULL,
  route_code VARCHAR(32) NULL,
  cutoff_time DATETIME NULL,
  temp_zone ENUM('AMBIENT','CHILLED') NULL,
  status ENUM('PLANNED','ALLOCATED','PICKING','PACKING','SHIPPED','CLOSED') NOT NULL DEFAULT 'PLANNED',
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uniq_wave (client_id, warehouse_id, wave_no)
);

CREATE TABLE wms_wave_earnings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  wave_id BIGINT UNSIGNED NOT NULL,
  earning_id BIGINT UNSIGNED NOT NULL,
  UNIQUE KEY uniq_wave_earning (wave_id, earning_id),
  INDEX idx_wave (wave_id)
);

CREATE TABLE wms_picking_tasks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  wave_id BIGINT UNSIGNED NOT NULL,
  warehouse_id BIGINT UNSIGNED NOT NULL,
  picker_id BIGINT UNSIGNED NULL,
  status ENUM('READY','IN_PROGRESS','DONE','ABORTED') NOT NULL DEFAULT 'READY',
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  INDEX idx_wave_id (wave_id)
);

CREATE TABLE wms_picking_task_lines (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  task_id BIGINT UNSIGNED NOT NULL,
  earning_line_id BIGINT UNSIGNED NOT NULL,
  real_stock_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  item_id BIGINT UNSIGNED NOT NULL,
  lot_no VARCHAR(64) NULL,
  expiry_date DATE NULL,
  received_at DATETIME NULL,
  purchase_id BIGINT UNSIGNED NULL,
  unit_cost DECIMAL(12,4) NULL,
  pick_qty_each INT NOT NULL,
  scanned_qty_each INT NOT NULL DEFAULT 0,
  diff_reason VARCHAR(255) NULL,
  status ENUM('READY','PICKED','SHORT','SUBSTITUTED') NOT NULL DEFAULT 'READY',
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  INDEX idx_task (task_id),
  INDEX idx_earning_line (earning_line_id)
);

CREATE TABLE wms_ship_confirms (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  wave_id BIGINT UNSIGNED NOT NULL,
  earning_id BIGINT UNSIGNED NOT NULL,
  confirm_no VARCHAR(32) NOT NULL,
  shipped_at DATETIME NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NULL,
  UNIQUE KEY uniq_confirm (wave_id, earning_id, confirm_no)
);
```

### 2.3 入荷・棚入れ

```sql
CREATE TABLE wms_receipts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_id BIGINT UNSIGNED NOT NULL,
  warehouse_id BIGINT UNSIGNED NOT NULL,
  status ENUM('RECEIVING','PUTAWAY','COMPLETED','CANCELLED') NOT NULL DEFAULT 'RECEIVING',
  received_at DATETIME NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uniq_receipt (purchase_id)
);

CREATE TABLE wms_receipt_lines (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  receipt_id BIGINT UNSIGNED NOT NULL,
  purchase_line_id BIGINT UNSIGNED NULL,
  item_id BIGINT UNSIGNED NOT NULL,
  lot_no VARCHAR(64) NULL,
  expiry_date DATE NULL,
  expected_qty_each INT NOT NULL,
  received_qty_each INT NOT NULL DEFAULT 0,
  unit_cost DECIMAL(12,4) NULL,
  diff_reason VARCHAR(255) NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  INDEX idx_receipt (receipt_id)
);

CREATE TABLE wms_putaway_tasks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  receipt_id BIGINT UNSIGNED NOT NULL,
  warehouse_id BIGINT UNSIGNED NOT NULL,
  status ENUM('READY','IN_PROGRESS','DONE','ABORTED') NOT NULL DEFAULT 'READY',
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL
);

CREATE TABLE wms_putaway_task_lines (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  putaway_task_id BIGINT UNSIGNED NOT NULL,
  item_id BIGINT UNSIGNED NOT NULL,
  lot_no VARCHAR(64) NULL,
  expiry_date DATE NULL,
  from_location_id BIGINT UNSIGNED NULL,
  to_location_id BIGINT UNSIGNED NOT NULL,
  qty_each INT NOT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL
);
```

### 2.4 移動・棚卸・容器・監査

```sql
CREATE TABLE wms_moves (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  warehouse_id BIGINT UNSIGNED NOT NULL,
  from_location_id BIGINT UNSIGNED NOT NULL,
  to_location_id BIGINT UNSIGNED NOT NULL,
  item_id BIGINT UNSIGNED NOT NULL,
  lot_no VARCHAR(64) NULL,
  expiry_date DATE NULL,
  qty_each INT NOT NULL,
  reason ENUM('REPLENISH','RELOCATE','CORRECT') NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NULL
);

CREATE TABLE wms_counts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  warehouse_id BIGINT UNSIGNED NOT NULL,
  plan_code VARCHAR(32) NOT NULL,
  status ENUM('PLANNED','COUNTING','RECONCILED','POSTED') NOT NULL DEFAULT 'PLANNED',
  scheduled_on DATE NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL
);

CREATE TABLE wms_count_lines (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  count_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  item_id BIGINT UNSIGNED NOT NULL,
  lot_no VARCHAR(64) NULL,
  expiry_date DATE NULL,
  book_qty_each INT NOT NULL,
  counted_qty_each INT NOT NULL,
  diff_qty_each INT NOT NULL,
  reason_code VARCHAR(32) NULL,
  status ENUM('UNCHECKED','CONFIRMED','POSTED') NOT NULL DEFAULT 'UNCHECKED',
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  INDEX idx_count (count_id)
);

CREATE TABLE wms_container_histories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  warehouse_id BIGINT UNSIGNED NOT NULL,
  contractor_id BIGINT UNSIGNED NULL,
  buyer_id BIGINT UNSIGNED NULL,
  container_code VARCHAR(64) NOT NULL,
  direction ENUM('PICKUP','RETURN') NOT NULL,
  ref_type ENUM('CONTAINER_PICKUP','CONTAINER_RETURN') NOT NULL,
  ref_id BIGINT UNSIGNED NOT NULL,
  qty INT NOT NULL,
  processed_at DATETIME NOT NULL,
  created_at TIMESTAMP NULL
);

CREATE TABLE wms_audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_id BIGINT UNSIGNED NOT NULL,
  action VARCHAR(64) NOT NULL,
  target_type VARCHAR(64) NOT NULL,
  target_id BIGINT UNSIGNED NOT NULL,
  payload_json JSON NULL,
  created_at TIMESTAMP NULL,
  INDEX idx_target (target_type, target_id)
);

CREATE TABLE wms_attachments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  target_type VARCHAR(64) NOT NULL,
  target_id BIGINT UNSIGNED NOT NULL,
  path VARCHAR(512) NOT NULL,
  mime_type VARCHAR(64) NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NULL
);
```

## 3. ビュー

```sql
CREATE OR REPLACE VIEW wms_v_stock_available AS
SELECT
  rs.id AS real_stock_id,
  rs.client_id, rs.warehouse_id, rs.stock_allocation_id, rs.item_id,
  rs.expiration_date, rs.received_at,
  rs.purchase_id, rs.price AS unit_cost,
  rs.current_quantity,
  GREATEST(rs.available_quantity - (rs.wms_reserved_qty + rs.wms_picking_qty), 0) AS available_for_wms,
  rs.wms_reserved_qty,
  rs.wms_picking_qty
FROM real_stocks rs;
```

> 備考: `unit_cost` は当面 `real_stocks.price` を利用。将来別単価に切替可能。

## 4. 引当アルゴリズム（FEFO→FIFO）

1. 候補抽出: `warehouse_id, item_id` で `wms_v_stock_available` を検索、`available_for_wms > 0` のみ。
2. ソート: `expiration_date ASC (NULL最後)` → `received_at ASC` → `real_stock_id ASC`。
3. 必要数量を満たすまで **`wms_reservations` 行を作成**。トランザクション内で `real_stocks.wms_reserved_qty` を同時加算。
4. 予約確定後に `wms_picking_tasks/_lines` へ展開（ロケ順に並替）。

## 5. API（概略）

* `POST /waves/generate` … 波生成（便×エリア×温度×締切）
* `POST /allocations` … 引当実行（予約＋real_stocks拘束量増分）
* `POST /picking-tasks` … 予約→ピックタスク化（ロケ順最適化）
* `POST /ship-confirms` … 出荷確定（予約CONSUMED、real_stocksピッキング拘束減）
* `POST /receipts/confirm` … 受入確定
* `POST /putaway/confirm` … 棚入れ確定
* `POST /counts/close` … 棚卸差異確定

## 6. 画面（MVP）

* 波動一覧/生成、出荷指示パネル（欠品/例外表示）
* ピッキング（ロケ順/スキャン、差異記録）
* 受入/棚入れ（検品、提案→実績）
* 在庫照会（予約控除、ロット/期限）
* 棚卸（計画→実績→差異確定）
* ログ/添付（証跡）

## 7. 段階実装（STEP）

1. STEP1: 予約基盤（`wms_reservations`）＋在庫ビュー＋引当API（拘束加算含む）
2. STEP2: 波生成・出荷指示パネル
3. STEP3: ピッキング（タスク/行、スキャン、差異）
4. STEP4: 出荷確定・COGS集計（`unit_cost = price` 初期運用）
5. STEP5: 入荷・棚入れ
6. STEP6: 棚卸・調整
7. STEP7: 容器履歴・KPI

## 8. テスト観点

* 予約前後で `available_for_wms` が減少すること
* 予約と `real_stocks.wms_reserved_qty` の整合（和が一致）
* FEFO→FIFO のソート結果確認（期限同日→入荷早い順）
* ピック開始/取消/出荷確定での拘束量遷移
* 例外系: 欠品・差異・同時更新（`wms_lock_version` 検出）
