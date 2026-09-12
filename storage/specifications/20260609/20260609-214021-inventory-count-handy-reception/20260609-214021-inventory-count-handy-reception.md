# 棚卸しAPI改善 — HANDY受付モード機能

- **作成日**: 2026-06-09
- **ステータス**: ドラフト
- **ディレクトリ**: `storage/specifications/20260609/20260609-214021-inventory-count-handy-reception/`

## 背景・目的

現在、HANDY端末から棚卸しAPIにアクセスすると、`GET /api/wms/inventory-counts` で DRAFT/COUNTING 状態の全棚卸しが返される。同一倉庫に複数の棚卸しが存在する場合、HANDYがどの棚卸しに対してデータを送信すべきか明確でない。

本機能は、倉庫ごとに **1件の棚卸しだけをHANDY受付ON** にする排他制御を追加し、HANDYとの通信対象を明確にする。

**目的**:
1. Web管理画面に「Handy受付」ボタンを追加し、ON/OFFを切り替え可能にする
2. 同一倉庫で他の棚卸しがONの場合、自動的にOFFに切り替える（排他制御）
3. API側でHANDY受付ONの棚卸しのみを返す / 連動させる

## 現状の実装

### DB構造

- `wms_inventory_counts` テーブル: HANDY受付関連カラムなし
- ステータス: `draft` → `counting` → `checked` → `confirmed` / `cancelled`
- `warehouse_id` で倉庫に紐づく

### API

- `GET /api/wms/inventory-counts`: DRAFT/COUNTING の全棚卸しを返す（フィルターなし）
- `GET /api/wms/inventory-counts/{id}/items`: `isHandyCountable()` で DRAFT/COUNTING のみ許可
- `POST /api/wms/inventory-count-items/{itemId}/count`: 同上
- `POST /api/wms/inventory-counts/{id}/counts/bulk`: 同上

### ViewPage ヘッダーアクション

`ViewWmsInventoryCount.php:830-1088` にヘッダーアクション定義:
- ログ、追加、現状保存、JAN、指示書、カウント開始、差異計算、差分PDF、未PDF、未0、3回目に戻す、確定、取消

## 変更内容

### 概要

`wms_inventory_counts` テーブルに `handy_reception` カラム（boolean）を追加。Web管理画面の棚卸し詳細ページに「Handy受付」トグルボタンを設置。ONにすると同一倉庫の他の棚卸しは自動OFFになる。APIはHANDY受付ONの棚卸しのみを返すように変更。

### 詳細設計

#### DB変更

**マイグレーション**: `wms_inventory_counts` テーブルにカラム追加

```php
// 新規マイグレーション
Schema::table('wms_inventory_counts', function (Blueprint $table) {
    $table->boolean('handy_reception')->default(false)->after('lock_mode')
          ->comment('HANDY受付ON/OFF — 倉庫単位で排他制御');
});
```

| カラム | 型 | デフォルト | 説明 |
|--------|-----|-----------|------|
| `handy_reception` | `tinyint(1)` | `0` | HANDY受付ON: `1` / OFF: `0` |

#### モデル変更

**`WmsInventoryCount.php`**:

1. `$fillable` に `handy_reception` 追加
2. `$casts` に `'handy_reception' => 'boolean'` 追加
3. 排他制御メソッド追加:

```php
public function enableHandyReception(): void
{
    // 同一倉庫の他の棚卸しをOFFにする
    static::where('warehouse_id', $this->warehouse_id)
        ->where('id', '!=', $this->id)
        ->where('handy_reception', true)
        ->update(['handy_reception' => false]);

    $this->update(['handy_reception' => true]);
}

public function disableHandyReception(): void
{
    $this->update(['handy_reception' => false]);
}

public function canToggleHandyReception(): bool
{
    // DRAFT/COUNTING のみON可能
    return in_array($this->status, [
        self::STATUS_DRAFT,
        self::STATUS_COUNTING,
    ], true);
}
```

#### UI変更

**`ViewWmsInventoryCount.php`** — ヘッダーアクションに「Handy受付」ボタン追加:

```php
Action::make('toggleHandyReception')
    ->label(fn () => $record->handy_reception ? 'Handy受付 ON' : 'Handy受付 OFF')
    ->icon(fn () => $record->handy_reception ? 'heroicon-o-signal' : 'heroicon-o-signal-slash')
    ->color(fn () => $record->handy_reception ? 'success' : 'gray')
    ->visible(fn () => $record->canToggleHandyReception())
    ->requiresConfirmation()
    ->modalHeading(fn () => $record->handy_reception ? 'Handy受付をOFFにする' : 'Handy受付をONにする')
    ->modalDescription(fn () => $record->handy_reception
        ? 'この棚卸しのHANDY受付を停止します。HANDYからの入力は受け付けなくなります。'
        : "この棚卸しのHANDY受付を開始します。\n同じ倉庫（{$record->warehouse_name}）で他にHANDY受付ONの棚卸しがある場合、そちらは自動的にOFFになります。")
    ->action(function () use ($record) {
        if ($record->handy_reception) {
            $record->disableHandyReception();
            Notification::make()->success()->title('Handy受付をOFFにしました')->send();
        } else {
            $record->enableHandyReception();
            Notification::make()->success()->title('Handy受付をONにしました')->send();
        }
        $this->record->refresh();
    }),
```

**ボタン配置**: ヘッダーアクション先頭（「ログ」ボタンの前）に配置し、ステータスバッジの近くで目立つようにする。

**Blade表示**: 棚卸しヘッダー情報エリアに受付状態バッジ表示:
- ON: `bg-green-100 text-green-700` — 「HANDY受付中」
- OFF: 表示なし（デフォルト状態なので非表示）

#### API変更

**`InventoryCountController.php`**:

1. **`index()` メソッド変更** — HANDY受付ONの棚卸しのみ返す:

```php
public function index(Request $request): JsonResponse
{
    $query = WmsInventoryCount::whereIn('status', [
        WmsInventoryCount::STATUS_DRAFT,
        WmsInventoryCount::STATUS_COUNTING,
    ]);

    // warehouse_id パラメータがある場合: その倉庫のHANDY受付ONの棚卸しを返す
    if ($request->filled('warehouse_id')) {
        $query->where('warehouse_id', (int) $request->input('warehouse_id'))
              ->where('handy_reception', true);
    }

    $counts = $query->orderBy('count_date', 'desc')
        ->orderBy('id', 'desc')
        ->get();

    return $this->success([
        'inventory_counts' => $counts->map(fn (WmsInventoryCount $count) => [
            // ...既存フィールド...
            'handy_reception' => (bool) $count->handy_reception,
        ])->values()->all(),
    ]);
}
```

2. **`show()` メソッド変更** — レスポンスに `handy_reception` 追加

3. **`items()` / `count()` / `bulkCount()` メソッド** — `isHandyCountable()` に `handy_reception` チェックを追加:

```php
private function isHandyCountable(WmsInventoryCount $count): bool
{
    return $count->handy_reception
        && in_array($count->status, [
            WmsInventoryCount::STATUS_DRAFT,
            WmsInventoryCount::STATUS_COUNTING,
        ], true);
}
```

4. **新規エンドポイント** — 倉庫指定でHANDY受付中の棚卸し取得:

```
GET /api/wms/inventory-counts/active?warehouse_id={id}
```

このエンドポイントは、指定倉庫でHANDY受付ONの棚卸しを1件返す。HANDYアプリが倉庫選択後に呼ぶ想定。

```php
public function active(Request $request): JsonResponse
{
    $warehouseId = (int) $request->input('warehouse_id');

    if (!$warehouseId) {
        return $this->validationError(['warehouse_id' => ['倉庫IDは必須です']]);
    }

    $count = WmsInventoryCount::where('warehouse_id', $warehouseId)
        ->where('handy_reception', true)
        ->whereIn('status', [
            WmsInventoryCount::STATUS_DRAFT,
            WmsInventoryCount::STATUS_COUNTING,
        ])
        ->first();

    if (!$count) {
        return $this->success(['inventory_count' => null]);
    }

    // show() と同じレスポンス構造
    return $this->success([
        'inventory_count' => [...],
    ]);
}
```

#### ステータス変更時の自動OFF

棚卸しが `confirmed` / `cancelled` に変更された時、`handy_reception` を自動的にOFFにする:

- `InventoryCountService::confirm()` — `handy_reception = false` を追加
- `InventoryCountService::cancel()` — `handy_reception = false` を追加

#### 一覧テーブルへの表示

**`WmsInventoryCountTable.php`** / **`ListWmsInventoryCounts.php`**:

- テーブルにHANDY受付状態バッジ（小さいドット or アイコン）を表示
- ステータス列の隣、もしくはインラインバッジ

