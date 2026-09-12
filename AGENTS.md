# AGENTS.md

This file provides guidance to Codex (Codex.ai/code) when working with code in this repository.

## 絶対禁止事項（CRITICAL）

### データベース破壊コマンドの禁止

**以下のコマンドは絶対に実行してはならない。本番データが削除される。**

```bash
# 禁止コマンド一覧
php artisan migrate:fresh      # 全テーブル削除 → 禁止
php artisan migrate:refresh    # 全テーブル削除 → 禁止
php artisan migrate:reset      # 全マイグレーション取り消し → 禁止
php artisan db:wipe            # データベース全削除 → 禁止
php artisan db:fresh           # 禁止

# 以下のオプション付きも禁止
--fresh
--refresh
--seed (単体での実行は可、上記と組み合わせは禁止)
```

これらは `app/Console/Commands/Prevent*.php` の同名コマンド override で無効化している。検証でも上記コマンド名を直接実行しないこと。

テストでは `RefreshDatabase` を使用しない。`phpunit.xml` / `.env.testing` は専用 test DB を使う。テストデータは必要最小限の作成と `DatabaseTransactions`、または対象を限定した `delete` / `truncate` で処理すること。

**許可されているコマンド:**
```bash
php artisan migrate            # 新規マイグレーションの実行 → OK
php artisan migrate:status     # マイグレーション状態確認 → OK
php artisan make:migration     # マイグレーション作成 → OK
```

**理由:** このプロジェクトは基幹システム（sakemaru）と共有データベースを使用しており、データ削除は業務に重大な影響を与える。

---

## 重要: 作業開始前の必読ドキュメント

新しいタスクを開始する前に、以下のドキュメントを必ず確認してください:

1. **Filament 4仕様**: `storage/specifications/filament4spec.md`
   - Filament 4の重要な変更点とベストプラクティス
   - テーブルクエリのカスタマイズ方法
   - アクションの位置制御
   - フォームコンポーネントの正しいインポートパス
   - よくあるエラーと解決方法

2. **WMS仕様**: `storage/specifications/2025-10-13-wms-specification.md`
   - システム全体の要件定義
   - データベース設計
   - ビジネスロジック

3. **モーダルデザイン仕様**: `~/.Codex/design-knowledge/modal-design.md`（プロジェクト横断共通）
   - **新規モーダル作成・既存モーダル修正時は必ず参照すること**
   - ヘッダー紺色（#1e293b）、ボタン右寄せ、実行ボタン赤（danger）
   - キャンセルは「〜せず閉じる」形式
   - 大量選択肢のセレクトは ViewField + Alpine.js パターン
   - CSS定義: `incoming-detail-modal` クラス（theme.css）
   - 旧仕様書（参考）: `storage/specifications/20260311/modal-design/spec.md`

4. **メガメニュー仕様**: `~/.Codex/design-knowledge/mega-menu.md`（プロジェクト横断共通）
   - メガメニューの修正・新規タブ追加時に参照
   - ヘッダー `bg-slate-800`、高さ 2.5rem、z-[35]
   - 動的カラムレイアウト（1〜3列）、Split View 連携

5. **テーブルタブ表示仕様**: `~/.Codex/design-knowledge/table-tabs.md`（プロジェクト横断共通）
   - 4パターン: getTabs() / PresetView / Form Schema Tabs / Sub-Navigation Tabs
   - パターン選択ガイド・実装例・動的タブ生成・キャッシュ戦略
   - テーブル固定高さ + 内部スクロール + sticky thead
   - ストライプ行スタイル

6. **ページスクロール制御仕様**: `~/.Codex/design-knowledge/page-scroll-control.md`（プロジェクト横断共通）
   - HTML overflow 制御、sticky カラム（右固定/左固定）
   - Split View（左右分割パネル + ドラッグリサイズ）
   - z-index 階層ルール

