# 倉庫移動候補 仕様書セット

- 作成日: 2026-09-03
- 目的: HANDYから届いた任意の倉庫移動をWMS Webで候補確認し、基幹の倉庫移動伝票として作成する。

## 読む順番

1. `00-current-mechanism.md` — 現行の棚卸しHANDY、横持ち、自動発注由来移動候補、基幹queueの調査結果。
2. `01-web-spec.md` — WMS Webのメニュー、画面、状態、確定処理。
3. `02-handy-spec.md` — DENSO HANDYの移動画面、同期、スキャン、履歴送信。
4. `03-api-db-spec.md` — API、DB、queue投入仕様。

## 主要判断

- 既存 `wms_stock_transfer_candidates` は自動発注/店間補充専用なので流用しない。
- 新規WMSテーブルは `wms_warehouse_transfer_candidates` 系で作る。
- HANDY送信は候補作成まで。実在庫は更新しない。
- WMS Web確定時に `stock_transfer_queue` へ `action_type=CREATE` を投入する。
- queueの `quantity_type` はMVPでは `PIECE` 固定にし、総バラ数を渡す。
- 移動先の入荷予定作成はMVP対象外。


## 実装時の確定事項（2026-09-03 実装）

- 移動先倉庫は **HANDYの送信時** に選択する（作業開始時ではない）。ローカルキャッシュは移動元倉庫単位 `warehouse_transfer_{from_warehouse_id}`。
- HANDYは送信前に通信チェック（ConnectivityManager + 倉庫一覧API取得）を行い、通信不可なら送信せず未送信履歴を保持する。
- 送信成功時のみ未送信履歴を削除する。500件超は分割送信し、失敗したバッチ以降は必ず未送信に残す。送信済み履歴は端末に保持せず、直前の送信結果（候補番号・件数）だけを表示する。
- Web確定時の在庫不足は **警告のみ**（確定は可能）。配送コース未解決・倉庫同一・商品無効は確定ブロック。
- queue結果の同期は一覧/詳細表示時の投影に加え、`wms:sync-warehouse-transfer-candidates`（5分間隔）で行う。
- 権限: `wms.wms-warehouse-transfer-candidate.{view,create,edit,delete}`（自動スキャン）+ `confirm` / `cancel`（config/sakemaru-auth.php）。

### 実装ファイル

WMS (`sakemaru-wms`):

- `database/migrations/2026_09_03_000000_create_wms_warehouse_transfer_candidate_tables.php`
- `app/Enums/WarehouseTransferCandidateStatus.php`
- `app/Models/WmsWarehouseTransferCandidate*.php`
- `app/Services/WarehouseTransfer/{WarehouseTransferStockListService,WarehouseTransferCandidateReceiveService,WarehouseTransferQueueService,WarehouseTransferStatusSyncService}.php`
- `app/Http/Controllers/Api/WarehouseTransferController.php` + `routes/api.php`
- `app/Console/Commands/SyncWarehouseTransferCandidateStatusCommand.php` + `routes/console.php`
- `app/Filament/Resources/WmsWarehouseTransferCandidates/**`
- `resources/views/filament/resources/wms-warehouse-transfer-candidates/confirm-modal.blade.php`
- `tests/Feature/Api/WarehouseTransferCandidateApiTest.php`

HANDY (`sakemaru-handy-denso`):

- `core/network/.../api/WarehouseTransferApi.kt`, `model/WarehouseTransferModels.kt`, `di/NetworkModule.kt`
- `app/.../warehousetransfer/{WarehouseTransferState,WarehouseTransferViewModel,WarehouseTransferScreen}.kt`
- `app/.../navigation/HandyNavHost.kt`（`composable(Routes.Move.route)` 追加）
