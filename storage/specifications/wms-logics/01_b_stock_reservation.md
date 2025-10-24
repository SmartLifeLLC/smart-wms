# 02a. 在庫引当ロジック仕様（波動生成時）

## 🎯 目的
波動（`wms_waves`）生成時に、対象となる出荷伝票（`earnings`）の各商品行（`trade_items`）に対して、
倉庫内在庫（`real_stocks`）を参照し、数量ベースで **在庫拘束（引当）** を行う。
結果は `wms_reservations` に記録し、倉庫在庫を `wms_real_stocks` 経由でロックする。

---

## 1️⃣ 引当の基本方針

| 項目 | 方針 |
|------|------|
| 引当単位 | 伝票明細（`trade_item_id`） |
| 優先ロット | **FEFO**（最短賞味期限順） |
| 優先倉庫 | `earnings.warehouse_id`（原則固定） |
| 倉庫内優先順位 | `wms_locations.walking_order ASC`（近接順） |
| 部分引当 | 可能（在庫が足りない場合は不足分を残す） |
| 在庫ゼロ時 | 欠品として `wms_reservations` は作成しない（後の再配分対象） |

---

## 2️⃣ 処理フロー（波動生成時）

```
(1) 対象伝票（earnings）抽出
      ↓
(2) 各伝票明細（trade_items）ループ
      ↓
(3) 対応倉庫内在庫(real_stocks)を取得（item_id一致）
      ↓
(4) ロット順(FEFO)＋ロケーション順(wms_locations.walking_order)にソート
      ↓
(5) 数量を充足するまで予約
      ↓
(6) wms_reservations にINSERT
      ↓
(7) wms_real_stocks.reserved_quantity を加算
```

---

## 3️⃣ テーブル関連

| テーブル | 主な役割                                        |
|-----------|---------------------------------------------|
| `real_stocks` | 物理在庫の真実。在庫数(`current_quantity/available_quantity`)は出荷確定時のみ減算。 |
| `wms_real_stocks` | 引当／ピッキング中拘束を集約キャッシュ。                        |
| `wms_reservations` | 各伝票明細ごとの引当予約を管理。                            |

---

## 4️⃣ 在庫引当アルゴリズム（擬似コード）

```php
foreach ($earnings as $earning) {
    foreach ($earning->tradeItems as $item) {
        $needQty = $item->qty_each;

        $stocks = RealStock::query()
            ->where('warehouse_id', $earning->warehouse_id)
            ->where('item_id', $item->item_id)
            ->where('qty', '>', 0)
            ->orderBy('expiry_date')
            ->orderBy('location_id')
            ->lockForUpdate()
            ->get();

        foreach ($stocks as $stock) {
            $allocQty = min($needQty, $stock->qty);

            if ($allocQty > 0) {
                WmsReservation::create([
                    'warehouse_id' => $earning->warehouse_id,
                    'real_stock_id' => $stock->id,
                    'item_id' => $item->item_id,
                    'trade_item_id' => $item->id,
                    'wave_id' => $wave->id,
                    'qty_each' => $allocQty,
                    'status' => 'RESERVED',
                    'created_by' => 0,
                ]);

                # 物理在庫は減らさず拘束数のみ増加
                WmsRealStock::where('real_stock_id', $stock->id)
                    ->increment('reserved_quantity', $allocQty);

                $needQty -= $allocQty;
            }

            if ($needQty <= 0) break;
        }

        if ($needQty > 0) {
            logShortage($earning->id, $item->item_id, $needQty);
        }
    }
}
```

---

## 5️⃣ FEFO（賞味期限優先）ロジック

- `ORDER BY expiry_date ASC, lot_no ASC`
- `expiry_date` が NULL の場合は最後に回す。
- **目的**：先に賞味期限の短いロットから出庫する。

---

## 6️⃣ ロケーション優先ロジック

- 同一ロット内では `wms_locations.walking_order ASC` で並べる。
- 倉庫マネージャが倉庫レイアウト順を事前に設定。
- 結果として、ピッキング時の順路に一致する在庫拘束が生成される。

---

## 7️⃣ 引当・出庫の責務分離

| 状態 | `real_stocks.qty` | `wms_real_stocks.reserved_quantity` | `wms_reservations.status` |
|------|-------------------|--------------------------------------|----------------------------|
| 引当直後 | 変化なし | ＋N | `RESERVED` |
| ピッキング中 | 変化なし | reserved→0, picking→＋N | `PICKING` |
| 欠品解除 | 変化なし | −N | `CANCELLED` |
| 出荷確定 | −N | −N | `CONSUMED` |

引当時点では実在庫(`real_stocks.qty`)を変更せず、
`wms_real_stocks.reserved_quantity` のみを加算して拘束を表現する。

---

## 8️⃣ 在庫差引処理（出荷確定時）

```sql
-- 出荷確定時（波動単位）
UPDATE real_stocks rs
JOIN wms_reservations wr ON wr.real_stock_id = rs.id
SET rs.qty = rs.qty - wr.qty_each,
    wr.status = 'CONSUMED'
WHERE wr.wave_id = :wave_id
  AND wr.status IN ('RESERVED','PICKING');
```

---

## 9️⃣ トランザクション管理

- 単位：`wave_id`（波動単位）
- ロック：`SELECT FOR UPDATE` による悲観ロック
- ロールバック：波動生成異常時は全拘束を取り消す
- 冪等性：`wms_idempotency_keys(scope='wave_reservation', key_hash)` による制御

---

## 🔟 備考

- 引当完了後、`earnings.picking_status = 'WAVE_RESERVED'` に更新。
- 欠品明細は `wms_reallocations.status='REQUESTED'` に登録。
- 販売管理システム（BoozeCore）との在庫整合性を維持するため、
  **物理在庫(`real_stocks.qty`)は出荷確定まで減算しない**。
