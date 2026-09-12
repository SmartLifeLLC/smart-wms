# 棚卸しHANDY受付モード機能 作業計画

## 前提

- 仕様書: `20260609-214021-inventory-count-handy-reception.md` 作成済み
- 確認事項の回答済み（boot.md「設計決定事項」参照）
- 現在ブランチ: `release/v1.0`

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | マイグレーション | `handy_reception` カラム追加 | マイグレーション作成・実行成功 |
| P2 | モデル変更 | 排他制御メソッド追加 | メソッド追加、`$fillable` / `$casts` 更新 |
| P3 | サービス変更 | confirm/cancel に自動OFF | 確定・取消時に `handy_reception = false` |
| P4 | UI変更 | ヘッダーアクション＋Bladeバッジ | トグルボタン動作、バッジ表示 |
| P5 | API変更 | index/show/isHandyCountable/active | エンドポイント追加・既存変更 |
| P6 | 動作確認 | Web UI + APIテスト | 全機能の動作検証完了 |

---

## P1: マイグレーション

### 目的

`wms_inventory_counts` テーブルに `handy_reception` (boolean, default: false) カラムを追加する。

### 作業内容

1. マイグレーションファイル作成:

```bash
php artisan make:migration add_handy_reception_to_wms_inventory_counts --table=wms_inventory_counts
```

2. マイグレーション内容:

```php
public function up(): void
{
    Schema::connection('sakemaru')->table('wms_inventory_counts', function (Blueprint $table) {
        $table->boolean('handy_reception')->default(false)->after('lock_mode');
    });
}

public function down(): void
{
    Schema::connection('sakemaru')->table('wms_inventory_counts', function (Blueprint $table) {
        $table->dropColumn('handy_reception');
    });
}
```

**注意**: `connection('sakemaru')` を明示すること。

3. マイグレーション実行:

```bash
php artisan migrate
```

### 完了条件

- マイグレーション実行成功
- `wms_inventory_counts` テーブルに `handy_reception` カラムが存在

---

## P2: モデル変更

### 目的

`WmsInventoryCount` モデルに `handy_reception` のサポートと排他制御メソッドを追加する。

### 修正対象ファイル

- `app/Models/WmsInventoryCount.php`

### 修正内容

1. `$fillable` 配列に `'handy_reception'` を追加（`lock_mode` の後）

2. `$casts` 配列に追加:
```php
'handy_reception' => 'boolean',
```

3. 排他制御メソッド追加:

```php
public function enableHandyReception(): void
{
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
    return in_array($this->status, [
        self::STATUS_DRAFT,
        self::STATUS_COUNTING,
    ], true);
}
```

### 完了条件

- `$fillable` / `$casts` に `handy_reception` が含まれている
- `enableHandyReception()` / `disableHandyReception()` / `canToggleHandyReception()` が定義されている

---

## P3: サービス変更

### 目的

棚卸し確定・取消時に `handy_reception` を自動的にOFFにする。

### 修正対象ファイル

- `app/Services/InventoryCount/InventoryCountService.php`

### 修正内容

1. **`confirm()` メソッド** (L484-523):
   - `$updates` 配列に `'handy_reception' => false` を追加（L502-506 あたり）

```php
$updates = [
    'status' => WmsInventoryCount::STATUS_CONFIRMED,
    'confirmed_at' => now(),
    'confirmed_by' => $userId,
    'handy_reception' => false,  // 追加
];
```

2. **`cancel()` メソッド** (L725-730):
   - `update` 配列に `'handy_reception' => false` を追加

```php
public function cancel(WmsInventoryCount $inventoryCount): void
{
    $inventoryCount->update([
        'status' => WmsInventoryCount::STATUS_CANCELLED,
        'handy_reception' => false,  // 追加
    ]);
}
```

### 完了条件

- `confirm()` で `handy_reception = false` が設定される
- `cancel()` で `handy_reception = false` が設定される

---

## P4: UI変更（アクション＋バッジ）

### 目的

棚卸し詳細ページに「Handy受付」トグルボタンと受付状態バッジを追加する。

### 修正対象ファイル

- `app/Filament/Resources/WmsInventoryCount/Pages/ViewWmsInventoryCount.php`
- `resources/views/filament/resources/wms-inventory-count/pages/view-wms-inventory-count.blade.php`

### 修正内容

#### 4-1: ヘッダーアクション追加