7. **テーブルコンパクトデザイン仕様**: `~/.Codex/design-knowledge/table-compact-design.md`（プロジェクト横断共通）
   - 行コンパクト化、ページヘッダー余白、sticky-actions右固定、ストライプ行
   - TextInputColumn幅固定、トップバー高さ調整

8. **欠品管理仕様**: `storage/specifications/wms-shortage-allocations/20251115-shorage-algorithm.md`
   - 欠品検出と代理出荷のアルゴリズム
   - データ構造と状態管理

9. **テーブルデザイン仕様**: `storage/specifications/table-design-specification.md`
   - コード系ラベルは「CD」表記で統一（商品コード→商品CD）
   - コードと名前は別カラムに分離
   - 商品名は`wrap()`禁止、`grow()`で全体表示
   - アクションボタンは右固定（`sticky-actions`クラス使用）
   - フィルターは`[コード]名前`形式で表示、コードでも検索可能

これらのドキュメントに記載されている情報を優先して使用し、古い実装パターンを避けてください。

## Project Overview

Smart WMS (Warehouse Management System) - A Laravel 12 + Filament 4 admin panel application for warehouse management.

This WMS system integrates with a core business system (基幹システム) by:
- Referencing shared database tables for master data
- Managing WMS-specific operations in dedicated `wms_` tables
- Tracking stock reservations and allocations via columns added to the core `real_stocks` table
- **No foreign keys** - data integrity is maintained at the application/job level

**Tech Stack:**
- **Laravel 12** (PHP 8.2+)
- **Filament 4** (Admin Panel Framework)
- **Livewire 3** (For reactive components)
- **Tailwind CSS 4** (Styling)
- **Vite** (Asset bundling)
- **MySQL** (Production database via `sakemaru` connection)

### Tailwind CSS 4 の注意事項

**Tailwind CSS 4.x** を使用（`@tailwindcss/vite` プラグイン経由）。v3 とは設定方法が大きく異なる。

#### 動的クラスの safelist

Blade 内の PHP `match()` / 三項演算子で生成されるクラスは Tailwind のスキャナが検出できない場合がある。

```css
/* ❌ v3の方法 — Tailwind CSS 4 では廃止・無効 */
/* tailwind.config.js の safelist は読み込まれない */

/* ✅ v4の正しい方法 — @source inline() を CSS に記述 */
@source inline("bg-green-100 bg-orange-50 text-green-700 border-orange-300 dark:bg-green-900");
```

- `@source inline("...")` は **1行で記述**（改行するとパースエラー）
- 記述場所: `resources/css/filament/admin/theme.css`（Filament テーマ用）
- `dark:` バリアントも明示的に含める必要がある

#### Livewire と配列キーの型

Livewire 3 は public array プロパティの **int 型キーを string に変換**する（JSONシリアライズ時）。Blade でアクセスする際は `(string)` キャストが必要:

```php
// Widget 側: キーを string で統一
$this->data[(string) $id] = 'value';

// Blade 側: string でアクセス
$status = $data[(string) $item['id']] ?? 'default';
```

#### ビルドコマンド

```bash
npm run build    # Vite 本番ビルド（Tailwind CSS も含む）
npm run dev      # Vite dev サーバー（HMR）
```

---

## Development Commands

### Local Web UI Login For Verification Agents
- For authenticated browser checks in this repository, use the shared credentials from `/Users/jungsinyu/Projects/sakemaru-ai-core/.env`.
- Read the login ID from `ADMIN_USER_EMAIL` and the password from `ADMIN_PASS` at runtime.
- Never print, copy, commit, or hard-code the credential values.
- Do not treat missing `ADMIN_USER_EMAIL` or `ADMIN_PASS` in this repository's own `.env` as a blocker; always check `/Users/jungsinyu/Projects/sakemaru-ai-core/.env` first unless the user explicitly provides different credentials.

