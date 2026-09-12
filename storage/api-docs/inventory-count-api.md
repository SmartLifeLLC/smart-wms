# 棚卸し Handy API 仕様

対象: DENSO Handy 棚卸しスキャンモード

認証: 既存 Handy API と同じく `X-API-Key` と Sanctum Bearer token を使用する。

## 1. カウント中棚卸し一覧

`GET /api/wms/inventory-counts`

カウント中の棚卸しだけを返す。倉庫別に active な棚卸しは WMS 管理画面の作成処理で 1 件に制限する。

レスポンス:

```json
{
  "inventory_counts": [
    {
      "id": 9,
      "count_no": "IC-20260524-XXXXXXXX",
      "warehouse_id": 91,
      "warehouse_code": "91",
      "warehouse_name": "華むすびの蔵センター",
      "count_date": "2026-05-24",
      "status": "counting",
      "status_label": "カウント中",
      "current_round": 1,
      "total_items": 1200,
      "counted_items": 0,
      "final_counted_items": 0
    }
  ]
}
```

## 2. 棚卸し対象データ取得

`GET /api/wms/inventory-counts/{id}/items?page=1&per_page=500&compact=1`

Handy は `per_page=500` で `meta.last_page` までページング取得し、取得後はローカル保存する。`compact=1` の場合は通信量削減のため `search_codes` 配列を返さず、検索用文字列 `search_text` にまとめる。

レスポンス（`compact=0` 時）:

```json
{
  "items": [
    {
      "id": 12345,
      "paper_barcode": "ICITEM-12345",
      "search_text": "ICITEM-12345 S001 商品名 4901234567890 A-01-01 ...",
      "item_id": 100,
      "item_code": "S001",
      "item_name": "商品名",
      "barcode": "4901234567890",
      "volume": "720",
      "volume_unit": "ML",
      "capacity_case": 12,
      "capacity_carton": null,
      "location": {
        "id": 456,
        "floor_name": "1F",
        "location_no": "A-01-01",
        "code1": "A",
        "code2": "01",
        "code3": "01"
      },
      "system_quantity": 25,
      "system_case_quantity": 2,
      "system_piece_quantity": 1,
      "system_total_piece_quantity": 25,
      "first_count_quantity": null,
      "second_count_quantity": null,
      "final_count_quantity": null,
      "current_count_quantity": null,
      "difference_quantity": null,
      "input_count": 0,
      "last_counted_at": null,
      "search_codes": [
        { "c": "4901234567890", "ct": "JAN", "t": "0", "q": 12 },
        { "c": "14901234567897", "ct": "JAN", "t": "1", "q": 6 }
      ]
    }
  ],
  "meta": { "page": 1, "per_page": 500, "total": 3617, "last_page": 8 }
}
```

> **変更点（2026-05-26）**: 旧 `jan_codes` / `own_codes`（文字列配列）を廃止し、`search_codes` に統合。通信量削減のため、キーは `c`=コード、`ct`=コード種別、`t`=数量種別、`q`=ケース換算入数。
>
> **変更点（2026-05-30）**: `t=0`（バラJAN/PIECE）の `q` は `item_quantity_information.quantity` ではなく `items.capacity_case` を返す。`t=1`（ケースJAN/CASE）の `q` はJANコードに紐づく `item_quantity_information.quantity` を返す。

## 2-2. JANコード辞書取得（新規）

`GET /api/wms/inventory-counts/{id}/jan-codes`

棚卸し対象の全商品について、JANコード → item_id の逆引き辞書を一括取得する。Handy は初期同期時にこの辞書をローカル保存し、バーコードスキャン時のローカル検索に使用する。

レスポンス:

```json
{
  "jan_codes": {
    "4901234567890": [
      { "i": 100, "ct": "JAN", "t": "0", "q": 12 }
    ],
    "14901234567897": [
      { "i": 100, "ct": "JAN", "t": "1", "q": 6 }
    ],
    "4902110352689": [
      { "i": 200, "ct": "JAN", "t": "0", "q": 12 },
      { "i": 301, "ct": "JAN", "t": "0", "q": 24 }
    ]
  }
}
```