`ViewWmsInventoryCount.php` の `getHeaderActions()` メソッド (L830-1088) の先頭（`viewLogs` アクションの前）に追加:

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
        : "この棚卸しのHANDY受付を開始します。同じ倉庫（{$record->warehouse_name}）で他にHANDY受付ONの棚卸しがある場合、そちらは自動的にOFFになります。")
    ->modalFooterActionsAlignment(Alignment::End)
    ->modalSubmitAction(fn ($action) => $record->handy_reception
        ? $action->makeModalSubmitAction('submit', [])->label('OFFにする')->color('danger')
        : $action->makeModalSubmitAction('submit', [])->label('ONにする')->color('success'))
    ->modalCancelActionLabel(fn () => $record->handy_reception ? 'OFFにせず閉じる' : 'ONにせず閉じる')
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

#### 4-2: Bladeバッジ追加

`view-wms-inventory-count.blade.php` のヘッダーバー (L87-113) で、ステータスバッジ (L95-97) の直後に受付バッジを追加:

```blade
@if ($record->handy_reception)
    <span class="rounded-full bg-green-100 px-2 py-0.5 text-[11px] font-bold text-green-700">
        HANDY受付中
    </span>
@endif
```

### 完了条件

- 棚卸し詳細ページに「Handy受付 ON/OFF」ボタンが表示される（DRAFT/COUNTING時のみ）
- ボタンクリック → 確認モーダル → ON/OFF切り替えが動作する
- ON時にヘッダーバーに「HANDY受付中」バッジが表示される

---

## P5: API変更

### 目的

APIでHANDY受付ONの棚卸しのみをHANDYに返し、受付OFFの棚卸しへのカウント登録を拒否する。

### 修正対象ファイル

- `app/Http/Controllers/Api/InventoryCountController.php`
- `routes/api.php`

### 修正内容

#### 5-1: `index()` メソッド変更 (L27-55)

`warehouse_id` を必須パラメータに変更し、HANDY受付ONの棚卸しのみ返す:

```php
public function index(Request $request): JsonResponse
{
    $validator = Validator::make($request->all(), [
        'warehouse_id' => ['required', 'integer'],
    ]);

    if ($validator->fails()) {
        return $this->validationError($validator->errors()->toArray());
    }

    $counts = WmsInventoryCount::where('warehouse_id', (int) $request->input('warehouse_id'))
        ->where('handy_reception', true)
        ->whereIn('status', [
            WmsInventoryCount::STATUS_DRAFT,
            WmsInventoryCount::STATUS_COUNTING,
        ])
        ->orderBy('count_date', 'desc')
        ->orderBy('id', 'desc')
        ->get();

    return $this->success([
        'inventory_counts' => $counts->map(fn (WmsInventoryCount $count) => [
            'id' => $count->id,
            'count_no' => $count->count_no,
            'warehouse_id' => $count->warehouse_id,
            'warehouse_code' => $count->warehouse_code,
            'warehouse_name' => $count->warehouse_name,
            'count_date' => $count->count_date?->format('Y-m-d'),
            'status' => $count->status,
            'status_label' => $count->status_label,
            'started_at' => $count->started_at?->toIso8601String(),
            'memo' => $count->memo,
            'handy_reception' => true,
            'current_round' => $this->currentRound($count),
            'total_items' => $count->items()->count(),
            'counted_items' => $count->items()->whereNotNull('first_count_quantity')->count(),
            'final_counted_items' => $count->items()->whereNotNull('final_count_quantity')->count(),
        ])->values()->all(),
    ]);
}
```

#### 5-2: `show()` メソッド変更 (L62-94)

レスポンスに `handy_reception` フィールドを追加:

```php
'handy_reception' => (bool) $count->handy_reception,
```

#### 5-3: `isHandyCountable()` メソッド変更 (L676-681)

`handy_reception` チェックを追加:

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

#### 5-4: `active()` エンドポイント追加

`InventoryCountController.php` に新規メソッド:

```php
public function active(Request $request): JsonResponse
{
    $validator = Validator::make($request->all(), [
        'warehouse_id' => ['required', 'integer'],
    ]);

    if ($validator->fails()) {
        return $this->validationError($validator->errors()->toArray());
    }

    $count = WmsInventoryCount::where('warehouse_id', (int) $request->input('warehouse_id'))
        ->where('handy_reception', true)
        ->whereIn('status', [
            WmsInventoryCount::STATUS_DRAFT,
            WmsInventoryCount::STATUS_COUNTING,
        ])
        ->first();

    if (!$count) {
        return $this->success(['inventory_count' => null]);
    }

    $itemStats = WmsInventoryCountItem::where('inventory_count_id', $count->id)
        ->selectRaw('COUNT(*) as total_items')
        ->selectRaw('COUNT(first_count_quantity) as counted_items')
        ->selectRaw('COUNT(*) - COUNT(first_count_quantity) as uncounted_items')
        ->first();

    return $this->success([
        'inventory_count' => [
            'id' => $count->id,
            'count_no' => $count->count_no,
            'warehouse_id' => $count->warehouse_id,
            'warehouse_code' => $count->warehouse_code,
            'warehouse_name' => $count->warehouse_name,
            'count_date' => $count->count_date?->format('Y-m-d'),
            'status' => $count->status,
            'status_label' => $count->status_label,
            'started_at' => $count->started_at?->toIso8601String(),
            'snapshot_taken_at' => $count->snapshot_taken_at?->toIso8601String(),
            'memo' => $count->memo,
            'handy_reception' => true,
            'total_items' => (int) $itemStats->total_items,
            'counted_items' => (int) $itemStats->counted_items,
            'uncounted_items' => (int) $itemStats->uncounted_items,
        ],
    ]);
}
```

