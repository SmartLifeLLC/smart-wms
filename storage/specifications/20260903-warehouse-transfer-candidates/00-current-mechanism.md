# 倉庫移動候補 現行仕組み調査

- 作成日: 2026-09-03
- 対象: WMS Web、HANDY(DENSO)、基幹の倉庫移動 queue
- 目的: HANDYから送信された任意の倉庫移動を、WMS Webで候補として確認・修正し、基幹の倉庫移動伝票として作成するための前提整理

## 結論

今回の「倉庫移動候補」は、既存の `wms_stock_transfer_candidates` を流用せず、新しいWMS候補ヘッダ/明細として作る。

理由:

- 既存 `wms_stock_transfer_candidates` は自動発注・安全在庫・Hub/Satellite補充の候補で、`batch_code`、安全在庫、発注点、入荷予定作成が前提になっている。
- 横持ち出荷は欠品割当を起点にした「代理倉庫からの出荷」で、任意のA倉庫からB倉庫への移動とは起点も状態管理も異なる。
- 基幹の倉庫移動伝票作成は `stock_transfer_queue` に `action_type=CREATE` を投入すれば既存処理を使える。
- 棚卸しHANDYは「倉庫単位の在庫リスト取得、JAN辞書ローカル照合、履歴送信、request_uuid冪等」の流れができており、倉庫移動HANDYもこの構造を踏襲できる。

## production URL確認

2026-09-03時点で未認証アクセスを確認した。

| URL | 結果 |
|---|---|
| `https://lw-hana.net/stocks/inventory/transfer` | `https://lw-hana.net/login` へリダイレクト |
| `https://wms.lw-hana.net/admin/wms-inventory-counts` | `https://wms.lw-hana.net/admin/login` へリダイレクト |

認証後の画面目視は未実施。画面仕様はローカル実装とDB定義を正とする。

## 既存の棚卸しHANDY連携

WMS API:

- `routes/api.php`
  - `GET /api/wms/inventory-counts`
  - `GET /api/wms/inventory-counts/active`
  - `GET /api/wms/inventory-counts/{id}/items`
  - `GET /api/wms/inventory-counts/{id}/jan-codes`
  - `POST /api/wms/inventory-counts/{id}/scan`
  - `POST /api/wms/inventory-counts/{id}/counts/bulk`
  - `POST /api/wms/inventory-count-items/{itemId}/count`
  - `GET /api/wms/inventory-count-items/{itemId}/logs`

主な実装:

- `app/Http/Controllers/Api/InventoryCountController.php`
- `app/Services/InventoryCount/InventoryCountService.php`
- `app/Models/WmsInventoryCount.php`
- `app/Models/WmsInventoryCountItem.php`
- `app/Models/WmsInventoryCountItemLog.php`

重要な動作:

- HANDYは `warehouse_id` を渡し、`handy_reception=true` かつ `draft/counting` の棚卸しだけを受け取る。
- `items` は1ページ最大500件で取得し、端末側に全件キャッシュする。
- `jan-codes` は `item_search_information` を元に、JAN/検索CDから `item_id`、数量タイプ、入数を引ける辞書を返す。
- `bulkCount` は `request_uuid` を必須にして、`wms_inventory_count_item_logs.request_uuid` の一意性で二重登録を防ぐ。
- HANDYは数量を端末側で総バラ数に換算し、WMS APIは `quantity` があればそれを優先する。

倉庫移動HANDYでは、棚卸しの `inventory_count_id` の代わりに、移動元倉庫、移動先倉庫、送信バッチUUIDを持つ。

## HANDY棚卸しアプリの現状

対象プロジェクト:

- `/Users/jungsinyu/Projects/sakemaru-handy-denso`

確認した実装:

- `feature/main/src/main/java/biz/smt_life/android/feature/main/MainScreen.kt`
  - メインメニューに `移動[3]` は表示済み。
- `app/src/main/java/biz/smt_life/android/sakemaru_handy_denso/navigation/Routes.kt`
  - `Routes.Move("move")` は定義済み。
- `app/src/main/java/biz/smt_life/android/sakemaru_handy_denso/navigation/HandyNavHost.kt`
  - メインから `Routes.Move.route` へ遷移する呼び出しはある。
  - `composable(Routes.Move.route)` は見当たらないため、移動画面は実質未実装。

棚卸し実装:

- `core/network/.../InventoryCountApi.kt`
- `core/network/.../InventoryCountModels.kt`
- `app/.../inventory/InventoryCountState.kt`
- `app/.../inventory/InventoryCountViewModel.kt`
- `app/.../inventory/InventoryCountScreen.kt`

重要な再利用候補:

- API envelope、API key、Sanctum auth、default warehouse取得。
- 全件同期、JAN辞書、ローカル検索、ITF除外。
- `dirtyInputs` と `sentHistory` による未送信/送信済み履歴。
- Fキー操作: F2戻る、F3入力確定、F4履歴/送信。
- 500件チャンク送信、失敗時は未送信履歴を保持。

注意:

- HANDYプロジェクトには既存の未コミット変更がある。仕様策定では変更しない。

## 既存の横持ち出荷

API:

