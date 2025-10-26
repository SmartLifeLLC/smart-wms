# 02. ピッキング・在庫拘束フェーズ仕様書

## 🎯 目的
波動で生成された出荷伝票（`earnings`）に基づき、倉庫現場で
**伝票単位のピッキングと在庫拘束を行うフェーズ**の詳細仕様を定義する。

---

## 1️⃣ 概要

| 項目 | 内容 |
|------|------|
| 対象 | 波動 (`wms_waves`) に紐づく伝票 (`earnings`) |
| 出荷単位 | 倉庫 × 配送コース × 波動 |
| ピッキング単位 | 伝票 (`earning_id`) |
| 実績単位 | 商品 (`trade_item_id`) |
| 在庫拘束 | `wms_reservations` |
| 作業記録 | `wms_picking_item_results` |

---

## 2️⃣ テーブル関連図

```

wms_waves
└─< wms_picking_tasks（伝票単位）
└─< wms_picking_item_results（商品単位）
└─< wms_reservations（在庫拘束）

````

---

## 3️⃣ テーブル定義要約
### 'wms_pickers' => picking users
### `wms_pickers`
| カラム                    | 内容            |
|------------------------|---------------|
| `id`                   | 主キー           |
| `default_warehouse_id` | デフォルト倉庫       |
| `name`                 | ピッカー名         |
| `code`                 | ピッカー識別コード     |
| `password`             | android ログイン用 |
 | created_at | 
 |updated_at |
 | last_login_at|
### `wms_picking_tasks`
| カラム         | 説明 |
|-------------|------|
| `id`        | 主キー |
| `wave_id`   | 波動ID |
| `warehouse_id` | 倉庫 |
| `earning_id` | 対応伝票 |
| `trade_id`  | 取引ID |
| `status`    | `PENDING` / `PICKING` / `SHORTAGE` / `COMPLETED` |
| `task_type` | `WAVE`（通常） / `REALLOCATION`（再配分） |
| `picker_id` | ピッカー |

### `wms_picking_item_results`
| カラム               | 説明 |
|-------------------|--|
| `picking_task_id` | タスクID |
| `trade_item_id`   | 商品明細ID |
| `item_id`         | 商品ID |
| `real_stock_id`   | 実在庫ID |
| `planned_qty`     | 指示数量 |
| `picked_qty`      | 実績数量 |
| `shortage_qty`    | 欠品数量 |
| `status`          | `PICKING` / `COMPLETED` / `SHORTAGE` |
| `picker_id`       | ピッカー |
| created_at        |
| updated_at        |

### `wms_reservations`
| カラム | 説明 |
|--------|------|
| `warehouse_id` | 倉庫 |
| `real_stock_id` | 実在庫ID |
| `item_id` | 商品 |
| `trade_item_id` | 出荷明細 |
| `wave_id` | 波動ID |
| `qty_each` | 数量 |
| `status` | `RESERVED` / `PICKING` / `CONSUMED` / `CANCELLED` |
| `lock_version` | 楽観ロック管理 |

---

## 4️⃣ ピッキングの流れ

1. **波動生成完了後**、`earnings` ごとに `wms_picking_tasks` が生成。
2. 商品ごとに `wms_picking_item_results` に指示（`planned_qty` 登録）。
3. ピッカー（Android / PC）が商品スキャンし数量入力。
4. 入力イベントで：
   - `picked_qty` 更新  
   - 在庫拘束（`wms_reservations`）を `RESERVED → PICKING` に変更  
   - `wms_real_stocks` の `picking_quantity` を加算  
5. 欠品報告 (`shortage_qty>0`) があれば `SHORTAGE` 状態へ。
6. すべて完了で `COMPLETED`、出荷確定待ちに移行。

---

## 5️⃣ 状態遷移

| 対象 | 状態遷移 | トリガー |
|------|------------|-----------|
| **タスク** | `PENDING → PICKING → COMPLETED` | ピッキング開始／完了 |
| **実績行** | `PICKING → COMPLETED` | 数量入力完了 |
| **拘束** | `RESERVED → PICKING → CONSUMED` | 拘束～出庫 |
| **欠品** | `PICKING → SHORTAGE` | ピッカー欠品報告 |

---

## 6️⃣ 在庫更新処理（整合性）

```sql
-- ピッキング開始時
UPDATE wms_real_stocks
SET reserved_quantity = reserved_quantity - :qty,
    picking_quantity = picking_quantity + :qty,
    lock_version = lock_version + 1
WHERE real_stock_id = :id
  AND lock_version = :current_lock;
````

※ `lock_version` 不一致時は再取得（409エラー）

---

## 7️⃣ 排他・冪等

| 機構        | 内容                             |
| --------- | ------------------------------ |
| **悲観ロック** | `SELECT ... FOR UPDATE`（在庫更新時） |
| **楽観ロック** | `lock_version`（在庫整合）           |
| **冪等制御**  | `wms_idempotency_keys`（重複報告防止） |

---

## 8️⃣ 完了条件

| 条件                                            | 意味              |
| --------------------------------------------- | --------------- |
| 全行 `picked_qty + shortage_qty >= planned_qty` | 完了可             |
| 欠品が1行でも存在                                     | タスク `SHORTAGE`  |
| 全行完了かつ欠品なし                                    | タスク `COMPLETED` |

---

## 9️⃣ 監査ログ

* 変更は `wms_op_logs` に記録：

    * 操作種別：`PICKING_START`, `PICKING_UPDATE`, `PICKING_COMPLETE`
    * JSON：before / after / user / idempotency_key

---

## 🔟 備考

* ピッキングステータスは `earnings.picking_status` にも反映される。
* 波動完了後は、欠品処理フェーズ（`03_shortage_reallocation.md`）へ移行。

```