| フィールド | 型 | 説明 |
|---|---|---|
| キー | string | JANコード（JAN/ITF/自社CD等、`item_search_information.search_string`） |
| i | integer | 商品ID。items エンドポイントの `item_id` と一致 |
| ct | string | コード種別。例: `JAN` / `OTHER` |
| t | string | `0`=バラ / `1`=ケース / `2`=ボール / `9`=その他 |
| q | integer | ケース入力時の換算入数。`t=0` は `items.capacity_case`、`t=1` はJANコード別入数 |

**JANコードが複数商品にヒットする場合:**

同一コードに複数の `item_id` が紐づく場合がある（上記例の `4902110352689`）。Handy 側で候補リストを表示し、ユーザーに選択させる。

**想定フロー:**

1. 初期同期: `items` → `jan-codes` の順でダウンロード
2. スキャン時: JANコード辞書をローカル検索 → `item_id` で商品特定
3. 複数ヒット時: 候補リストを表示 → ユーザー選択
4. 辞書にない場合: `POST /scan` でサーバー検索にフォールバック

## 3. 商品スキャン検索

`POST /api/wms/inventory-counts/{id}/scan`

棚卸し紙バーコード、JAN、商品CD、商品名、自社CD、商品数量コードから棚卸し明細を検索する。JANコード辞書にないコードのフォールバック検索用。

> **変更点（2026-05-26）**: `item_search_information`（JANコード）によるサーバー検索を追加。13桁ゼロパディング正規化にも対応。

リクエスト:

```json
{
  "keyword": "4901234567890"
}
```

レスポンス:

```json
{
  "items": [
    {
      "id": 12345,
      "paper_barcode": "ICITEM-12345",
      "item_id": 100,
      "item_code": "S001",
      "item_name": "商品名",
      "barcode": "4901234567890",
      "volume": "720",
      "volume_unit": "ML",
      "capacity_case": 12,
      "capacity_carton": null,
      "location": {
        "id": 456,
        "floor_name": "1F",
        "location_no": "A-01-01",
        "code1": "A",
        "code2": "01",
        "code3": "01"
      },
      "system_quantity": 25,
      "system_case_quantity": 2,
      "system_piece_quantity": 1,
      "system_total_piece_quantity": 25,
      "first_count_quantity": null,
      "second_count_quantity": null,
      "final_count_quantity": null,
      "current_count_quantity": null,
      "difference_quantity": null,
      "input_count": 0,
      "last_counted_at": null,
      "search_codes": [
        { "c": "4901234567890", "ct": "JAN", "t": "0", "q": 12 }
      ]
    }
  ]
}
```

## 4. 数量登録

`POST /api/wms/inventory-count-items/{itemId}/count`

Handy はケース数量とバラ数量を入力し、画面上で総バラ数を確認してから送信する。Handy 側は読み込んだコードの `q` を使って `ケース数量 * q + バラ数量` を計算する。サーバ側も `quantity` 未指定時の互換処理として同じ考え方で総バラ数へ換算し、1回目・2回目・最終の該当欄を更新する。

リクエスト:

```json
{
  "case_quantity": 2,
  "piece_quantity": 1,
  "count_round": 3,
  "device_id": "DENSO-001",
  "request_uuid": "550e8400-e29b-41d4-a716-446655440000"
}
```

`count_round`: `1` = 1回目、`2` = 2回目、`3` = 最終。

レスポンス:

```json
{
  "item": {
    "id": 12345,
    "item_code": "S001",
    "item_name": "商品名",
    "capacity_case": 12,
    "system_total_piece_quantity": 25,
    "first_count_quantity": null,
    "second_count_quantity": null,
    "final_count_quantity": 25,
    "current_count_quantity": 25,
    "difference_quantity": 0,
    "input_count": 1
  }
}
```

## 5. n回目終了時の一括送信

`POST /api/wms/inventory-counts/{id}/counts/bulk`

Handy はローカルで入力した明細のみ、n回目終了ボタン押下時に送信する。

```json
{
  "count_round": 1,
  "device_id": "DENSO-001",
  "items": [
    {
      "item_id": 12345,
      "case_quantity": 2,
      "piece_quantity": 1,
      "request_uuid": "550e8400-e29b-41d4-a716-446655440000"
    }
  ]
}
```

## 6. 入力者

Handy 登録時は Sanctum 認証中の `wms_pickers.id` を `wms_inventory_count_item_logs.user_id` に保存する。Web 修正時は `device_id = WEB` として `users.id` を同じ列に保存する。表示側は `device_id` により Handy / Web を判定する。