- `routes/api.php`
  - `GET /api/proxy-shipments`
  - `GET /api/proxy-shipments/{id}`
  - `POST /api/proxy-shipments/{id}/start`
  - `POST /api/proxy-shipments/{id}/update`
  - `POST /api/proxy-shipments/{id}/complete`

主な実装:

- `app/Http/Controllers/Api/ProxyShipmentController.php`
- `app/Services/Picking/ProxyShipmentPickingService.php`
- `app/Services/Shortage/StockTransferQueueService.php`
- `app/Models/WmsShortageAllocation.php`

流れ:

1. 欠品から `wms_shortages` と `wms_shortage_allocations` が作られる。
2. HANDYで横持ち出荷分をピックする。
3. 完了時に `StockTransferQueueService::createStockTransferQueue()` が `stock_transfer_queue` を作る。
4. `request_id` は `proxy-shipment-{allocation_id}`。
5. queue item note は `横持ち出荷ID: {allocation_id}`。

今回の倉庫移動との違い:

- 横持ちは欠品・販売伝票・代理出荷が起点。
- 移動先は販売倉庫/実倉庫解決ロジックで決まる。
- 1割当1queueに近い粒度。
- 今回は欠品や販売伝票に紐づかず、HANDYで任意にスキャンした複数明細をWMSで1候補にまとめる。

## 既存の自動発注由来の移動候補

主な実装:

- `app/Models/WmsStockTransferCandidate.php`
- `app/Filament/Resources/WmsStockTransferCandidates/WmsStockTransferCandidateResource.php`
- `app/Services/AutoOrder/TransferCandidateExecutionService.php`

特徴:

- `hub_warehouse_id` から `satellite_warehouse_id` への補充。
- `batch_code`、安全在庫、発注点、入荷予定、ロット判定が前提。
- 確定時に `stock_transfer_queue` を作り、あわせて `WmsOrderIncomingSchedule` も作る。
- `request_id` は単体で `transfer-create-{candidate_id}`、グループで `transfer-create-group-{ids}`。

今回の倉庫移動との違い:

- 今回は自動発注・安全在庫・入荷予定作成が主目的ではない。
- メニューは自動発注カテゴリではなく、WMS Webの `在庫 => 倉庫移動候補`。
- 倉庫移動伝票作成は既存queueを使うが、候補テーブルは分ける。

## 基幹の倉庫移動画面とqueue

対象プロジェクト:

- `/Users/jungsinyu/Projects/sakemaru-ai-core`

Web route:

- `routes/web.php`
  - `GET /stocks/inventory/transfer`
  - `GET /stocks/inventory/transfer/form/{id?}`

画面:

- `resources/views/stocks-inventory-transfers.blade.php`
- `resources/views/stocks-inventory-transfer-form.blade.php`
- `app/Livewire/Tables/StockTransferDatatable.php`
- `app/Livewire/StockTransferTableEditor.php`

queue:

- `app/Models/StockTransferQueue.php`
- `app/Jobs/Polling/ProcessStockTransfer.php`
- `app/Actions/Trades/UpdateStockTransfer.php`

`stock_transfer_queue` の重要カラム:

| カラム | 用途 |
|---|---|
| `client_id` | クライアント |
| `slip_number` | nullなら自動採番 |
| `process_date` | 処理日 |
| `delivered_date` | 納品日/移動予定日 |
| `items` | JSON明細 |
| `from_warehouse_code` | 移動元倉庫CD |
| `to_warehouse_code` | 移動先倉庫CD |
| `delivery_course_id` | 配送コースID |
| `request_id` | 一意な冪等キー |
| `status` | `BEFORE` / `PROCESSING` / `FINISHED` |
| `action_type` | `CREATE` / `UPDATE` / `DELIVER` / `CANCEL` |
| `stock_transfer_id` | 作成された基幹移動ID |

`ProcessStockTransfer` のCREATE処理:

- `from_warehouse_code` と `to_warehouse_code` を倉庫マスタから引く。
- `items` の `item_code`、`quantity`、`quantity_type`、`stock_allocation_code` から `UpdateStockTransfer` 用の行データを作る。
- `UpdateStockTransfer::executeWithTransaction()` で `stock_transfers` と明細を作る。
- 作成時に移動元在庫は減算される。
- 移動先在庫は未納品の場合は増えず、納品/確定時に処理される。

## 新機能への制約

- `stock_transfer_queue.request_id` は必ず一意にし、WMS側の再送/二重確定に耐える。
- queue作成前に、移動元/移動先倉庫CD、配送コース、商品CD、在庫区分CDをWMS側で検証する。
- HANDY送信は候補作成まで。実在庫を直接更新しない。
- Web確定後は明細編集不可にする。編集はqueue投入前まで。
- MVPでは `wms_order_incoming_schedules` を作らない。入荷予定/移動入荷検品まで連動するかは別仕様で決める。

## 未決事項

| 項目 | 推奨 |
|---|---|
| 移動先倉庫の選択タイミング | HANDYの作業開始時に必須選択 |
| 移動元在庫不足時 | MVPは確定ブロック。将来、権限付きで不足許可を検討 |
| 同じfrom/to/dateの複数HANDY送信 | 未確定候補へ集約し、送信バッチUUIDと行UUIDで冪等管理 |
| 配送コース未解決 | 確定ブロックしWebで選択させる |
| 移動先の受入予定作成 | MVP対象外 |

