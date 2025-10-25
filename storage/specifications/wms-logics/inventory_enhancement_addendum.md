
````markdown
# 📘 追加仕様書：在庫・ロケーション・入荷拡張設計
**Smart WMS — Inventory Enhancement Addendum**  
Version: 2025-10-26  
  

---

## 目次
1. [目的](#目的)
2. [在庫引当におけるケース／バラ区分対応](#在庫引当におけるケースバラ区分対応)
3. [ロケーション属性の拡張（wms_locations）](#ロケーション属性の拡張wms_locations)
4. [locations と wms_locations の関係](#locations-と-wms_locations-の関係)
5. [入荷処理時の在庫増加ロジック](#入荷処理時の在庫増加ロジック)
6. [データ整合と責務分離](#データ整合と責務分離)
7. [トランザクション・冪等・ロック制御](#トランザクション冪等ロック制御)

---

## 🎯 目的
本追加仕様は、WMS出荷システムにおいて以下の3点を補強する目的で策定された。

1. 在庫引当時に**ケース／バラ単位（qty_type）** を区別する  
2. 倉庫ロケーション（`locations`）に**ピッキング属性・動線情報**を付与  
3. 入荷（仕入・補充）時に**在庫を増加し、WMS管理テーブルに同期**

これにより、波動生成～出荷確定までの在庫データフローが一貫して管理可能になる。

---

## 1️⃣ 在庫引当におけるケース／バラ区分対応

### 背景
倉庫では「ケース在庫」「バラ在庫」が異なる棚に格納されており、  
同一商品でも単位によりロケーションが異なる。

### 対応方針
在庫引当時、注文明細の `trade_items.quantity_type` に基づき  
「ケース棚」または「バラ棚」から在庫を引き当てる。

---

### 実装設計

```sql
-- 在庫検索条件に picking_unit_type を追加
SELECT rs.id, rs.item_id, rs.available_quantity
FROM real_stocks rs
JOIN wms_locations wl ON wl.location_id = rs.location_id
WHERE rs.warehouse_id = :warehouse_id
  AND rs.item_id = :item_id
  AND wl.picking_unit_type IN (:quantity_type, 'BOTH')
ORDER BY rs.expiration_date ASC, wl.walking_order ASC
FOR UPDATE;
````

| フィールド                       | 値                               | 説明        |
| --------------------------- | ------------------------------- | --------- |
| `wl.picking_unit_type`      | `'CASE'` / `'PIECE'` / `'BOTH'` | 引当可能な在庫単位 |
| `trade_items.quantity_type` | `'CASE'` / `'PIECE'`            | 顧客の注文単位   |

---

## 2️⃣ ロケーション属性の拡張（`wms_locations`）

### カラム追加

```sql
ALTER TABLE wms_locations
ADD COLUMN picking_unit_type ENUM('CASE','PIECE','BOTH') DEFAULT 'BOTH'
COMMENT '引当可能な単位: ケース／バラ／両方';
```

| カラム                 | 説明                 |
| ------------------- | ------------------ |
| `picking_unit_type` | 引当可能単位（ケース／バラ／両方）  |
| `walking_order`     | 倉庫内動線順序（通路→棚→段）    |
| `zone_code`         | 温度帯・エリア区分（常温／冷蔵など） |

### 例

| location_id | zone_code | aisle | rack | level | picking_unit_type | walking_order |
| ----------- | --------- | ----- | ---- | ----- | ----------------- | ------------- |
| 1001        | 常温        | 1     | 1    | 1     | CASE              | 1001          |
| 1002        | 常温        | 1     | 2    | 1     | PIECE             | 1002          |
| 1003        | 冷蔵        | 2     | 1    | 1     | BOTH              | 2001          |

---

## 3️⃣ `locations` と `wms_locations` の関係

| 観点   | `locations`                                         | `wms_locations`      |
| ---- | --------------------------------------------------- | -------------------- |
| 管理主体 | 販売管理（BoozeCore）                                     | WMS（倉庫運用）            |
| 関係性  | **1:1**（`wms_locations.location_id = locations.id`） |                      |
| 目的   | 基本マスタ（名称・倉庫・コード）                                    | WMS属性（ゾーン・棚構造・動線・単位） |
| 更新頻度 | 低（レイアウト変更時）                                         | 中（運用改善・動線調整）         |

### ER図

```
locations (販売管理)
   ├─ id (PK)
   ├─ warehouse_id
   ├─ name / code1 / code2 / code3
   └─ ...
         │
         ▼ (1:1)
wms_locations (WMS拡張)
   ├─ id (PK)
   ├─ location_id (FK)
   ├─ zone_code / aisle / rack / level
   ├─ picking_unit_type
   ├─ walking_order
   └─ ...
```

---

## 4️⃣ 入荷処理時の在庫増加ロジック

### 処理概要

```
(1) 仕入伝票登録（purchases / purchase_items）
(2) 入荷検品（wms_receipts）
(3) 実在庫更新（real_stocks）
(4) WMS同期（wms_real_stocks）
(5) ロケーション情報適用（wms_locations）
```

### 主要処理SQL

#### ① 実在庫更新

```sql
INSERT INTO real_stocks (
    client_id, warehouse_id, stock_allocation_id,
    location_id, item_id, current_quantity, available_quantity,
    expiration_date, purchase_id, created_at, updated_at
)
VALUES
(:client_id, :warehouse_id, :stock_allocation_id,
 :location_id, :item_id, :qty, :qty, :expiration_date, :purchase_id, NOW(), NOW())
ON DUPLICATE KEY UPDATE
    current_quantity = current_quantity + VALUES(current_quantity),
    available_quantity = available_quantity + VALUES(available_quantity),
    updated_at = NOW();
```

#### ② WMS同期

```sql
INSERT INTO wms_real_stocks (real_stock_id, wms_reserved_qty, wms_picking_qty, wms_lock_version, created_at, updated_at)
SELECT rs.id, 0, 0, 0, NOW(), NOW()
FROM real_stocks rs
LEFT JOIN wms_real_stocks wrs ON rs.id = wrs.real_stock_id
WHERE wrs.real_stock_id IS NULL;
```

#### ③ ロケーション反映

```sql
SELECT picking_unit_type, walking_order, zone_code
FROM wms_locations
WHERE location_id = :location_id;
```

---

## 5️⃣ データ整合と責務分離

| データ層    | 管理テーブル                        | 役割              |
| ------- | ----------------------------- | --------------- |
| 販売管理層   | `real_stocks`                 | 物理在庫の真実         |
| WMS拘束層  | `wms_real_stocks`             | 引当／ピッキング中拘束の可視化 |
| 引当明細層   | `wms_reservations`            | 引当・出庫・欠品の記録     |
| ロケーション層 | `locations` + `wms_locations` | 倉庫構造・棚属性の統合     |

### 在庫状態の変化例

| フェーズ   | real_stocks.current_quantity | wms_real_stocks.wms_reserved_qty | 備考      |
| ------ | ---------------------------- | -------------------------------- | ------- |
| 入荷確定   | +N                           | 変化なし                             | 在庫増加    |
| 引当時    | 変化なし                         | +N                               | WMS拘束   |
| ピッキング中 | 変化なし                         | reserved→0, picking→+N           | ピッキング拘束 |
| 出荷確定   | -N                           | -N                               | 在庫消費    |

---

## 6️⃣ トランザクション・冪等・ロック制御

| 項目         | 方針                                                                            |
| ---------- | ----------------------------------------------------------------------------- |
| トランザクション単位 | 波動（`wave_id`）または入荷バッチ単位                                                       |
| 排他制御       | `SELECT FOR UPDATE` による悲観ロック                                                  |
| 冪等制御       | `wms_idempotency_keys(scope='receiving' or 'wave_reservation', key_hash=...)` |
| ロールバック     | 入荷確定中の障害時には全在庫追加・拘束変更をrollback                                                |

---

## ✅ まとめ

| 機能区分     | 方針概要                                               |
| -------- | -------------------------------------------------- |
| 引当単位     | ケース／バラを `picking_unit_type` によって区別                 |
| 在庫増加     | 入荷確定で `real_stocks` 数量増加、`wms_real_stocks` 自動同期    |
| 実在庫減算    | 出荷確定時のみ                                            |
| ロケーション属性 | `wms_locations` にゾーン・棚・動線・単位属性を保持                  |
| 更新責務     | BoozeCoreがマスタ（`locations`）、WMSが拡張（`wms_locations`） |
| データ整合    | 1:1関係を維持し、倉庫ごとの単位・ゾーン管理を実現                         |

```