### 影響範囲

| ファイル | 影響 |
|---------|------|
| `InventoryCountController.php` | `index()` フィルター変更、`isHandyCountable()` 条件追加、レスポンスフィールド追加 |
| `WmsInventoryCount.php` | カラム追加、排他制御メソッド |
| `ViewWmsInventoryCount.php` | ヘッダーアクション追加 |
| `InventoryCountService.php` | `confirm()` / `cancel()` に自動OFF追加 |
| `WmsInventoryCountTable.php` | バッジ表示追加（任意） |
| HANDYアプリ | API仕様変更に伴う影響（`index` レスポンスに `handy_reception` フィールド追加） |

### 後方互換性

- `index()` API: `warehouse_id` パラメータなしの場合は既存動作を維持（全棚卸し返す）
- 新カラム `handy_reception` のデフォルトは `false`（既存データに影響なし）
- `isHandyCountable()` の条件追加は **破壊的変更**: HANDY受付OFFの棚卸しには `items()` / `count()` / `bulkCount()` でアクセス不可になる

## 制約

1. **FK禁止**: `wms_inventory_counts` テーブルに外部キーを追加しない
2. **`migrate:fresh` / `migrate:refresh` 禁止**: 新規マイグレーションのみ使用
3. **排他制御は倉庫単位**: `warehouse_id` が同じ棚卸し間で排他
4. **Filament 4 のインポートパス**: `Filament\Actions\Action` を使用（`Filament\Tables\Actions\Action` ではない）

## 対象ファイル

### 新規作成

| ファイル | 説明 |
|---------|------|
| `database/migrations/2026_06_09_XXXXXX_add_handy_reception_to_wms_inventory_counts.php` | `handy_reception` カラム追加 |

### 既存変更

| ファイル | 説明 |
|---------|------|
| `app/Models/WmsInventoryCount.php` | `$fillable` / `$casts` 追加、排他制御メソッド追加 |
| `app/Http/Controllers/Api/InventoryCountController.php` | `index()` フィルター、`isHandyCountable()` 条件変更、`active()` 追加、レスポンスフィールド追加 |
| `app/Filament/Resources/WmsInventoryCount/Pages/ViewWmsInventoryCount.php` | 「Handy受付」ヘッダーアクション追加 |
| `app/Services/InventoryCount/InventoryCountService.php` | `confirm()` / `cancel()` に `handy_reception = false` 追加 |
| `routes/api.php` | `active` エンドポイント追加 |
| `resources/views/filament/resources/wms-inventory-count/pages/view-wms-inventory-count.blade.php` | HANDY受付バッジ表示（任意） |

### 参照のみ

| ファイル | 説明 |
|---------|------|
| `app/Filament/Resources/WmsInventoryCount/Tables/WmsInventoryCountTable.php` | 一覧バッジ表示の参考 |
| `app/Filament/Resources/WmsInventoryCount/Tables/WmsInventoryCountItemTable.php` | 明細テーブル構造の参考 |

## 確認事項

1. **`isHandyCountable()` の破壊的変更**: HANDY受付OFFでも `items()` API でデータ取得を許可するか？カウント登録（`count()` / `bulkCount()`）のみ制限するか？
   - 案A: `items()` / `count()` / `bulkCount()` 全てで `handy_reception` チェック（厳密制御）
   - 案B: `count()` / `bulkCount()` のみ制限、`items()` は閲覧のみ許可（柔軟）
   - **推奨: 案A**（HANDYに表示される棚卸しはONのもののみという一貫性）
A
2. **`index()` API の後方互換性**: `warehouse_id` パラメータなしの場合に `handy_reception` フィルターをかけるか？
   - 案A: パラメータなし → 全棚卸し返す（後方互換維持）
   - 案B: パラメータなし → `handy_reception = true` のみ返す
   - **推奨: 案A**（HANDYアプリの既存動作を壊さない）
warehouse_id必須
   
3. **棚卸し作成時の自動ON**: 新規棚卸し作成時に自動で `handy_reception = true` にするか？
   - 手動ONのみ（明示的に操作が必要）
   - 自動ON（作成時に同一倉庫の他をOFFにして自動ON）
   - **推奨: 手動ONのみ**（意図しない切り替え防止）

しない。

4. **Bladeバッジ表示**: 詳細ページのヘッダー情報エリアに「HANDY受付中」バッジを表示するか？ボタンの色変更だけで十分か？
バッジをつける。
