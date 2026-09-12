# 仕入伝票キュー データ一括登録仕様

## 概要

`purchase_create_queue` へのデータ登録時、同一伝票として生成すべき明細は **1つのレコード** にまとめる必要があります。

## 問題

明細ごとに別レコードとしてINSERTすると、明細1件につき1伝票が生成されてしまいます。

### NG例：明細ごとに別レコード

```sql
-- これは間違い！3つの伝票が生成されてしまう

-- レコード1
INSERT INTO purchase_create_queue (request_uuid, delivered_date, items, status, ...) VALUES (
    'uuid-1', '2026-01-17',
    '{"supplier_code": "001", "warehouse_code": "10", "details": [{"item_code": "10001", "quantity": 10, "quantity_type": "PIECE"}]}',
    'BEFORE', ...
);

-- レコード2
INSERT INTO purchase_create_queue (request_uuid, delivered_date, items, status, ...) VALUES (
    'uuid-2', '2026-01-17',
    '{"supplier_code": "001", "warehouse_code": "10", "details": [{"item_code": "10002", "quantity": 5, "quantity_type": "CASE"}]}',
    'BEFORE', ...
);

-- レコード3
INSERT INTO purchase_create_queue (request_uuid, delivered_date, items, status, ...) VALUES (
    'uuid-3', '2026-01-17',
    '{"supplier_code": "001", "warehouse_code": "10", "details": [{"item_code": "10003", "quantity": 3, "quantity_type": "PIECE"}]}',
    'BEFORE', ...
);
```

### OK例：同一伝票の明細を1レコードにまとめる

```sql
-- 正しい！1つの伝票に3明細が含まれる

INSERT INTO purchase_create_queue (request_uuid, slip_number, delivered_date, items, status, retry_count, created_at, updated_at) VALUES (
    'uuid-batch-001',
    '91461018629',
    '2026-01-17',
    '{
        "process_date": "2026-01-17",
        "delivered_date": "2026-01-17",
        "account_date": "2026-01-17",
        "supplier_code": "001",
        "warehouse_code": "10",
        "slip_number": "91461018629",
        "details": [
            {"item_code": "10001", "quantity": 10, "quantity_type": "PIECE", "expiration_date": "2026-03-15"},
            {"item_code": "10002", "quantity": 5, "quantity_type": "CASE", "expiration_date": "2026-04-20"},
            {"item_code": "10003", "quantity": 3, "quantity_type": "PIECE", "expiration_date": "2026-03-10"}
        ]
    }',
    'BEFORE',
    0,
    NOW(),
    NOW()
);
```

## グルーピング基準

以下の項目が **すべて同一** の明細は、1つのレコードの `details` 配列にまとめてください。

| 項目 | 説明 |
|------|------|
| `warehouse_code` | 倉庫コード |
| `supplier_code` | 仕入先コード |
| `slip_number` | 入荷/EOS伝票番号 |
| `delivered_date` | 入荷日 |
| `process_date` | 処理日 |
| `account_date` | 買掛日 |

分納で同じ `slip_number` の納品が複数日に分かれる場合、`delivered_date` が異なるため別レコードとして登録し、仕入伝票も複数生成します。

## WMS側の実装例（擬似コード）

### PHP/Laravel の場合

```php
// 入荷データを取得
$receivingItems = ReceivingItem::where('status', 'pending')->get();

// グルーピング: 倉庫 + 仕入先 + 伝票番号 + 入荷日
$grouped = $receivingItems->groupBy(function ($item) {
    return $item->warehouse_code . '_' . $item->supplier_code . '_' . $item->slip_number . '_' . $item->delivered_date;
});

// グループごとに1レコードをINSERT
foreach ($grouped as $key => $items) {
    $first = $items->first();

    $details = $items->map(function ($item) {
        return [
            'item_code' => $item->item_code,
            'quantity' => $item->quantity,
            'quantity_type' => $item->quantity_type,
            'expiration_date' => $item->expiration_date,  // 賞味期限（任意）
        ];
    })->toArray();

    DB::table('purchase_create_queue')->insert([
        'request_uuid' => Str::uuid()->toString(),
        'slip_number' => $first->slip_number,
        'delivered_date' => $first->delivered_date,
        'items' => json_encode([
            'process_date' => $first->delivered_date,
            'delivered_date' => $first->delivered_date,
            'account_date' => $first->delivered_date,
            'supplier_code' => $first->supplier_code,
            'warehouse_code' => $first->warehouse_code,
            'slip_number' => $first->slip_number,
            'details' => $details,
        ]),
        'status' => 'BEFORE',
        'retry_count' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
```

### SQL（ストアドプロシージャ）の場合

```sql
-- 1. 一時テーブルにグルーピングキーを作成
CREATE TEMPORARY TABLE tmp_purchase_groups AS
SELECT
    warehouse_code,
    supplier_code,
    slip_number,
    delivered_date,
    GROUP_CONCAT(
        JSON_OBJECT(
            'item_code', item_code,
            'quantity', quantity,
            'quantity_type', quantity_type,
            'expiration_date', expiration_date
        )
    ) as details_json
FROM pending_receiving_items
GROUP BY warehouse_code, supplier_code, slip_number, delivered_date;

-- 2. グループごとにINSERT
INSERT INTO purchase_create_queue (request_uuid, slip_number, delivered_date, items, status, retry_count, created_at, updated_at)
SELECT
    UUID(),
    slip_number,
    delivered_date,
    JSON_OBJECT(
        'process_date', delivered_date,
        'delivered_date', delivered_date,
        'account_date', delivered_date,
        'supplier_code', supplier_code,
        'warehouse_code', warehouse_code,
        'slip_number', slip_number,
        'details', JSON_ARRAY(details_json)  -- 注意: 実際はJSONパースが必要
    ),
    'BEFORE',
    0,
    NOW(),
    NOW()
FROM tmp_purchase_groups;
```

## 基幹側の処理

基幹システムは `items` JSON内の `details` 配列を1つの伝票として処理します。

```
purchase_create_queue レコード (details: 3件)
    ↓
基幹システム処理
    ↓
仕入伝票 1枚 (明細: 3行)
```

## 注意事項

### supplier_code 省略時の自動分割

`supplier_code` を省略した場合、基幹システムが商品ごとに仕入先を自動判定し、**仕入先が異なる場合は伝票が分割されます**。

```json
// supplier_code を省略
{
    "warehouse_code": "10",
    "details": [
        {"item_code": "10001", ...},  // → 仕入先A
        {"item_code": "20001", ...},  // → 仕入先B
        {"item_code": "10002", ...}   // → 仕入先A
    ]
}
```

この場合、仕入先Aの伝票と仕入先Bの伝票の2枚が生成されます。

EOS受信データから仕入キューを作る場合、誤った仕入先で仕入伝票を生成しないため、`supplier_code` は省略しません。WMS側で仕入先を確定できない入荷予定はキュー登録対象外として扱います。

### 1伝票あたりの明細数上限

推奨: 1伝票あたり **100明細以下**

大量の明細がある場合は適切に分割してください。

## チェックリスト

- [ ] 同一倉庫・仕入先・伝票番号・入荷日の明細を1レコードにまとめている
- [ ] `slip_number` を `purchase_create_queue.slip_number` と `items.slip_number` の両方に設定している
- [ ] `details` 配列に複数の明細が含まれている
- [ ] `request_uuid` はレコードごとに一意の値を設定している
- [ ] JSON形式が正しい（特に配列のカンマ、括弧）
