# HANDY 倉庫移動候補 仕様書

- 作成日: 2026-09-03
- 対象プロジェクト: `/Users/jungsinyu/Projects/sakemaru-handy-denso`
- 対象メニュー: `移動[3]`

## 目的

HANDYで移動元倉庫の在庫リストを取得し、商品スキャンと数量入力を繰り返して、履歴画面からWMSへ倉庫移動候補を送信する。

送信後すぐに基幹在庫を更新しない。WMS Webで候補を確認・修正・確定した時点で、基幹の倉庫移動伝票が作成される。

## 現状

- メインメニューには `移動[3]` が存在する。
- `Routes.Move("move")` は存在する。
- `HandyNavHost` にはメインから `Routes.Move.route` への遷移がある。
- `composable(Routes.Move.route)` は見当たらないため、移動画面は未実装。
- 棚卸し機能は、同期、スキャン、数量入力、履歴送信、失敗時保持まで実装済み。

## 画面構成

棚卸し画面と同じ操作感にする。

| タブ/画面 | 役割 |
|---|---|
| メニュー | 移動元/移動先、同期状態、未送信件数を表示 |
| 移動先選択 | 移動先倉庫を選択 |
| スキャン | 商品CD/JAN/バーコードを読んで商品を確定 |
| 数量入力 | ケース/バラを入力してローカル履歴へ保存 |
| 履歴 | 未送信/送信済みを表示し、F4で送信 |
| 設定 | データ同期、ローカル初期化 |

## 基本フロー

1. HANDYのログイン/設定から自店倉庫を移動元倉庫として取得する。
2. WMS APIから移動元倉庫の在庫リストを取得する。（移動先倉庫は送信時に選択する）
4. WMS APIからJAN辞書を取得する。
5. 商品をスキャンする。
6. ローカル辞書/在庫リストから商品を特定する。
7. ケース/バラ数量を入力する。
8. F3で入力確定し、未送信履歴に保存してスキャン画面へ戻る。
9. 履歴画面でF4送信を押す。
10. 通信チェック（ネットワーク + 倉庫一覧API）。通信不可なら送信せずエラー表示し、未送信履歴を保持する。
11. 移動先倉庫を選択し、F4で送信する。
12. WMSに倉庫移動候補が作成/更新される。
13. 送信成功時のみ未送信履歴を削除する。失敗分は必ず未送信に残す。送信済み履歴は保持せず、直前の送信結果のみ表示する。

## 状態モデル案

`WarehouseTransferState`:

| フィールド | 説明 |
|---|---|
| `activeTab` | `MENU` / `DESTINATION` / `SCAN` / `HISTORY` / `SETTINGS` |
| `loading` | 同期中 |
| `submitting` | 送信中 |
| `fromWarehouseId` | 移動元倉庫ID |
| `fromWarehouseCode` | 移動元倉庫CD |
| `fromWarehouseName` | 移動元倉庫名 |
| `toWarehouseId` | 移動先倉庫ID |
| `toWarehouseCode` | 移動先倉庫CD |
| `toWarehouseName` | 移動先倉庫名 |
| `processDate` | 処理日。初期値は当日 |
| `deliveredDate` | 納品日。初期値は当日 |
| `allItems` | 同期済み在庫リスト |
| `janDictionary` | JAN/検索CD辞書 |
| `selectedItem` | 入力中商品 |
| `scanQuantityType` | スキャンされた数量タイプ |
| `scannedCode` | 読取CD |
| `packageQuantity` | 入数 |
| `caseQuantity` | 入力ケース数 |
| `pieceQuantity` | 入力バラ数 |
| `accumulatedBase` | 同一商品再スキャン時の既存未送信入力 |
| `dirtyInputs` | 未送信履歴 |
| `sentHistory` | 送信済み履歴 |
| `syncedAt` | 最終同期日時 |
| `uploadUuid` | 送信バッチUUID。送信開始ごとに採番 |

`LocalWarehouseTransferInput`:

| フィールド | 説明 |
|---|---|
| `localKey` | `itemId + stockAllocationCode` を基準にしたローカルキー |
| `itemId` | 商品ID |
| `itemCode` | 商品CD |
| `itemName` | 商品名 |
| `barcode` | バーコード |
| `realStockId` | 同期時の在庫ID |
| `locationNo` | ロケーション |
| `stockAllocationCode` | 在庫区分CD。初期値 `1` |
| `caseQuantity` | ケース数 |
| `pieceQuantity` | バラ数 |
| `packageQuantity` | 入数 |
| `totalPieces` | 総バラ数 |
| `availableQuantityAtSync` | 同期時在庫 |
| `searchCode` | 読取/検索CD |
| `requestUuid` | 行単位の冪等UUID |
| `sent` | 送信済みフラグ |
| `updatedAt` | 更新日時 |

## ローカルキャッシュ

移動元/移動先が変わると別キャッシュにする。

キャッシュキー案:

```text
warehouse_transfer_{from_warehouse_id}
```

移動先倉庫は送信時に選択するため、キャッシュは移動元倉庫単位にする。前回選択した移動先はキャッシュに保持し、次回送信時の初期値にする。

保持対象:

- 移動元/移動先倉庫。
- 在庫リスト。
- JAN辞書。
- 未送信履歴。
- 送信済み履歴。
- 最終同期日時。

送信失敗時は未送信履歴を消さない。アプリ再起動後も再送できる。

## 在庫リスト同期

API:

```http
GET /api/wms/warehouse-transfer/stock-items?warehouse_id={fromWarehouseId}&page=1&per_page=500&compact=1
GET /api/wms/warehouse-transfer/jan-codes?warehouse_id={fromWarehouseId}
```

同期対象:

- 移動元倉庫の管理対象商品。
- 利用可能在庫が0より大きい商品を基本にする。
- 必要なら0在庫も検索対象に含めるオプションをWeb/APIで追加する。

在庫リスト項目:

- `id`: ローカル表示/検索用ID。`real_stock_id` があればそれを優先。
- `real_stock_id`
- `item_id`
- `item_code`
- `item_name`
- `barcode`
- `volume`
- `volume_unit_label`
- `capacity_case`
- `capacity_carton`
- `location`
- `stock_allocation_code`
- `available_quantity`
- `case_quantity`
- `piece_quantity`
- `search_codes`

## スキャン仕様

棚卸しと同じ検索優先順位を使う。

1. JAN辞書完全一致。
2. JAN辞書の13桁0埋め一致。
3. 同期済み在庫リストの商品CD完全一致。
4. バーコード完全一致。
5. paper barcode相当の内部コード一致を追加する場合はAPIと同一ルールにする。
6. ローカルで一意に決まらない場合はエラー表示し、Web/API検索にフォールバックしてもよい。

ITFの扱い:

- 棚卸しと同じく、14桁ITFはMVPでは対象外。
- 対応する場合は `item_search_information` の数量タイプと入数が明確なものだけ許可する。

## 数量入力

- ケース/バラ入力を持つ。
- 総バラ数は `caseQuantity * packageQuantity + pieceQuantity`。
- `totalPieces <= 0` は保存不可。
- 同じ商品/在庫区分を再スキャンした場合、未送信履歴に加算する。
- `accumulatedBase` は非永続でよい。再起動時は `dirtyInputs` から復元する。
- 移動元在庫を超える入力は端末で警告する。ただし最終判定はWeb確定時にWMSで行う。

数量タイプ:

- HANDY表示はケース/バラ。
- API送信は総バラ数 `quantity` を必須にする。
- WMS queue投入時も `quantity_type=PIECE` に統一する。

## 履歴画面

未送信:

- 商品CD、商品名、ロケーション、ケース/バラ、総バラ、読取CDを表示。
- 行削除ができる。
- F4で送信。

送信済み:

- 送信時刻、候補番号、商品数、総バラを表示。
- サーバ候補IDを表示できる場合は保持する。

## 送信API

```http
POST /api/wms/warehouse-transfer-candidates
```

リクエスト:

```json
{
  "upload_uuid": "uuid",
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
      "request_uuid": "line-uuid"
    }
  ]
}
```

レスポンス:

```json
{
  "candidate": {
    "id": 1,
    "candidate_no": "WT202609030001",
    "status": "PENDING",
    "from_warehouse_id": 91,
    "to_warehouse_id": 12,
    "item_count": 1,
    "total_quantity": 12
  },
  "accepted_count": 1,
  "missing_item_ids": []
}
```

## 冪等性

- `upload_uuid` は送信単位で一意。通信タイムアウト後の再送は同じ `upload_uuid` を使う。
- `request_uuid` は行単位で一意。同じ行が再送されても二重加算しない。
- API成功後に端末がレスポンス保存前に落ちた場合、同じ `upload_uuid` で再送して同じ候補情報を取得する。

## ハードキー

棚卸しの操作に合わせる。

| キー | メニュー | スキャン/入力 | 履歴 |
|---|---|---|---|
| F1 | スキャン開始 | 数量入力モード切替またはクリア | 未使用 |
| F2 | 戻る | 入力取消/戻る | 戻る |
| F3 | 移動先選択/同期 | 入力確定 | 未使用 |
| F4 | 履歴 | 履歴へ | 送信 |

端末の既存キー割当と競合する場合は、棚卸しと同じ実装を優先する。

## エラー表示

| エラー | HANDY表示 |
|---|---|
| 移動先未選択 | `移動先倉庫を選択してください` |
| 同期データなし | `先に在庫リストを同期してください` |
| 商品なし | `商品が見つかりません` |
| 複数候補 | `複数の商品が該当しました。商品CDで入力してください` |
| 数量0以下 | `数量を入力してください` |
| 送信失敗 | `送信できませんでした。未送信履歴に残しました` |
| Webで確定済み候補への追加不可 | 新しい候補として送信されるため、HANDY側では通常意識しない |

## 実装単位

1. `WarehouseTransferApi` とモデルを追加。
2. `WarehouseTransferState` / `WarehouseTransferViewModel` を追加。
3. 棚卸しの同期/検索/履歴送信ロジックを倉庫移動用に移植する。
4. `WarehouseTransferScreen` を追加。
5. `HandyNavHost` に `composable(Routes.Move.route)` を追加。
6. メインメニューの `移動[3]` を新画面に接続する。
7. ローカルキャッシュを移動元/移動先別に保存する。
8. ViewModel単体テストと実機スキャン確認を行う。

## テスト観点

- 移動元/移動先選択後に在庫リストとJAN辞書を同期できる。
- JANスキャンで商品が一意に決まる。
- 同じ商品を複数回入力すると未送信履歴で加算される。
- 履歴削除で対象行だけ消える。
- 送信成功分だけ未送信から消える。
- 通信失敗時に未送信が残る。
- 同じ `upload_uuid` 再送で候補が重複しない。
- アプリ再起動後に未送信履歴が復元される。