```bash
# Initial Setup
composer setup  # Installs dependencies, generates key, runs migrations, builds assets

# Development (runs server, queue, logs, and vite concurrently)
composer dev    # http://localhost:8000

# Testing
composer test                           # Clear config cache and run PHPUnit tests
php artisan test --filter=TestName      # Run specific test

# Code Quality
./vendor/bin/pint                       # Laravel Pint (code formatter)

# Assets
npm run build                           # Build production assets
npm run dev                             # Vite dev server

# Test Data Generation (project-specific)
php artisan wms:generate-test-data      # Generate WMS test data
php artisan wms:generate-waves          # Generate waves for testing
php artisan wms:generate-test-shortages # Generate shortage test data
php artisan wms:update-daily-stats      # Update daily statistics
```

## Local System Test Knowledge

- core base URL: `https://sakemaru.test`
- WMS base URL: `https://wms.sakemaru.test`
- Trade base URL: `https://trade.sakemaru.test`
- Search base URL: `https://search.sakemaru.test`
- Local DB: `hana_local`
- Local DB user: `ROOT` (`root` in `.env`)

## Architecture

### Database Connections

The project uses two database connections:
- **`sakemaru`**: Production MySQL database for core system integration (see `config/database.php`)
- **`sqlite`**: Default/testing database

**WMS Models** must extend `WmsModel` base class which sets the `sakemaru` connection:

```php
// app/Models/WmsModel.php
abstract class WmsModel extends Model
{
    protected $connection = 'sakemaru';
}
```

### Filament 4 Resource Structure

Resources follow this pattern (different from Filament 3):

```
app/Filament/Resources/
├── ModelResource.php           # Main resource class
├── Model/
│   ├── Pages/
│   │   ├── ListModel.php      # List page
│   │   ├── CreateModel.php    # Create page
│   │   └── EditModel.php      # Edit page
│   ├── Schemas/
│   │   └── ModelForm.php      # Form definition (uses Schema, not Form)
│   └── Tables/
│       └── ModelTable.php     # Table definition
```

### Service Layer

Business logic is organized in `app/Services/`:
- `StockAllocationService` - Stock allocation with FEFO→FIFO priority
- `WaveService` - Wave generation and management
- `Shortage/*` - Shortage detection, proxy shipment, confirmation services
- `Picking/*` - Route optimization (A* algorithm, distance caching)
- `Print/*` - Print request handling

### Key Filament 4 Patterns

**CRITICAL: These patterns are Filament 4 specific and differ from Filament 3**

#### コンポーネントのインポートパス（重要）

```php
// =====================================================
// Filament 4 正しいインポートパス一覧
// =====================================================

// Schema/Form コンポーネント
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;      // NOT Filament\Forms\Components\Section
use Filament\Schemas\Components\Grid;         // NOT Filament\Infolists\Components\Grid

// フォーム入力コンポーネント
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;

// 表示専用コンポーネント（Infolist）
use Filament\Infolists\Components\TextEntry;  // 表示専用フィールド

// アクション
use Filament\Actions\Action;                  // NOT Filament\Tables\Actions\Action

// テーブル
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
```

#### よくある間違い（避けるべきパターン）

```php
// ❌ 間違い
use Filament\Tables\Actions\Action;           // クラスが存在しない
use Filament\Forms\Components\Section;        // Filament 4では非推奨
use Filament\Infolists\Components\Section;    // クラスが存在しない
use Filament\Infolists\Components\Grid;       // クラスが存在しない

// ✅ 正しい
use Filament\Actions\Action;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
```

#### テーブルアクションの設定

```php
// Table query customization
public function table(Table $table): Table
{
    return parent::table($table)
        ->modifyQueryUsing(fn (Builder $query) => $query->with(['relation']));
}

// ❌ 間違い: ->actions([]) は Filament 4 では使用不可
$table->actions([...]);

// ✅ 正しい: recordActions と toolbarActions を使用
$table->recordActions([
    Action::make('view')->icon('heroicon-o-eye'),
], position: RecordActionsPosition::BeforeColumns)
->toolbarActions([
    Action::make('create'),
]);
```