#### 5-5: ルート追加

`routes/api.php` の棚卸しエンドポイントグループ (L65-72) に追加:

```php
Route::get('/wms/inventory-counts/active', [InventoryCountController::class, 'active']);
```

**注意**: `{id}` パラメータルートより前に配置すること（`/active` が `{id}` にマッチしないよう）。

### 完了条件

- `GET /api/wms/inventory-counts?warehouse_id=X` が HANDY受付ONの棚卸しのみ返す
- `warehouse_id` なしでリクエストするとバリデーションエラーが返る
- `GET /api/wms/inventory-counts/{id}` のレスポンスに `handy_reception` フィールドがある
- `isHandyCountable()` が `handy_reception = false` の棚卸しを拒否する
- `GET /api/wms/inventory-counts/active?warehouse_id=X` が受付中の棚卸し1件を返す

---

## P6: 動作確認

### 目的

全機能の結合テストを行い、正常動作を確認する。

### テスト手順

#### 6-1: Web UI テスト

1. `https://wms.sakemaru.test/admin/wms-inventory-counts` を開く
2. DRAFT or COUNTING ステータスの棚卸しの詳細を開く
3. 「Handy受付 OFF」ボタンが表示されることを確認
4. ボタンクリック → 確認モーダル表示 → 「ONにする」実行
5. ボタンが「Handy受付 ON」（緑色）に変わることを確認
6. ヘッダーバーに「HANDY受付中」バッジが表示されることを確認
7. 同一倉庫の別の棚卸しで同じ操作を行い、前の棚卸しが自動OFFになることを確認
8. CONFIRMED / CANCELLED の棚卸しではボタンが非表示であることを確認

#### 6-2: API テスト

```bash
# 1. index — warehouse_id 必須
curl -s -H "X-API-Key: test-api-key-12345" \
  "https://wms.sakemaru.test/api/wms/inventory-counts" | jq .
# → バリデーションエラーが返ること

# 2. index — warehouse_id 指定
curl -s -H "X-API-Key: test-api-key-12345" \
  "https://wms.sakemaru.test/api/wms/inventory-counts?warehouse_id=XX" | jq .
# → handy_reception=true の棚卸しのみ返ること

# 3. active エンドポイント
curl -s -H "X-API-Key: test-api-key-12345" \
  "https://wms.sakemaru.test/api/wms/inventory-counts/active?warehouse_id=XX" | jq .
# → 受付中の棚卸し1件、または null

# 4. items — handy_reception=false の棚卸し
curl -s -H "X-API-Key: test-api-key-12345" \
  "https://wms.sakemaru.test/api/wms/inventory-counts/{OFF_ID}/items" | jq .
# → エラー（422: INVALID_STATUS）

# 5. show — handy_reception フィールド
curl -s -H "X-API-Key: test-api-key-12345" \
  "https://wms.sakemaru.test/api/wms/inventory-counts/{ID}" | jq .inventory_count.handy_reception
# → true or false
```

#### 6-3: 確定・取消時の自動OFF

1. HANDY受付ONの棚卸しを取消 → `handy_reception` がOFFになることを確認
2. （テスト可能であれば）確定時にも同様にOFFになることを確認

### 完了条件

- P4の全UIテスト項目がパスする
- P5の全APIテスト項目がパスする
- 確定・取消時の自動OFFが動作する

---

## 制約（厳守）

1. `php artisan migrate:fresh` / `migrate:refresh` / `migrate:reset` / `db:wipe` 禁止
2. FK（外部キー）禁止
3. `Filament\Actions\Action` を使用（`Filament\Tables\Actions\Action` ではない）
4. `Filament\Schemas\Components\Section` / `Grid` を使用
5. マイグレーションは `Schema::connection('sakemaru')` を使用

## 全体完了条件

- P1〜P6 すべて完了
- HANDY受付ON/OFFトグルがWeb UIで正常動作
- 同一倉庫の排他制御が動作
- APIがHANDY受付ONの棚卸しのみ返す
- 確定・取消時に自動OFF
