
# 04. 出荷確定フェーズ仕様書

## 🎯 目的
波動（`wms_waves`）に紐づく伝票（`earnings`）のピッキング完了後、  
在庫を確定的に差し引き、納品伝票出力および売上確定（`earnings.is_delivered=1`）を実施する。

---

## 1️⃣ 概要

| 項目 | 内容 |
|------|------|
| フェーズ名 | 出荷確定（Shipment Confirmation） |
| 対象テーブル | `wms_shipments`, `earnings`, `real_stocks`, `wms_reservations` |
| 実行トリガー | 波動内の全伝票が `COMPLETED` または `SHORTAGE_CONFIRMED` |
| 出力帳票 | 納品伝票（販売管理側帳票出力機能） |

---

## 2️⃣ 出荷確定条件

| 条件 | 意味 |
|------|------|
| `wms_picking_tasks.status` = `COMPLETED` | すべてのピッキング完了 |
| 欠品あり (`SHORTAGE_CONFIRMED`) | 欠品確定処理済みであれば出荷可能 |
| 再配分中 (`REALLOCATION`) | 出荷保留（他倉庫ピック完了後に更新） |

**出荷確定対象 =**  
`wave_id` ごとの `wms_picking_tasks` が上記条件を満たしたもの。

---

## 3️⃣ 出荷確定処理フロー

```

(1) 波動完了確認
↓
(2) 在庫確定差引（real_stocks）
↓
(3) wms_reservations → CONSUMED
↓
(4) wms_shipments 登録
↓
(5) earnings.is_delivered = 1
↓
(6) wave.status = CLOSED

````

---

## 4️⃣ テーブル仕様

### `wms_shipments`
| カラム | 内容 |
|--------|------|
| `id` | 主キー |
| `wave_id` | 波動ID |
| `warehouse_id` | 倉庫 |
| `delivery_course_id` | 配送コース |
| `earning_id` | 出荷伝票 |
| `trade_id` | 取引ID |
| `shipped_at` | 出荷確定日時 |
| `status` | `PREPARING` / `CONFIRMED` / `PRINTED` / `SHIPPED` |
| `created_at`, `updated_at` | タイムスタンプ |

---

## 5️⃣ 在庫差引ロジック

### A. 出庫対象
- `wms_reservations.status = PICKING`
- `qty_each` を `real_stocks` から差引。

### B. 処理SQL例
```sql
UPDATE real_stocks rs
JOIN wms_reservations wr ON wr.real_stock_id = rs.id
SET
  rs.qty = rs.qty - wr.qty_each,
  wr.status = 'CONSUMED'
WHERE wr.wave_id = :wave_id
  AND wr.status IN ('PICKING','RESERVED');
````

### C. 排他制御

* `real_stocks` を `SELECT FOR UPDATE`
* `lock_version` による楽観ロック検証

---

## 6️⃣ ステータス遷移まとめ

| 対象                  | 状態          | 次状態              | トリガー    |
| ------------------- | ----------- | ---------------- | ------- |
| `wms_picking_tasks` | `COMPLETED` | —                | 全行ピック完了 |
| `wms_reservations`  | `PICKING`   | `CONSUMED`       | 出庫処理    |
| `wms_shipments`     | `PREPARING` | `CONFIRMED`      | 登録時     |
| `wms_shipments`     | `CONFIRMED` | `SHIPPED`        | 納品伝票出力済 |
| `wms_waves`         | `COMPLETED` | `CLOSED`         | 出荷完了時   |
| `earnings`          | 任意          | `is_delivered=1` | 出荷確定後   |

---

## 7️⃣ 納品伝票出力ルール

| 状況                          | 表示内容              |
| --------------------------- | ----------------- |
| 欠品確定 (`SHORTAGE_CONFIRMED`) | 欠品行を印字（販売管理システム側） |
| 再配分中 (`REALLOCATION`)       | 欠品扱いしない（保留）       |
| 完了 (`COMPLETED`)            | 通常印字（数量確定）        |

納品伝票は販売管理（BoozeCore）側の帳票出力機能で生成。
WMSは出荷確定ステータスを同期するのみ。

---

## 8️⃣ 出荷確定ジョブ

### `wms:confirm-shipments`

* 実行タイミング：各波動のピッキング完了後（自動／手動）
* 処理内容：

    1. 完了条件チェック
    2. 在庫差引・予約消化
    3. `wms_shipments` へ登録
    4. `earnings.is_delivered = 1`
    5. `wms_waves.status = CLOSED`

### ジョブ結果ログ

| 項目                | 説明           |
| ----------------- | ------------ |
| `wave_id`         | 対象波動         |
| `processed_count` | 出荷確定伝票数      |
| `total_qty`       | 出荷数量合計       |
| `errors`          | 処理エラー明細（あれば） |

---

## 9️⃣ 監査・冪等性

| 対象   | 内容                                                         |
| ---- | ---------------------------------------------------------- |
| 操作ログ | `wms_op_logs` に出荷確定記録                                      |
| 冪等性  | `wms_idempotency_keys(scope='shipment_confirm', key_hash)` |
| 競合制御 | `SELECT FOR UPDATE` + `lock_version`                       |

---

## 🔟 出荷確定後の状態一覧

| テーブル                | 更新内容                                         |
| ------------------- | -------------------------------------------- |
| `wms_waves`         | `status='CLOSED'`                            |
| `wms_picking_tasks` | `status='COMPLETED'`                         |
| `wms_reservations`  | `status='CONSUMED'`                          |
| `wms_shipments`     | 新規登録                                         |
| `earnings`          | `is_delivered=1`, `picking_status='SHIPPED'` |
| `real_stocks`       | `qty` 差引後確定保存                                |

---

## ✅ 備考

* 欠品確定済みの伝票でも、在庫更新処理は行わない（数量0扱い）。
* `wms_shipments` は納品伝票番号連携のため、販売管理システム側に同期。
* `wave_id` 単位での一括確定のほか、単伝票確定（手動）も許容。

```

---

この内容をファイルとして  
`/mnt/data/wms_specifications_v1_2025-10-22_md/04_shipment_confirmation.md`  
に保存しておきましょうか？
```
