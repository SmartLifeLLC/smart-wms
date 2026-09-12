# Work Plan: inventory-count-handy-reception

- **ID**: inventory-count-handy-reception
- **作成日**: 2026-06-09
- **最終更新**: 2026-06-10
- **ステータス**: 完了
- **ディレクトリ**: `storage/specifications/20260609/20260609-214021-inventory-count-handy-reception/`

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（`20260609-214021-inventory-count-handy-reception-boot.md`）
2. `20260609-214021-inventory-count-handy-reception-plan.md` を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

`wms_inventory_counts` テーブルに `handy_reception` (boolean) カラムを追加し、倉庫ごとにHANDY受付のON/OFF排他制御を実現する。Web管理画面の棚卸し詳細ページにトグルボタン＋受付バッジ追加。APIは受付ONの棚卸しのみ連動。

## 設計決定事項（仕様書の確認事項の回答）

1. **`isHandyCountable()` の範囲**: 案A採用 — `items()` / `count()` / `bulkCount()` 全てで `handy_reception` チェック
2. **`index()` API**: `warehouse_id` 必須に変更 — パラメータなしはエラーを返す
3. **棚卸し作成時の自動ON**: しない — 手動ONのみ
4. **Bladeバッジ表示**: つける — ヘッダー情報エリアに「HANDY受付中」バッジ

## 重要な設計制約

- FK禁止: `wms_inventory_counts` に外部キーを追加しない
- `migrate:fresh` / `migrate:refresh` 禁止: `php artisan migrate` のみ使用
- Filament 4 インポートパス: `Filament\Actions\Action` を使用
- 排他制御は `warehouse_id` 単位

## 対象ファイル

### 新規作成
- `database/migrations/2026_06_09_XXXXXX_add_handy_reception_to_wms_inventory_counts.php`

### 既存変更
- `app/Models/WmsInventoryCount.php` — `$fillable`, `$casts`, 排他制御メソッド
- `app/Filament/Resources/WmsInventoryCount/Pages/ViewWmsInventoryCount.php` — ヘッダーアクション追加
- `resources/views/filament/resources/wms-inventory-count/pages/view-wms-inventory-count.blade.php` — HANDY受付バッジ
- `app/Http/Controllers/Api/InventoryCountController.php` — `index()` / `show()` / `isHandyCountable()` / `active()`
- `app/Services/InventoryCount/InventoryCountService.php` — `confirm()` / `cancel()` に自動OFF
- `routes/api.php` — `active` エンドポイント追加

### 参照のみ（変更禁止）
- `app/Filament/Resources/WmsInventoryCount/Tables/WmsInventoryCountTable.php`
- `app/Filament/Resources/WmsInventoryCount/Tables/WmsInventoryCountItemTable.php`

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: マイグレーション | 完了 | 2026-06-10 | `handy_reception` カラム追加 |
| P2: モデル変更 | 完了 | 2026-06-10 | WmsInventoryCount に排他制御メソッド |
| P3: サービス変更 | 完了 | 2026-06-10 | confirm/cancel に自動OFF |
| P4: UI変更（アクション＋バッジ） | 完了 | 2026-06-10 | ViewPage ヘッダーアクション + Bladeバッジ |
| P5: API変更 | 完了 | 2026-06-10 | index/show/isHandyCountable/active |
| P6: 動作確認 | 完了 | 2026-06-10 | API全テストパス |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### マイグレーション情報
- マイグレーションファイル名: `2026_06_09_215136_add_handy_reception_to_wms_inventory_counts.php`
- 実行結果: 成功（30.76ms）

### 既存コードの重要な行番号
- `WmsInventoryCount.php` fillable: L26-62, casts: L64-80
- `ViewWmsInventoryCount.php` getHeaderActions: L830-1088
- `InventoryCountController.php` index: L27-55, isHandyCountable: L676-681
- `InventoryCountService.php` confirm: L484-523, cancel: L725-730
- `view-wms-inventory-count.blade.php` ヘッダーバー: L87-113 (ステータスバッジ: L95-97)

### Git ブランチ
- 作業ブランチ: release/v1.0（現在のブランチ）
- ベースブランチ: release/v1.0

---

## Phase完了記録

### P1: マイグレーション
- 完了日: 2026-06-10
- 実績:
  - `2026_06_09_215136_add_handy_reception_to_wms_inventory_counts.php` 作成・実行成功
  - `handy_reception` (boolean, default: false) を `lock_mode` の後に追加

### P2: モデル変更
- 完了日: 2026-06-10
- 実績:
  - `$fillable` に `handy_reception` 追加
  - `$casts` に `'handy_reception' => 'boolean'` 追加
  - `enableHandyReception()` — 同一倉庫の他をOFF → 自身をON
  - `disableHandyReception()` — 自身をOFF
  - `canToggleHandyReception()` — DRAFT/COUNTING のみtrue

### P3: サービス変更
- 完了日: 2026-06-10
- 実績:
  - `confirm()` の `$updates` 配列に `'handy_reception' => false` 追加
  - `cancel()` の `update` 配列に `'handy_reception' => false` 追加

### P4: UI変更（アクション＋バッジ）
- 完了日: 2026-06-10
- 実績:
  - `toggleHandyReception` ヘッダーアクション追加（先頭に配置）
  - ON/OFF確認モーダル（色・ラベル動的切替）
  - Blade ヘッダーバーに「HANDY受付中」バッジ（`bg-green-100 text-green-700`）

### P5: API変更
- 完了日: 2026-06-10
- 実績:
  - `index()` — `warehouse_id` 必須化、`handy_reception=true` フィルター追加
  - `show()` — レスポンスに `handy_reception` フィールド追加
  - `isHandyCountable()` — `handy_reception` チェック追加
  - `active()` — 新規エンドポイント（`GET /api/wms/inventory-counts/active?warehouse_id=X`）
  - `routes/api.php` — `/active` を `{id}` より前に配置

### P6: 動作確認
- 完了日: 2026-06-10
- 実績:
  - API全テスト成功: index(warehouse_id必須), index(フィルター動作), active, show(handy_reception), items(ON/OFF制御)
  - 排他制御: `enableHandyReception()` で同一倉庫の他レコードが自動OFF確認
  - isHandyCountable: handy_reception=false → 422 INVALID_STATUS 確認