#### モーダルアクションのスキーマ

```php
// Actions in modals use schema(), not form()
Action::make('myAction')
    ->schema([...])  // NOT ->form([...])
    ->action(function ($record, array $data) { ... });

// モーダル内でInfolistを表示する場合
Action::make('viewResult')
    ->modalHeading('結果表示')
    ->modalSubmitAction(false)
    ->modalCancelActionLabel('閉じる')
    ->infolist(fn ($record) => [
        Section::make('サマリー')
            ->schema([
                Grid::make(2)->schema([
                    TextEntry::make('field1')->label('項目1'),
                    TextEntry::make('field2')->label('項目2'),
                ]),
            ]),
    ]);
```

### モーダルデザインルール

新規モーダル作成時は `storage/specifications/20260311/modal-design/spec.md` を必ず参照。

**共通Bladeコンポーネント** (`resources/views/components/modal/`):
- `container.blade.php` — サイズ指定（sm〜7xl）、Alpine.js制御、backdrop + transition
- `header.blade.php` — アイコン + タイトル + 閉じるボタン、`bg-slate-50`
- `content.blade.php` — スクロール可能コンテンツ領域
- `footer.blade.php` — ボタン配置（justify: end）、`bg-slate-50`
- `form-group.blade.php` — ラベル付きフォームグループ
- `confirm.blade.php` — 確認ダイアログ（red/orange/blue）

**Filament Action モーダル**:
```php
// 詳細モーダルの標準パターン
Action::make('viewDetail')
    ->modalWidth('5xl')                    // 詳細系は5xl統一
    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
    ->modalSubmitAction(false)
    ->modalCancelActionLabel('閉じる')      // 閉じるのみ
    ->infolist(fn ($record) => [...]);

// フォーム付きモーダル（発注生成等）
Action::make('generate')
    ->modalFooterActionsAlignment(\Filament\Support\Enums\Alignment::End)
    ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('生成開始')->color('danger'))
    ->modalCancelActionLabel('発注せず閉じる')
    ->schema([...]);
```

---

### テーブルデザインルール

詳細は `storage/specifications/table-design-specification.md` を参照。

**カラム命名規則**:
- コード系ラベルは「CD」表記: 商品CD、倉庫CD、仕入先CD
- コードと名前は別カラムに分離（結合しない）
- 商品名は `->grow()` で全体表示、`->wrap()` 禁止
- 日付は24時間表記: `->date('m/d H:i')`

**フィルター表示**: `[コード]名前` 形式、コードでも検索可能
```php
// HasOptimizedFilters トレイトを使用
->getOptionLabelFromRecordUsing(fn ($record) => "[{$record->code}]{$record->name}")
->getSearchResultsUsing(fn (string $search) => /* mb_convert_kana($search, 'as') で全角→半角変換 */)
```

---

### 操作列の固定（sticky-actions）

テーブルの操作列（アクションボタン）は右固定が標準。CSSは `resources/css/filament/admin/theme.css` で定義。

```php
// テーブルに適用（全テーブル共通）
$table->extraAttributes(['class' => 'sticky-actions'])

// 左固定が必要な場合（ピッキングタスク等）
$table->extraAttributes(['class' => 'sticky-actions-left'])
```

**CSS仕様**:
- `.sticky-actions` — 最終列を `position: sticky; right: 0` で固定、z-index管理
- `.sticky-actions-left` — 先頭列を左固定
- ストライプ背景色: light `#f5f9ff` / `#ffffff`、dark `#1e2a3b` / `#111827`
- `::before` 左ボーダー（2px gray）で視覚的区切り

---

### クエリ最適化パターン

