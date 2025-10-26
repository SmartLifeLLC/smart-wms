# Wave生成時の在庫引当ロジック解説書

## 目次
1. [概要](#概要)
2. [テーブル構成と役割](#テーブル構成と役割)
3. [引当処理フロー](#引当処理フロー)
4. [FEFO→FIFO優先ロジック](#fefofifo優先ロジック)
5. [数量タイプの取り扱い](#数量タイプの取り扱い)
6. [欠品・部分引当の処理](#欠品部分引当の処理)
7. [実装コード解説](#実装コード解説)

---

## 概要

Wave生成（`php artisan wms:generate-waves`）時に、出荷予定の商品に対して在庫を引き当てる処理です。

### 基本方針
- **引当単位**: 伝票明細（`trade_item`）ごと
- **優先順**: FEFO（賞味期限優先） → FIFO（入庫日優先）
- **部分引当**: 可能（在庫不足時は引当可能な分だけ確保）
- **欠品記録**: 在庫ゼロでも記録を残す（監査証跡）

---

## テーブル構成と役割

### 1. コアシステムテーブル（参照のみ）

#### `earnings` - 出荷伝票
```sql
SELECT id, trade_id, warehouse_id, delivery_course_id, delivered_date, picking_status
FROM earnings
WHERE delivered_date = '2025-10-24'
  AND is_delivered = 0
  AND picking_status = 'BEFORE';
```

| カラム | 説明 | Wave生成での使用 |
|--------|------|------------------|
| `id` | 伝票ID | Wave-伝票の紐付け |
| `trade_id` | 取引ID | 商品明細の取得 |
| `warehouse_id` | 倉庫ID | 在庫検索条件 |
| `picking_status` | ピッキング状態 | `BEFORE` → `PICKING` に更新 |

#### `trade_items` - 商品明細
```sql
SELECT id, item_id, quantity, quantity_type
FROM trade_items
WHERE trade_id = ?;
```

| カラム | 説明 | Wave生成での使用 |
|--------|------|------------------|
| `id` | 明細ID | 引当記録のキー |
| `item_id` | 商品ID | 在庫検索条件 |
| `quantity` | 発注数量 | 引当必要数 |
| `quantity_type` | 数量区分 | CASE/PIECE/CARTON |

#### `real_stocks` - 実在庫（コア）
```sql
SELECT id, warehouse_id, item_id, current_quantity, available_quantity,
       expiration_date, purchase_id, price
FROM real_stocks
WHERE warehouse_id = ? AND item_id = ?;
```

| カラム | 説明 | 引当時の動作 |
|--------|------|--------------|
| `id` | 在庫ID | 引当レコードに記録 |
| `current_quantity` | 現在数量 | **変更なし**（出荷確定時のみ減算） |
| `available_quantity` | 利用可能数 | **変更なし** |
| `expiration_date` | 賞味期限 | FEFO優先の基準 |

### 2. WMS管理テーブル

#### `wms_real_stocks` - WMS在庫トラッキング
```sql
CREATE TABLE wms_real_stocks (
  id BIGINT PRIMARY KEY,
  real_stock_id BIGINT UNIQUE,          -- real_stocks.id
  wms_reserved_qty INT DEFAULT 0,       -- WMS引当拘束数（ピッキング未開始）
  wms_picking_qty INT DEFAULT 0,        -- WMSピッキング進行中拘束数
  wms_lock_version INT DEFAULT 0,       -- 楽観ロックバージョン
  created_at TIMESTAMP,
  updated_at TIMESTAMP
);
```

**役割**: 在庫の拘束状態を管理（実在庫は減らさない）

| カラム | 説明 | 引当時の動作 |
|--------|------|--------------|
| `wms_reserved_qty` | 引当済み数量 | **+引当数** を加算 |
| `wms_picking_qty` | ピッキング中数量 | 引当時は0（ピッキング開始時に移動） |

**利用可能在庫の計算式**:
```sql
available_for_wms = rs.available_quantity
                  - wrs.wms_reserved_qty
                  - wrs.wms_picking_qty
```

#### `wms_reservations` - 引当記録
```sql
CREATE TABLE wms_reservations (
  id BIGINT PRIMARY KEY,
  warehouse_id BIGINT NOT NULL,
  real_stock_id BIGINT NULL,            -- 引当元在庫（欠品時はNULL）
  item_id BIGINT NOT NULL,
  qty_each INT NOT NULL,                -- 引当数量
  qty_type ENUM('CASE','PIECE','CARTON'), -- 数量区分
  shortage_qty INT DEFAULT 0,           -- 不足数量
  source_type ENUM('EARNING',...),
  source_id BIGINT,                     -- earning_id
  source_line_id BIGINT,                -- trade_item_id
  wave_id BIGINT,
  status ENUM('RESERVED','PARTIAL','SHORTAGE','RELEASED','CONSUMED','CANCELLED'),
  created_at TIMESTAMP,
  updated_at TIMESTAMP
);
```

**役割**: 引当の明細記録（監査証跡）

| ステータス | 意味 | qty_each | shortage_qty |
|-----------|------|----------|--------------|
| `RESERVED` | 完全引当 | 引当数 | 0 |
| `PARTIAL` | 部分引当 | 0 | 不足数 |
| `SHORTAGE` | 完全欠品 | 0 | 必要数 |

#### `wms_waves` - Wave（ピッキングバッチ）
```sql
CREATE TABLE wms_waves (
  id BIGINT PRIMARY KEY,
  wms_wave_setting_id BIGINT,
  wave_no VARCHAR(50) UNIQUE,           -- W991-C99100001-20251024-1
  shipping_date DATE,
  status ENUM('PENDING','IN_PROGRESS','COMPLETED','CANCELLED'),
  created_at TIMESTAMP
);
```

**役割**: 複数の伝票をまとめてピッキング指示

#### `wms_picking_tasks` - ピッキングタスク
```sql
CREATE TABLE wms_picking_tasks (
  id BIGINT PRIMARY KEY,
  wave_id BIGINT NOT NULL,
  warehouse_id BIGINT,
  earning_id BIGINT,                    -- 対象伝票
  trade_id BIGINT,
  status ENUM('PENDING','IN_PROGRESS','COMPLETED'),
  task_type ENUM('WAVE','EMERGENCY'),
  picker_id BIGINT NULL,
  created_at TIMESTAMP
);
```

**役割**: 伝票単位のピッキング指示

#### `wms_picking_item_results` - ピッキング明細結果
```sql
CREATE TABLE wms_picking_item_results (
  id BIGINT PRIMARY KEY,
  picking_task_id BIGINT NOT NULL,
  trade_item_id BIGINT NOT NULL,        -- 商品明細
  item_id BIGINT NOT NULL,
  real_stock_id BIGINT NULL,

  -- 数量（Wave生成時に設定）
  ordered_qty INT NOT NULL,             -- 発注数量（trade_items.quantity）
  ordered_qty_type ENUM('CASE','PIECE','CARTON'),

  planned_qty INT NOT NULL,             -- 引当済み数量（reservations合計）
  planned_qty_type ENUM('CASE','PIECE','CARTON'),

  -- 数量（ピッキング時にピッカーが設定）
  picked_qty INT DEFAULT 0,             -- 実績ピッキング数量
  picked_qty_type ENUM('CASE','PIECE','CARTON'),

  shortage_qty INT DEFAULT 0,           -- 欠品数量

  status ENUM('PICKING','COMPLETED','SHORTAGE'),
  picker_id BIGINT NULL,

  -- 生成カラム（自動計算）
  has_physical_shortage BOOLEAN GENERATED ALWAYS AS (planned_qty != picked_qty) STORED,

  created_at TIMESTAMP,
  updated_at TIMESTAMP
);
```

**役割**: ピッキング指示と実績の記録

| カラム | 設定タイミング | 意味 |
|--------|---------------|------|
| `ordered_qty` | Wave生成時 | 顧客が注文した数量 |
| `planned_qty` | Wave生成時 | 実際に引き当てできた数量 |
| `picked_qty` | ピッキング時 | ピッカーがスキャンした数量 |
| `shortage_qty` | ピッキング時 | ordered_qty - picked_qty |
| `has_physical_shortage` | 自動計算 | planned_qty ≠ picked_qty（倉庫内在庫相違） |

---

## 引当処理フロー

### 全体の流れ

```
┌─────────────────────────────────────────────────────────────┐
│ 1. Wave生成開始                                              │
│    php artisan wms:generate-waves [--reset]                 │
└─────────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────────┐
│ 2. 対象伝票の抽出                                            │
│    - delivered_date = 今日（または指定日）                   │
│    - is_delivered = 0                                       │
│    - picking_status = 'BEFORE'                              │
│    - warehouse_id, delivery_course_id が wave_settings一致  │
└─────────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────────┐
│ 3. Waveレコード作成                                          │
│    wms_waves:                                               │
│      - wave_no: W{warehouse}-C{course}-{date}-{id}         │
│      - status: PENDING                                      │
└─────────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────────┐
│ 4. 伝票ごとにピッキングタスク作成                             │
│    wms_picking_tasks:                                       │
│      - wave_id                                              │
│      - earning_id                                           │
│      - status: PENDING                                      │
└─────────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────────┐
│ 5. 商品明細ごとに引当処理 ← メインロジック                    │
│    ┌─────────────────────────────────────────────────────┐ │
│    │ 5.1 trade_items 取得                                 │ │
│    │     WHERE trade_id = earning.trade_id               │ │
│    └─────────────────────────────────────────────────────┘ │
│                          ↓                                  │
│    ┌─────────────────────────────────────────────────────┐ │
│    │ 5.2 在庫検索（FEFO→FIFO）                           │ │
│    │     SELECT * FROM real_stocks                       │ │
│    │     LEFT JOIN wms_real_stocks                       │ │
│    │     WHERE warehouse_id = ? AND item_id = ?          │ │
│    │       AND available_for_wms > 0                     │ │
│    │     ORDER BY                                         │ │
│    │       expiration_date IS NULL,  -- NULL は最後      │ │
│    │       expiration_date ASC,      -- FEFO             │ │
│    │       id ASC                    -- FIFO             │ │
│    │     FOR UPDATE;  -- 悲観ロック                      │ │
│    └─────────────────────────────────────────────────────┘ │
│                          ↓                                  │
│    ┌─────────────────────────────────────────────────────┐ │
│    │ 5.3 在庫から順次引当                                 │ │
│    │     needQty = trade_item.quantity                   │ │
│    │     totalAllocated = 0                              │ │
│    │                                                      │ │
│    │     FOR EACH stock:                                 │ │
│    │       allocQty = min(needQty, stock.available)     │ │
│    │       IF allocQty > 0:                              │ │
│    │         - wms_reservations に記録                   │ │
│    │         - wms_real_stocks.wms_reserved_qty += qty  │ │
│    │         - totalAllocated += allocQty               │ │
│    │         - needQty -= allocQty                      │ │
│    │       IF needQty <= 0: BREAK                       │ │
│    └─────────────────────────────────────────────────────┘ │
│                          ↓                                  │
│    ┌─────────────────────────────────────────────────────┐ │
│    │ 5.4 欠品処理                                         │ │
│    │     IF needQty > 0:                                 │ │
│    │       status = totalAllocated>0 ? 'PARTIAL':'SHORTAGE'│
│    │       wms_reservations に欠品レコード追加:          │ │
│    │         - qty_each = 0                              │ │
│    │         - shortage_qty = needQty                    │ │
│    │         - status = PARTIAL or SHORTAGE              │ │
│    └─────────────────────────────────────────────────────┘ │
│                          ↓                                  │
│    ┌─────────────────────────────────────────────────────┐ │
│    │ 5.5 ピッキング明細作成                               │ │
│    │     wms_picking_item_results:                       │ │
│    │       - ordered_qty = trade_item.quantity          │ │
│    │       - planned_qty = totalAllocated               │ │
│    │       - picked_qty = 0  (ピッキング時に設定)        │ │
│    └─────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────────┐
│ 6. 伝票ステータス更新                                        │
│    earnings.picking_status = 'BEFORE' → 'PICKING'          │
└─────────────────────────────────────────────────────────────┘
```

---

## FEFO→FIFO優先ロジック

### 優先順位

1. **FEFO (First Expiry, First Out)**: 賞味期限が近い順
2. **FIFO (First In, First Out)**: 入庫が古い順（同じ賞味期限内）
3. **Tie-breaker**: real_stock_id の昇順

### SQL実装

```sql
SELECT
  rs.id as real_stock_id,
  rs.expiration_date,
  rs.available_quantity,
  rs.purchase_id,
  rs.price,
  COALESCE(wrs.wms_reserved_qty, 0) as reserved_qty,
  COALESCE(wrs.wms_picking_qty, 0) as picking_qty,
  rs.available_quantity - COALESCE(wrs.wms_reserved_qty, 0) - COALESCE(wrs.wms_picking_qty, 0) as available_for_wms
FROM real_stocks rs
LEFT JOIN wms_real_stocks wrs ON rs.id = wrs.real_stock_id
WHERE rs.warehouse_id = ?
  AND rs.item_id = ?
  AND rs.available_quantity - COALESCE(wrs.wms_reserved_qty, 0) - COALESCE(wrs.wms_picking_qty, 0) > 0
ORDER BY
  rs.expiration_date IS NULL,  -- NULL を最後に
  rs.expiration_date ASC,      -- FEFO: 賞味期限が近い順
  rs.id ASC                    -- FIFO: 古い在庫順
FOR UPDATE;  -- 悲観ロック（並行実行対策）
```

### 例

| real_stock_id | item_id | expiration_date | available_for_wms | 引当順序 |
|---------------|---------|-----------------|-------------------|----------|
| 101 | 12345 | 2025-11-15 | 10 | **1位** (最短期限) |
| 102 | 12345 | 2025-12-01 | 20 | **2位** |
| 103 | 12345 | 2025-12-01 | 15 | **3位** (同期限だがID小) |
| 104 | 12345 | NULL | 50 | **4位** (期限なし = 最後) |

---

## 数量タイプの取り扱い

### 数量タイプの種類

| タイプ | 値 | 日本語 | 説明 |
|--------|------|--------|------|
| `CASE` | 'CASE' | ケース | ケース単位（箱） |
| `PIECE` | 'PIECE' | バラ | バラ単位（個別） |
| `CARTON` | 'CARTON' | ボール | ボール単位 |

### 数量タイプの流れ

```
trade_items.quantity_type (注文時)
         ↓
wms_picking_item_results.ordered_qty_type (Wave生成時にコピー)
         ↓
wms_picking_item_results.planned_qty_type (Wave生成時にコピー)
         ↓
wms_reservations.qty_type (Wave生成時にコピー)
         ↓
wms_picking_item_results.picked_qty_type (ピッキング時、デフォルトは同じ)
```

### 実装例

```php
// Wave生成時
DB::table('wms_picking_item_results')->insert([
    'ordered_qty' => $tradeItem->quantity,          // 例: 10
    'ordered_qty_type' => $tradeItem->quantity_type, // 例: 'CASE'
    'planned_qty' => $allocatedQty,                  // 例: 10
    'planned_qty_type' => $tradeItem->quantity_type, // 例: 'CASE'
    'picked_qty' => 0,
    'picked_qty_type' => $tradeItem->quantity_type,  // デフォルト: 'CASE'
]);

DB::table('wms_reservations')->insert([
    'qty_each' => $allocQty,                         // 例: 5
    'qty_type' => $tradeItem->quantity_type,         // 例: 'CASE'
]);
```

---

## 欠品・部分引当の処理

### ケース1: 完全引当（RESERVED）

**シナリオ**: 注文10個、在庫10個以上

```
trade_item.quantity = 10
available_for_wms = 15

引当結果:
  - totalAllocated = 10
  - needQty = 0

wms_reservations:
  qty_each: 10
  shortage_qty: 0
  status: 'RESERVED'

wms_picking_item_results:
  ordered_qty: 10
  planned_qty: 10  ← 完全引当
  shortage_qty: 0
```

### ケース2: 部分引当（PARTIAL）

**シナリオ**: 注文10個、在庫5個のみ

```
trade_item.quantity = 10
available_for_wms = 5

引当結果:
  - totalAllocated = 5
  - needQty = 5

wms_reservations (2レコード):
  1) 引当分:
     qty_each: 5
     shortage_qty: 0
     status: 'RESERVED'

  2) 欠品分:
     qty_each: 0
     shortage_qty: 5
     status: 'PARTIAL'

wms_picking_item_results:
  ordered_qty: 10
  planned_qty: 5   ← 部分引当
  shortage_qty: 0  (ピッキング時に設定)
```

### ケース3: 完全欠品（SHORTAGE）

**シナリオ**: 注文10個、在庫0個

```
trade_item.quantity = 10
available_for_wms = 0

引当結果:
  - totalAllocated = 0
  - needQty = 10

wms_reservations:
  qty_each: 0
  shortage_qty: 10
  status: 'SHORTAGE'

wms_picking_item_results:
  ordered_qty: 10
  planned_qty: 0   ← 引当ゼロ
  shortage_qty: 0  (ピッキング時に10に更新される)
```

### ケース4: 倉庫内在庫相違

**シナリオ**: 引当10個、実際は7個しかない（3個紛失）

```
Wave生成時:
  planned_qty: 10
  picked_qty: 0

ピッキング時:
  planned_qty: 10
  picked_qty: 7    ← ピッカーが実際にスキャンした数
  shortage_qty: 3  ← ordered_qty(10) - picked_qty(7)
  has_physical_shortage: TRUE  ← planned_qty(10) ≠ picked_qty(7)
```

**`has_physical_shortage`フラグの意味**:
- `FALSE`: システム在庫と実在庫が一致
- `TRUE`: **倉庫内在庫相違あり**（盗難・破損・システムエラー）

---

## 実装コード解説

### メインメソッド: `reserveStockForTradeItem()`

```php
protected function reserveStockForTradeItem($waveId, $earning, $tradeItem): int
{
    $needQty = $tradeItem->quantity;  // 必要数量
    $warehouseId = $earning->warehouse_id;
    $itemId = $tradeItem->item_id;
    $totalAllocated = 0;  // 引当済み合計

    // ステップ1: 利用可能在庫を FEFO→FIFO順で取得
    $stocks = DB::connection('sakemaru')
        ->table('real_stocks as rs')
        ->leftJoin('wms_real_stocks as wrs', 'rs.id', '=', 'wrs.real_stock_id')
        ->where('rs.warehouse_id', $warehouseId)
        ->where('rs.item_id', $itemId)
        ->whereRaw('rs.available_quantity > COALESCE(wrs.wms_reserved_qty, 0) + COALESCE(wrs.wms_picking_qty, 0)')
        ->select([
            'rs.id as real_stock_id',
            'rs.expiration_date',
            'rs.available_quantity',
            'rs.purchase_id',
            'rs.price',
            DB::raw('COALESCE(wrs.wms_reserved_qty, 0) as reserved_qty'),
            DB::raw('COALESCE(wrs.wms_picking_qty, 0) as picking_qty'),
            DB::raw('rs.available_quantity - COALESCE(wrs.wms_reserved_qty, 0) - COALESCE(wrs.wms_picking_qty, 0) as available_for_wms')
        ])
        ->orderByRaw('rs.expiration_date IS NULL')  // NULL最後
        ->orderBy('rs.expiration_date', 'asc')      // FEFO
        ->orderBy('rs.id', 'asc')                   // FIFO
        ->lockForUpdate()  // 悲観ロック
        ->get();

    // ステップ2: 在庫から順次引当
    foreach ($stocks as $stock) {
        if ($needQty <= 0) break;

        $allocQty = min($needQty, $stock->available_for_wms);

        if ($allocQty > 0) {
            // 2-1. wms_reservations に引当記録を作成
            DB::connection('sakemaru')->table('wms_reservations')->insert([
                'warehouse_id' => $warehouseId,
                'real_stock_id' => $stock->real_stock_id,
                'item_id' => $itemId,
                'expiry_date' => $stock->expiration_date,
                'purchase_id' => $stock->purchase_id,
                'unit_cost' => $stock->price,
                'qty_each' => $allocQty,
                'qty_type' => $tradeItem->quantity_type ?? 'PIECE',
                'shortage_qty' => 0,
                'source_type' => 'EARNING',
                'source_id' => $earning->id,
                'source_line_id' => $tradeItem->id,
                'wave_id' => $waveId,
                'status' => 'RESERVED',
                'created_by' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 2-2. wms_real_stocks の引当数量を増加
            $wmsRealStock = DB::connection('sakemaru')
                ->table('wms_real_stocks')
                ->where('real_stock_id', $stock->real_stock_id)
                ->first();

            if ($wmsRealStock) {
                // 既存レコード更新
                DB::connection('sakemaru')
                    ->table('wms_real_stocks')
                    ->where('real_stock_id', $stock->real_stock_id)
                    ->update([
                        'wms_reserved_qty' => DB::raw('wms_reserved_qty + ' . $allocQty),
                        'updated_at' => now(),
                    ]);
            } else {
                // 新規レコード作成
                DB::connection('sakemaru')->table('wms_real_stocks')->insert([
                    'real_stock_id' => $stock->real_stock_id,
                    'wms_reserved_qty' => $allocQty,
                    'wms_picking_qty' => 0,
                    'wms_lock_version' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $totalAllocated += $allocQty;
            $needQty -= $allocQty;
        }
    }

    // ステップ3: 欠品処理
    if ($needQty > 0) {
        $status = $totalAllocated > 0 ? 'PARTIAL' : 'SHORTAGE';

        // 欠品レコードを作成
        DB::connection('sakemaru')->table('wms_reservations')->insert([
            'warehouse_id' => $warehouseId,
            'real_stock_id' => null,  // 欠品なので在庫IDはnull
            'item_id' => $itemId,
            'qty_each' => 0,
            'qty_type' => $tradeItem->quantity_type ?? 'PIECE',
            'shortage_qty' => $needQty,
            'source_type' => 'EARNING',
            'source_id' => $earning->id,
            'source_line_id' => $tradeItem->id,
            'wave_id' => $waveId,
            'status' => $status,
            'created_by' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->warn("  {$status} for item {$itemId}: {$needQty} units could not be reserved (allocated: {$totalAllocated})");
    }

    return $totalAllocated;  // 引当済み数量を返す
}
```

### 重要なポイント

1. **悲観ロック (`FOR UPDATE`)**
   - 並行実行時の在庫二重引当を防止
   - トランザクション内で実行必須

2. **実在庫は減らさない**
   - `real_stocks.current_quantity` は**変更しない**
   - `wms_real_stocks.wms_reserved_qty` のみ増加
   - 実在庫の減算は**出荷確定時のみ**

3. **欠品も記録**
   - `qty_each = 0, shortage_qty = 不足数` のレコードを作成
   - 監査証跡として残す
   - 再配分（reallocation）の対象として検索可能

---

## まとめ

### データフロー

```
1. Wave生成
   ↓
2. 在庫検索（FEFO→FIFO）
   ↓
3. wms_reservations 作成
   ↓
4. wms_real_stocks.wms_reserved_qty 増加
   ↓
5. wms_picking_item_results 作成
   ↓
6. earnings.picking_status = 'PICKING'
```

### 在庫状態の遷移

| 段階 | real_stocks.current_quantity | wms_real_stocks.wms_reserved_qty | wms_reservations.status |
|------|------------------------------|----------------------------------|-------------------------|
| 引当前 | 100 | 0 | - |
| 引当後 | 100（変化なし） | +10 | RESERVED |
| ピッキング中 | 100 | reserved→0, picking→+10 | RESERVED |
| 出荷確定 | 90（-10） | 0 | CONSUMED |

### 欠品の区別

| 種類 | 発生タイミング | 検出方法 |
|------|---------------|----------|
| **引当時欠品** | Wave生成時 | `planned_qty < ordered_qty` |
| **ピッキング時欠品** | ピッキング時 | `picked_qty < planned_qty` (has_physical_shortage=TRUE) |

---

**作成日**: 2025-10-25
**バージョン**: 1.0
**対象システム**: Smart WMS - Wave Generation & Stock Allocation
