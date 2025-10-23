# 03. 欠品・再配分フェーズ仕様書

## 🎯 目的
ピッキング中に発生する欠品（こうない欠品）を検知し、  
他倉庫からの再配分または欠品確定を処理するための業務仕様を定義する。

---

## 1️⃣ 概要

| 項目 | 内容 |
|------|------|
| 対象 | ピッキング中に不足が発生した商品行 |
| トリガー | `wms_picking_results.shortage_qty > 0` |
| 主な関連テーブル | `wms_reallocations`, `wms_reservations`, `wms_picking_tasks`, `wms_real_stocks`, `wms_pickers` |
| 担当者区分 | ピッカー（現場）／倉庫管理者／営業担当者 |

---

## 2️⃣ 欠品検出の流れ

1. **ピッカー報告**  
   - AndroidまたはWebピッキング画面でピッキング数量を入力。その数量が指示数量より少ない場合欠品発生状態  
   - `wms_picking_results.shortage_qty` に数量を記録。  
   - `shortage_reason` を入力（例：`NO_STOCK_AT_LOCATION`=>default, `DAMAGED`, `EXPIRED`）。  

2. **タスク状態更新**  
   - `wms_picking_tasks.status = 'SHORTAGE'`
   - `earnings.picking_status = 'SHORTAGE'`

3. **在庫拘束解除**  
   - 該当商品の `wms_reservations` を `RELEASED` に変更。  

4. **欠品ボード反映**  
   - 管理者画面（Web）に表示される「欠品リスト」に即時反映。  

---

## 3️⃣ 再配分処理の全体フロー

```

ピッカー欠品報告
↓
倉庫管理者が欠品リストを確認
↓
他倉庫在庫を検索（リアルタイム）
↓
在庫あり → 仮引当（REALLOCATED_PROVISIONAL）
↓
営業承認 → 本引当（RESERVED）
↓
再配分ピック（task_type='REALLOCATION'）
↓
完了後 出荷確定

```

---

## 4️⃣ 再配分関連テーブル

### `wms_reallocations`
| カラム | 内容 |
|--------|------|
| `id` | 主キー |
| `earning_id` | 出荷伝票 |
| `trade_item_id` | 商品明細 |
| `item_id` | 商品 |
| `requested_from_warehouse_id` | 元倉庫（欠品側） |
| `requested_to_warehouse_id` | 再配分先倉庫 |
| `requested_qty_each` | 数量 |
| `status` | `REQUESTED` / `PROVISIONAL_RESERVED` / `CONFIRMED` / `FAILED` / `CANCELLED` |
| `requested_by` | 営業担当者 |
| `confirmed_by` | 倉庫管理者 |
| `provisional_expires_at` | 仮引当有効期限 |
| `created_at`, `updated_at` | タイムスタンプ |

### `wms_reservations`（再配分用）
| カラム | 説明 |
|--------|------|
| `status` | `REALLOCATED_PROVISIONAL` → `RESERVED` |
| `source_reservation_id` | 元拘束レコード参照 |
| `warehouse_id` | 再配分先倉庫 |
| `provisional_expires_at` | 締切時刻まで有効 |

### `wms_picking_tasks`
| カラム | 内容 |
|--------|------|
| `task_type` | `WAVE` / `REALLOCATION` |
| `picker_id` | ピッカー（`wms_pickers.id`） |
| `reallocation_from_warehouse_id` | 元倉庫（再配分タスク時） |
| `status` | `PENDING` / `PICKING` / `SHORTAGE` / `COMPLETED` |


---

## 5️⃣ ステータス遷移

| 対象 | 状態 | 次状態 | トリガー |
|------|-------|----------|-----------|
| 明細 | `SHORTAGE_REPORTED` | `REALLOCATING` | 管理者が再配分検索開始 |
| 再配分 | `REQUESTED` | `PROVISIONAL_RESERVED` | 他倉庫在庫を仮引当 |
| 再配分 | `PROVISIONAL_RESERVED` | `CONFIRMED` | 営業承認／倉庫確定 |
| 再配分 | `PROVISIONAL_RESERVED` | `CANCELLED` | 締切時間経過（自動解除） |
| 再配分 | `CONFIRMED` | `COMPLETED` | 再配分ピック完了 |
| 再配分 | `PROVISIONAL_RESERVED` | `FAILED` | 他倉庫現地欠品発生 |

---

## 6️⃣ 自動ジョブ

### `wms:reallocation-expire`
- `NOW() > provisional_expires_at` の仮引当を自動解除。
- 対応レコード：
  - `wms_reservations.status = CANCELLED`
  - `wms_reallocations.status = CANCELLED`
  - 明細：`trade_item.status = SHORTAGE_REPORTED`

### `wms:attach-reallocation-to-wave`
- `wms_reallocations.status='CONFIRMED'` のレコードを検出。
- 再配分先倉庫で **次便の `wms_waves`** に自動追加。

---

## 7️⃣ 欠品確定（再配分不可）

| 条件 | 処理 |
|------|------|
| 他倉庫に在庫なし | `wms_reallocations.status = REJECTED` |
| 管理者手動確定 | 明細を `SHORTAGE_CONFIRMED` に更新 |
| WMS上の処理完了 | 販売管理側が納品伝票で欠品印字を実施 |

---

## 8️⃣ 操作権限・監査

| 役割 | 操作 | 備考 |
|------|------|------|
| ピッカー (`wms_pickers`) | 欠品報告 | モバイル／Web |
| 倉庫管理者 | 再配分リクエスト／確認 | 倉庫別欠品リスト画面 |
| 営業担当者 | 再配分承認 | 管理画面 |
| システム | 自動期限解除・再波動割当 | バックグラウンドジョブ |

### 監査ログ
- すべての再配分・欠品確定イベントを `wms_op_logs` に保存  
```

action: 'REALLOCATION_CONFIRM', 'REALLOCATION_CANCEL', 'SHORTAGE_CONFIRMED'
before / after JSON
user_id, warehouse_id, idempotency_key

```

---

## 9️⃣ 冪等性・排他制御

| 対象 | 制御方法 |
|------|------------|
| API再送 | `wms_idempotency_keys(scope='reallocation_confirm', key_hash=sha256(...))` |
| 在庫更新 | `SELECT ... FOR UPDATE` + `wms_real_stocks.lock_version` |
| 同時承認競合 | 最初の確定のみ有効、他は 409 / 412 エラー |

---

## 🔟 欠品・再配分フローまとめ

```

(1) ピッカー欠品報告
↓
(2) 倉庫管理者が欠品ボードで確認
↓
(3) 他倉庫在庫あり → 仮引当 (REALLOCATED_PROVISIONAL)
↓
(4) 営業承認 → 本引当 (CONFIRMED)
↓
(5) 再配分タスク生成（task_type='REALLOCATION'）
↓
(6) ピッキング完了 → 明細完結
↓
(7) 出荷確定（波動または単独）

```

---

## ✅ 備考
- `assigned_picker_id` は廃止し、`picker_id` に統一。
- ピッカー情報は `wms_pickers` に登録済み。
- `picker_id` は `wms_picking_tasks` と `wms_picking_results` 双方に保持。
- 欠品発生から再配分完了までの全工程をリアルタイム監視可能。
```

---

この内容を `/mnt/data/wms_specifications_v1_2025-10-22_md/03_shortage_reallocation.md` に書き出して保存しますか？