**OOM回避**: `result_data` 等の巨大JSONカラムがあるテーブルでは `select *` + `ORDER BY` でソートバッファOOMが発生する。PKインデックスを利用した `orderBy('id', 'desc')` を使用。

**Eager Loading**: Resource の `getEloquentQuery()` で必要なリレーションのみ `->with()`:
```php
public static function getEloquentQuery(): Builder
{
    return parent::getEloquentQuery()
        ->with(['createdByUser', 'warehouse']);  // 必要最小限
}
```

**サブクエリ集計**: `HasStockSubqueries` トレイト（`app/Filament/Concerns/`）
```php
// selectRaw + whereColumn で在庫集計
$subquery = DB::connection('sakemaru')->table('real_stocks')
    ->selectRaw('COALESCE(SUM(quantity), 0)')
    ->whereColumn('item_id', "{$mainTable}.item_id");
```

**フィルター最適化**: `HasOptimizedFilters` トレイト（`app/Filament/Concerns/`）
- `warehouseFilter()` — 倉庫選択フィルタ（コード/名前検索対応）
- `contractorFilter()` — 仕入先複数選択フィルタ
- `batchCodeFilter()` — バッチコード選択（`limit(50)` で制限）
- `statusFilter()` — Enum ベースのステータスフィルタ

---

### 共通トレイト（`app/Filament/Concerns/`）

| トレイト | 用途 |
|---------|------|
| `HasExportAction` | CSV/XLSXエクスポート。`toolbarActions([static::getExportAction()])` で全テーブル共通 |
| `HasOptimizedFilters` | 再利用可能なフィルタビルダー。全角→半角変換、`[CD]名前` 表示 |
| `HasStockSubqueries` | 在庫集計サブクエリ（current/available/defaultLocation） |
| `HasWmsUserViews` | AdvancedTables プラグイン統合。ユーザービュー管理 |

**トレイト競合解決**:
```php
use AdvancedTables;
use HasWmsUserViews {
    HasWmsUserViews::getUserViews insteadof AdvancedTables;
    HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
}
```

---

### Stock Allocation Strategy

**FEFO → FIFO Priority:**
1. **FEFO (First Expiry, First Out)**: Prioritize by `expiration_date` ASC (NULL values last)
2. **FIFO (First In, First Out)**: Within same expiry date, sort by `received_at` ASC
3. **Tie-breaker**: Sort by `real_stock_id` ASC

Uses `wms_v_stock_available` view for real-time available stock calculation.

### Quantity Type Display Guidelines

**IMPORTANT**: Always use `QuantityType` enum for displaying quantity types in the UI.

```php
use App\Enums\QuantityType;

// Correct terminology:
// CASE  → "ケース" (NOT "CS")
// PIECE → "バラ"   (NOT "個")
// CARTON → "ボール"

$caseLabel = QuantityType::CASE->name();   // "ケース"
$pieceLabel = QuantityType::PIECE->name(); // "バラ"

// For volume units
use App\Enums\EVolumeUnit;
$unit = EVolumeUnit::tryFrom($value)->name();  // ml, g, etc.
```

### Key Design Principles

1. **No Foreign Keys**: All relationships managed at application level
2. **Optimistic Locking**: Use `wms_lock_version` to detect concurrent stock updates
3. **Idempotency**: All allocation operations must be idempotent via `wms_idempotency_keys`
4. **Transaction Safety**: Stock reservations must be atomic (reservation + real_stocks update)

### WMS Tables (prefixed with `wms_`)

- `wms_reservations` - Stock allocation records
- `wms_waves` - Wave/batch picking operations
- `wms_picking_tasks` - Picking task management
- `wms_shortages` - Shortage records
- `wms_shortage_allocations` - Proxy shipment allocations
- `wms_picking_logs` - Picking operation logs
- `wms_pickers` - Picker (worker) records
- `wms_picking_areas` - Warehouse picking areas
- `wms_locations` / `wms_location_levels` - Location management
