<?php

namespace App\Filament\Resources\WmsInventoryCount\Pages;

use App\Filament\Resources\WmsInventoryCountResource;
use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use App\Models\WmsInventoryCountItemLog;
use App\Services\InventoryCount\InventoryCountService;
use App\Services\InventoryCount\InventoryDifferenceWorkbookService;
use App\Services\InventoryCount\InventoryDiffListPdfService;
use App\Services\InventoryCount\InventoryEnteredListWorkbookService;
use App\Services\InventoryCount\InventoryInstructionPdfService;
use App\Services\InventoryCount\InventoryInstructionSheetPdfService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Alignment;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ViewWmsInventoryCount extends Page implements HasForms
{
    use InteractsWithForms;

    private const UNCOUNTED_TARGET_MAJOR_CATEGORY_CODES = [1001, 1002, 1003, 1006];

    private const LIST_TABS = ['all', 'diff', 'pdf_diff', 'matched', 'unmanaged'];

    protected static string $resource = WmsInventoryCountResource::class;

    protected string $view = 'filament.resources.wms-inventory-count.pages.view-wms-inventory-count';

    public WmsInventoryCount $record;

    public string $floorFilter = '';

    public string $areaFilter = '';

    public string $itemCodeFilter = '';

    public string $locationFilter = '';

    public array $selectedLocationFilters = [];

    public string $itemNameFilter = '';

    public string $listTab = 'all';

    public string $sortColumn = '';

    public string $sortDirection = 'asc';

    public int $itemPage = 1;

    public int $itemPerPage = 200;

    public int $activeCountRound = 1;

    public bool $editModalOpen = false;

    public ?int $editItemId = null;

    public string $editFirstCountQty = '';

    public string $editSecondCountQty = '';

    public string $editFinalCountQty = '';

    /**
     * @var Collection<int, WmsInventoryCountItem>|null
     */
    private ?Collection $filteredPdfDiffItemsCache = null;

    private ?string $filteredPdfDiffItemsCacheKey = null;

    public function mount(WmsInventoryCount $record): void
    {
        $record->load(['createdByUser', 'confirmedByUser']);
        $this->record = $record;
        $this->activeCountRound = $this->currentProgressRound();
    }

    public function getTitle(): string|Htmlable
    {
        return "棚卸し詳細: {$this->record->count_no}";
    }

    public function getBreadcrumbs(): array
    {
        return [
            WmsInventoryCountResource::getUrl() => '棚卸し',
            '#' => $this->record->count_no,
        ];
    }

    public function getMaxContentWidth(): ?string
    {
        return 'full';
    }

    public function getHeading(): string
    {
        return '';
    }

    public function getCachedHeaderActions(): array
    {
        return [];
    }

    // ========================================
    // Filter / Tab / Sort
    // ========================================

    public function search(): void {}

    public function setListTab(string $tab): void
    {
        if (! in_array($tab, self::LIST_TABS, true)) {
            return;
        }
        $this->listTab = $tab;
        if ($tab === 'pdf_diff') {
            $this->sortColumn = '';
            $this->sortDirection = 'asc';
        }
        $this->itemPage = 1;
    }

    public function updatedListTab(string $tab): void
    {
        if (! in_array($tab, self::LIST_TABS, true)) {
            $this->listTab = 'all';
        }

        if ($this->listTab === 'pdf_diff') {
            $this->sortColumn = '';
            $this->sortDirection = 'asc';
        }
        $this->itemPage = 1;
    }

    public function clearFilters(): void
    {
        $this->floorFilter = '';
        $this->areaFilter = '';
        $this->itemCodeFilter = '';
        $this->locationFilter = '';
        $this->selectedLocationFilters = [];
        $this->itemNameFilter = '';
        $this->search();
    }

    public function updatedFloorFilter(): void
    {
        $this->itemPage = 1;
    }

    public function updatedAreaFilter(): void
    {
        $this->itemPage = 1;
    }

    public function updatedItemCodeFilter(): void
    {
        $this->itemPage = 1;
    }

    public function updatedLocationFilter(): void
    {
        $this->itemPage = 1;
    }

    public function updatedSelectedLocationFilters(): void
    {
        $this->itemPage = 1;
    }

    public function updatedItemNameFilter(): void
    {
        $this->itemPage = 1;
    }

    public function sortBy(string $column): void
    {
        if ($this->listTab === 'pdf_diff') {
            return;
        }

        if (! in_array($column, $this->sortableColumns(), true)) {
            return;
        }

        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortColumn = $column;
            $this->sortDirection = 'asc';
        }

        $this->itemPage = 1;
    }

    public function sortIndicator(string $column): string
    {
        if ($this->sortColumn !== $column) {
            return '↕';
        }

        return $this->sortDirection === 'asc' ? '↑' : '↓';
    }

    // ========================================
    // Data
    // ========================================

    public function floorOptions(): array
    {
        return $this->inventoryCountItemsQuery()
            ->whereNotNull('floor_name')
            ->distinct()
            ->orderBy('floor_name')
            ->pluck('floor_name')
            ->toArray();
    }

    public function locationOptions(): array
    {
        return $this->inventoryCountItemsQuery()
            ->whereNotNull('location_no')
            ->distinct()
            ->orderBy('location_no')
            ->pluck('location_no')
            ->toArray();
    }

    public function rows(): LengthAwarePaginator
    {
        if ($this->listTab === 'pdf_diff') {
            return $this->pdfDiffRows();
        }

        $query = $this->inventoryCountItemsQuery();
        $this->applyFilters($query);
        $this->applyTabFilter($query, $this->listTab);
        $this->applySort($query);

        return $query->paginate($this->itemPerPage, ['*'], 'inventory_items_page', $this->itemPage);
    }

    public function goToItemPage(int $page): void
    {
        $total = $this->listTab === 'pdf_diff'
            ? $this->filteredPdfDiffItems()->count()
            : $this->filteredQuery()->count();
        $lastPage = max(1, (int) ceil($total / $this->itemPerPage));
        $this->itemPage = min(max(1, $page), $lastPage);
    }

    public function previousItemPage(): void
    {
        $this->goToItemPage($this->itemPage - 1);
    }

    public function nextItemPage(): void
    {
        $this->goToItemPage($this->itemPage + 1);
    }

    public function setActiveCountRound(int $round): void
    {
        if ($round < 1 || $round > $this->currentProgressRound()) {
            return;
        }

        $this->activeCountRound = $round;
        $this->itemPage = 1;
    }

    public function activeRoundLabel(): string
    {
        return "{$this->activeCountRound}回目";
    }

    public function roundLabel(int $round): string
    {
        return "{$round}回目";
    }

    public function differenceWorkbookRoundOptions(): array
    {
        return collect(range(1, $this->currentProgressRound()))
            ->mapWithKeys(fn (int $round): array => [$round => $this->roundLabel($round)])
            ->all();
    }

    public function defaultDifferenceWorkbookRound(): int
    {
        return $this->activeCountRound;
    }

    public function isRoundConfirmed(int $round): bool
    {
        return $this->record->{$this->roundConfirmedAtColumn($round)} !== null;
    }

    public function roundDifferenceForDisplay(WmsInventoryCountItem $item, int $round): ?int
    {
        $confirmedDifference = $this->isRoundConfirmed($round)
            ? $item->confirmedRoundDifference($round)
            : null;

        if ($confirmedDifference !== null) {
            return (int) $confirmedDifference;
        }

        $countedQty = $this->physicalRoundQuantityForDisplay($item, $round);
        if ($countedQty === null) {
            $countedQty = $this->isUncountedTargetItemForDisplay($item)
                ? 0
                : $item->roundQuantity($round);
        }
        $baseQty = $item->ending_system_quantity ?? $item->system_quantity;

        if ($countedQty === null || $baseQty === null) {
            return null;
        }

        return (int) $countedQty - (int) $baseQty;
    }

    public function totalCount(): int
    {
        if ($this->listTab === 'pdf_diff') {
            return $this->filteredPdfDiffItems()->count();
        }

        return $this->filteredQuery()->count();
    }

    public function countForTab(string $tab): int
    {
        if ($tab === 'pdf_diff') {
            return $this->filteredPdfDiffItems()->count();
        }

        $query = $this->inventoryCountItemsQuery();
        $this->applyFilters($query);
        $this->applyTabFilter($query, $tab);

        return $query->count();
    }

    public function isUncountedTargetItemForDisplay(WmsInventoryCountItem $item): bool
    {
        $majorCategoryCode = $item->item?->item_category1?->code;
        if (! in_array((int) $majorCategoryCode, self::UNCOUNTED_TARGET_MAJOR_CATEGORY_CODES, true)) {
            return false;
        }

        $systemQuantity = $item->ending_system_quantity ?? $item->system_quantity;
        $differenceQuantity = $item->difference_quantity;

        return (float) ($systemQuantity ?? 0) !== 0.0
            || ($differenceQuantity !== null && (float) $differenceQuantity !== 0.0);
    }

    public function isUnmanagedStockItemForDisplay(WmsInventoryCountItem $item): bool
    {
        if (! Schema::connection('sakemaru')->hasColumn('items', 'is_managed_stock')) {
            return false;
        }

        $isManagedStock = $item->item?->is_managed_stock;

        return $isManagedStock !== null && ! (bool) $isManagedStock;
    }

    private function physicalRoundQuantityForDisplay(WmsInventoryCountItem $item, int $round): ?int
    {
        return match ($round) {
            1 => $item->first_count_quantity,
            2 => $item->second_count_quantity,
            3 => $item->final_count_quantity,
            default => null,
        };
    }

    private function filteredQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = $this->inventoryCountItemsQuery();
        $this->applyFilters($query);
        $this->applyTabFilter($query, $this->listTab);

        return $query;
    }

    private function pdfDiffRows(): LengthAwarePaginator
    {
        $items = $this->filteredPdfDiffItems();

        return new LengthAwarePaginator(
            $items->forPage($this->itemPage, $this->itemPerPage)->values(),
            $items->count(),
            $this->itemPerPage,
            $this->itemPage,
            [
                'path' => request()->url(),
                'pageName' => 'inventory_items_page',
            ],
        );
    }

    /**
     * @return Collection<int, WmsInventoryCountItem>
     */
    private function filteredPdfDiffItems(): Collection
    {
        $cacheKey = $this->filteredPdfDiffItemsCacheKey();
        if ($this->filteredPdfDiffItemsCacheKey === $cacheKey && $this->filteredPdfDiffItemsCache !== null) {
            return $this->filteredPdfDiffItemsCache;
        }

        $items = (new InventoryDiffListPdfService)->diffItemsForRound($this->record, $this->activeCountRound);

        if ($this->floorFilter !== '') {
            $items = $items->filter(fn (WmsInventoryCountItem $item): bool => (string) $item->floor_name === $this->floorFilter);
        }

        $items = $this->applyCollectionTextFilter($items, $this->areaFilter, ['location_code1']);
        $items = $this->applyCollectionTextFilter($items, $this->itemCodeFilter, ['item_code']);
        $items = $this->applyCollectionTextFilter($items, $this->locationFilter, ['location_no', 'location_code1', 'location_code2', 'location_code3']);

        if ($this->selectedLocationFilters !== []) {
            $selectedLocations = array_map('strval', $this->selectedLocationFilters);
            $items = $items->filter(fn (WmsInventoryCountItem $item): bool => in_array((string) $item->location_no, $selectedLocations, true));
        }

        $items = $this->applyCollectionTextFilter($items, $this->itemNameFilter, ['item_name']);

        $this->filteredPdfDiffItemsCacheKey = $cacheKey;
        $this->filteredPdfDiffItemsCache = $items->values();

        return $this->filteredPdfDiffItemsCache;
    }

    private function filteredPdfDiffItemsCacheKey(): string
    {
        return json_encode([
            'record_id' => $this->record->id,
            'round' => $this->activeCountRound,
            'floor' => $this->floorFilter,
            'area' => $this->areaFilter,
            'item_code' => $this->itemCodeFilter,
            'location' => $this->locationFilter,
            'selected_locations' => array_values($this->selectedLocationFilters),
            'item_name' => $this->itemNameFilter,
        ]) ?: '';
    }

    private function inventoryCountItemsQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return WmsInventoryCountItem::where('inventory_count_id', $this->record->id)
            ->withoutOwnedSetItems()
            ->with(['item.item_category1']);
    }

    private function applyFilters(\Illuminate\Database\Eloquent\Builder $query): void
    {
        if ($this->floorFilter !== '') {
            $query->where('floor_name', $this->floorFilter);
        }

        $this->applyTextFilter($query, $this->areaFilter, ['location_code1']);
        $this->applyTextFilter($query, $this->itemCodeFilter, ['item_code']);
        $this->applyTextFilter($query, $this->locationFilter, ['location_no', 'location_code1', 'location_code2', 'location_code3']);
        if ($this->selectedLocationFilters !== []) {
            $query->whereIn('location_no', $this->selectedLocationFilters);
        }
        $this->applyTextFilter($query, $this->itemNameFilter, ['item_name']);
    }

    private function applyTabFilter(\Illuminate\Database\Eloquent\Builder $query, string $tab): void
    {
        $roundColumn = $this->roundColumn($this->activeCountRound);
        $confirmedDifferenceColumn = $this->roundConfirmedDifferenceQuantityColumn($this->activeCountRound);
        $useConfirmedDifference = $this->isRoundConfirmed($this->activeCountRound)
            && $this->inventoryCountItemColumnExists($confirmedDifferenceColumn);

        if ($tab === 'unmanaged') {
            $query->unmanagedStockItems();

            return;
        }

        if (! in_array($tab, ['diff', 'matched'], true)) {
            return;
        }

        $query->managedStockItems();

        if ($tab === 'diff') {
            $query->where(function (\Illuminate\Database\Eloquent\Builder $query) use ($roundColumn, $confirmedDifferenceColumn, $useConfirmedDifference): void {
                $query->where(function (\Illuminate\Database\Eloquent\Builder $query) use ($roundColumn, $confirmedDifferenceColumn, $useConfirmedDifference): void {
                    $this->applyDifferenceTabCondition($query, $roundColumn, $confirmedDifferenceColumn, $useConfirmedDifference);
                })->orWhere(function (\Illuminate\Database\Eloquent\Builder $query) use ($roundColumn): void {
                    $query->whereNull($roundColumn);
                    $this->applyUncountedTargetFilters($query);
                });
            });

            return;
        }

        $query->where(function (\Illuminate\Database\Eloquent\Builder $query) use ($roundColumn): void {
            $query
                ->whereNotNull($roundColumn)
                ->orWhere(function (\Illuminate\Database\Eloquent\Builder $query): void {
                    $this->applyNotUncountedTargetFilters($query);
                });
        });
        $this->applyMatchedTabCondition($query, $roundColumn, $confirmedDifferenceColumn, $useConfirmedDifference);
    }

    private function applyDifferenceTabCondition(
        \Illuminate\Database\Eloquent\Builder $query,
        string $roundColumn,
        string $confirmedDifferenceColumn,
        bool $useConfirmedDifference,
    ): void {
        $systemQuantityExpression = $this->systemQuantityExpression();

        if ($useConfirmedDifference) {
            $query->whereNotNull($confirmedDifferenceColumn)->where($confirmedDifferenceColumn, '!=', 0);

            return;
        }

        if ($this->activeCountRound === 2) {
            $query
                ->where(function ($query) use ($roundColumn) {
                    $query->whereNotNull($roundColumn)
                        ->orWhereNotNull('first_count_quantity');
                })
                ->whereRaw("{$systemQuantityExpression} IS NOT NULL")
                ->whereRaw("COALESCE(second_count_quantity, first_count_quantity) != {$systemQuantityExpression}");

            return;
        }

        $query
            ->whereNotNull($roundColumn)
            ->whereRaw("{$systemQuantityExpression} IS NOT NULL")
            ->whereRaw("{$roundColumn} != {$systemQuantityExpression}");
    }

    private function applyMatchedTabCondition(
        \Illuminate\Database\Eloquent\Builder $query,
        string $roundColumn,
        string $confirmedDifferenceColumn,
        bool $useConfirmedDifference,
    ): void {
        $systemQuantityExpression = $this->systemQuantityExpression();

        if ($useConfirmedDifference) {
            $query->whereNotNull($confirmedDifferenceColumn)->where($confirmedDifferenceColumn, 0);

            return;
        }

        if ($this->activeCountRound === 2) {
            $query
                ->where(function ($query) use ($roundColumn) {
                    $query->whereNotNull($roundColumn)
                        ->orWhereNotNull('first_count_quantity');
                })
                ->whereRaw("{$systemQuantityExpression} IS NOT NULL")
                ->whereRaw("COALESCE(second_count_quantity, first_count_quantity) = {$systemQuantityExpression}");

            return;
        }

        $query
            ->whereNotNull($roundColumn)
            ->whereRaw("{$systemQuantityExpression} IS NOT NULL")
            ->whereRaw("{$roundColumn} = {$systemQuantityExpression}");
    }

    private function applyUncountedTargetFilters(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query
            ->whereHas('item.item_category1', function (\Illuminate\Database\Eloquent\Builder $query): void {
                $query->whereIn('code', self::UNCOUNTED_TARGET_MAJOR_CATEGORY_CODES);
            })
            ->where(function (\Illuminate\Database\Eloquent\Builder $query): void {
                $systemQuantityExpression = $this->systemQuantityExpression();

                $query
                    ->whereRaw("{$systemQuantityExpression} != 0")
                    ->orWhere(function (\Illuminate\Database\Eloquent\Builder $query): void {
                        $query
                            ->whereNotNull('difference_quantity')
                            ->where('difference_quantity', '!=', 0);
                    });
            });
    }

    private function applyNotUncountedTargetFilters(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $systemQuantityExpression = $this->systemQuantityExpression();

        $query->where(function (\Illuminate\Database\Eloquent\Builder $query) use ($systemQuantityExpression): void {
            $query
                ->whereDoesntHave('item.item_category1', function (\Illuminate\Database\Eloquent\Builder $query): void {
                    $query->whereIn('code', self::UNCOUNTED_TARGET_MAJOR_CATEGORY_CODES);
                })
                ->orWhere(function (\Illuminate\Database\Eloquent\Builder $query) use ($systemQuantityExpression): void {
                    $query
                        ->whereHas('item.item_category1', function (\Illuminate\Database\Eloquent\Builder $query): void {
                            $query->whereIn('code', self::UNCOUNTED_TARGET_MAJOR_CATEGORY_CODES);
                        })
                        ->whereRaw("{$systemQuantityExpression} = 0")
                        ->where(function (\Illuminate\Database\Eloquent\Builder $query): void {
                            $query
                                ->whereNull('difference_quantity')
                                ->orWhere('difference_quantity', 0);
                        });
                });
        });
    }

    private function applySort(\Illuminate\Database\Eloquent\Builder $query): void
    {
        if (! in_array($this->sortColumn, $this->sortableColumns(), true)) {
            $this->sortColumn = '';
        }

        if (in_array($this->sortColumn, ['ending_system_quantity', 'ending_difference_quantity'], true)
            && ! Schema::connection('sakemaru')->hasColumn('wms_inventory_count_items', 'ending_system_quantity')
        ) {
            $this->sortColumn = '';
        }

        if ($this->sortColumn === 'ending_difference_quantity') {
            $roundColumn = $this->roundColumn($this->activeCountRound);
            $direction = $this->sortDirection === 'desc' ? 'desc' : 'asc';
            $confirmedDifferenceColumn = $this->roundConfirmedDifferenceQuantityColumn($this->activeCountRound);
            $systemQuantityExpression = $this->systemQuantityExpression();

            if ($this->isRoundConfirmed($this->activeCountRound) && $this->inventoryCountItemColumnExists($confirmedDifferenceColumn)) {
                $query
                    ->orderByRaw("CASE WHEN {$confirmedDifferenceColumn} IS NULL THEN 1 ELSE 0 END")
                    ->orderBy($confirmedDifferenceColumn, $direction);
            } elseif ($this->activeCountRound === 2) {
                $query
                    ->orderByRaw("CASE WHEN COALESCE(second_count_quantity, first_count_quantity) IS NULL OR {$systemQuantityExpression} IS NULL THEN 1 ELSE 0 END")
                    ->orderByRaw("(COALESCE(second_count_quantity, first_count_quantity) - {$systemQuantityExpression}) {$direction}");
            } else {
                $query
                    ->orderByRaw("CASE WHEN {$roundColumn} IS NULL OR {$systemQuantityExpression} IS NULL THEN 1 ELSE 0 END")
                    ->orderByRaw("({$roundColumn} - {$systemQuantityExpression}) {$direction}");
            }
        } elseif ($this->sortColumn !== '') {
            $query->orderBy($this->sortColumn, $this->sortDirection);
        } else {
            $query->orderByRaw("
                CASE
                    WHEN floor_name = '1F' THEN 1
                    WHEN floor_name = '2F' THEN 2
                    WHEN floor_name LIKE 'YX%' THEN 3
                    ELSE 4
                END
            ")
                ->orderBy('floor_name')
                ->orderBy('location_code1')
                ->orderBy('location_code2')
                ->orderBy('location_code3');
        }
        $query->orderBy('id');
    }

    private function sortableColumns(): array
    {
        return ['item_code', 'item_name', 'ending_system_quantity', 'ending_difference_quantity'];
    }

    private function systemQuantityExpression(): string
    {
        return $this->inventoryCountItemColumnExists('ending_system_quantity')
            ? 'COALESCE(ending_system_quantity, system_quantity)'
            : 'system_quantity';
    }

    private function applyTextFilter(\Illuminate\Database\Eloquent\Builder $query, string $value, array $columns): void
    {
        $value = trim(mb_convert_kana($value, 'as'));
        if ($value === '') {
            return;
        }

        $query->where(function ($q) use ($value, $columns) {
            foreach ($columns as $column) {
                $q->orWhere($column, 'like', "%{$value}%");
            }
        });
    }

    /**
     * @param  Collection<int, WmsInventoryCountItem>  $items
     * @param  array<int, string>  $attributes
     * @return Collection<int, WmsInventoryCountItem>
     */
    private function applyCollectionTextFilter(Collection $items, string $value, array $attributes): Collection
    {
        $value = $this->normalizeCollectionFilterValue($value);
        if ($value === '') {
            return $items;
        }

        return $items->filter(function (WmsInventoryCountItem $item) use ($value, $attributes): bool {
            foreach ($attributes as $attribute) {
                if (str_contains($this->normalizeCollectionFilterValue($item->{$attribute}), $value)) {
                    return true;
                }
            }

            return false;
        });
    }

    private function normalizeCollectionFilterValue(mixed $value): string
    {
        return mb_strtolower(trim(mb_convert_kana((string) ($value ?? ''), 'as')));
    }

    // ========================================
    // Edit Modal
    // ========================================

    public function openEditModal(int $itemId): void
    {
        $item = $this->inventoryCountItemsQuery()
            ->where('id', $itemId)
            ->first();

        if (! $item) {
            return;
        }

        $this->editItemId = $item->id;
        $this->editFirstCountQty = $item->first_count_quantity !== null ? (string) (int) $item->first_count_quantity : '';
        $this->editSecondCountQty = $item->second_count_quantity !== null ? (string) (int) $item->second_count_quantity : '';
        $this->editFinalCountQty = $item->final_count_quantity !== null ? (string) (int) $item->final_count_quantity : '';
        $this->editModalOpen = true;
    }

    public function closeEditModal(): void
    {
        $this->editModalOpen = false;
        $this->editItemId = null;
        $this->editFirstCountQty = '';
        $this->editSecondCountQty = '';
        $this->editFinalCountQty = '';
    }

    public function editModalItem(): ?WmsInventoryCountItem
    {
        if (! $this->editItemId) {
            return null;
        }

        return $this->inventoryCountItemsQuery()
            ->whereKey($this->editItemId)
            ->first();
    }

    public function saveEditModal(): void
    {
        if ($this->isRoundConfirmed($this->activeCountRound)) {
            Notification::make()->danger()->title('確定済みの回数は編集できません')->send();

            return;
        }

        if (! in_array($this->record->status, [
            WmsInventoryCount::STATUS_DRAFT,
            WmsInventoryCount::STATUS_COUNTING,
            WmsInventoryCount::STATUS_CHECKED,
        ])) {
            Notification::make()->danger()->title('このステータスでは編集できません')->send();

            return;
        }

        $item = $this->inventoryCountItemsQuery()
            ->where('id', $this->editItemId)
            ->first();

        if (! $item) {
            Notification::make()->danger()->title('明細が見つかりません')->send();

            return;
        }

        $first = $this->activeCountRound === 1
            ? ($this->editFirstCountQty !== '' ? (int) $this->editFirstCountQty : null)
            : $item->first_count_quantity;
        $second = $this->activeCountRound === 2
            ? ($this->editSecondCountQty !== '' ? (int) $this->editSecondCountQty : null)
            : $item->second_count_quantity;
        $final = $this->activeCountRound === 3
            ? ($this->editFinalCountQty !== '' ? (int) $this->editFinalCountQty : null)
            : $item->final_count_quantity;

        $oldFirst = $item->first_count_quantity;
        $oldSecond = $item->second_count_quantity;
        $oldFinal = $item->final_count_quantity;

        if ($this->record->status === WmsInventoryCount::STATUS_DRAFT) {
            (new InventoryCountService)->startCounting($this->record);
            $this->record->refresh();
        }

        $item->first_count_quantity = $first;
        $item->second_count_quantity = $second;
        $item->final_count_quantity = $final;
        $this->setChangedActorNames($item, [
            1 => [$oldFirst, $first],
            2 => [$oldSecond, $second],
            3 => [$oldFinal, $final],
        ]);
        $item->last_counted_at = now();
        $item->input_count = ($item->input_count ?? 0) + 1;

        if ($this->record->status === WmsInventoryCount::STATUS_CHECKED) {
            $finalQty = $final ?? $second ?? $first;
            if ($finalQty !== null) {
                $item->final_count_quantity = $finalQty;
                $this->setLiveDifference($item, (int) $finalQty);
            } else {
                $item->final_count_quantity = null;
                $this->setLiveDifference($item, null);
            }
        }

        $item->save();
        $this->writeWebCountLogs($item, [
            1 => [$oldFirst, $first],
            2 => [$oldSecond, $second],
            3 => [$oldFinal, $final],
        ]);

        Notification::make()->success()->title('カウント数を保存しました')->send();
        $this->closeEditModal();
    }

    // ========================================
    // Inline Save
    // ========================================

    public function saveInlineChanges(array $changes): void
    {
        if ($this->isRoundConfirmed($this->activeCountRound)) {
            Notification::make()->danger()->title('確定済みの回数は編集できません')->send();

            return;
        }

        if (! in_array($this->record->status, [
            WmsInventoryCount::STATUS_DRAFT,
            WmsInventoryCount::STATUS_COUNTING,
            WmsInventoryCount::STATUS_CHECKED,
        ])) {
            Notification::make()->danger()->title('このステータスでは編集できません')->send();

            return;
        }

        $count = 0;
        if ($this->record->status === WmsInventoryCount::STATUS_DRAFT) {
            (new InventoryCountService)->startCounting($this->record);
            $this->record->refresh();
        }

        foreach ($changes as $itemId => $data) {
            $item = $this->inventoryCountItemsQuery()
                ->where('id', (int) $itemId)
                ->first();

            if (! $item) {
                continue;
            }

            $first = $this->activeCountRound === 1
                ? (isset($data['first']) && $data['first'] !== null ? (int) $data['first'] : null)
                : $item->first_count_quantity;
            $second = $this->activeCountRound === 2
                ? (isset($data['second']) && $data['second'] !== null ? (int) $data['second'] : null)
                : $item->second_count_quantity;
            $final = $this->activeCountRound === 3
                ? (isset($data['final']) && $data['final'] !== null ? (int) $data['final'] : null)
                : $item->final_count_quantity;

            $oldFirst = $item->first_count_quantity;
            $oldSecond = $item->second_count_quantity;
            $oldFinal = $item->final_count_quantity;

            $item->first_count_quantity = $first;
            $item->second_count_quantity = $second;
            $item->final_count_quantity = $final;
            $this->setChangedActorNames($item, [
                1 => [$oldFirst, $first],
                2 => [$oldSecond, $second],
                3 => [$oldFinal, $final],
            ]);
            $item->last_counted_at = now();
            $item->input_count = ($item->input_count ?? 0) + 1;

            $countedQty = match ($this->activeCountRound) {
                1 => $first,
                2 => $second ?? $first,
                3 => $final,
            };
            $this->setLiveDifference($item, $countedQty);

            $item->save();
            $this->writeWebCountLogs($item, [
                1 => [$oldFirst, $first],
                2 => [$oldSecond, $second],
                3 => [$oldFinal, $final],
            ]);
            $count++;
        }

        Notification::make()->success()->title("{$count}件のカウント数を保存しました")->send();
    }

    public function calculateActiveRoundDifferences(): void
    {
        if ($this->isRoundConfirmed($this->activeCountRound)) {
            Notification::make()->danger()->title('確定済みの差異は再計算できません')->send();

            return;
        }

        if (! in_array($this->record->status, [
            WmsInventoryCount::STATUS_COUNTING,
            WmsInventoryCount::STATUS_CHECKED,
        ], true)) {
            Notification::make()->danger()->title('カウント開始後に差異計算できます')->send();

            return;
        }

        $this->calculateRoundDifferences($this->activeCountRound);

        $this->listTab = 'diff';
        $this->itemPage = 1;

        Notification::make()->success()->title($this->activeRoundLabel().'の差異計算が完了しました')->send();
    }

    public function fillActiveRoundUncountedWithZero(): void
    {
        $round = $this->activeCountRound;

        if ($this->record->status !== WmsInventoryCount::STATUS_COUNTING) {
            Notification::make()->danger()->title('カウント中のみ未カウントを0にできます')->send();

            return;
        }

        if ($round !== $this->currentProgressRound() || $this->isRoundConfirmed($round)) {
            Notification::make()->danger()->title('現在回数の未カウントだけ0にできます')->send();

            return;
        }

        $roundColumn = $this->roundColumn($round);
        $actorColumn = $this->roundActorNameColumn($round);
        $actorName = $this->currentWebActorName();
        $count = 0;

        $this->inventoryCountItemsQuery()
            ->managedStockItems()
            ->whereNull($roundColumn)
            ->chunkById(500, function ($items) use ($round, $roundColumn, $actorColumn, $actorName, &$count) {
                foreach ($items as $item) {
                    $oldQuantity = $item->{$roundColumn};
                    $item->{$roundColumn} = 0;
                    $item->{$actorColumn} = $actorName;
                    $item->last_counted_at = now();
                    $item->input_count = ($item->input_count ?? 0) + 1;
                    $this->setLiveDifference($item, 0);
                    $item->save();

                    $this->writeWebCountLogs($item, [
                        $round => [$oldQuantity, 0],
                    ], WmsInventoryCountItemLog::DEVICE_WEB_AUTO_ZERO);

                    $count++;
                }
            });

        $this->listTab = 'all';
        $this->itemPage = 1;

        Notification::make()
            ->success()
            ->title($this->activeRoundLabel().'の未カウントに0を入力しました')
            ->body("対象明細: {$count}件")
            ->send();
    }

    public function confirmRound(int $round): void
    {
        if (! in_array($round, [1, 2, 3], true)) {
            return;
        }

        if (! in_array($this->record->status, [
            WmsInventoryCount::STATUS_COUNTING,
            WmsInventoryCount::STATUS_CHECKED,
        ], true)) {
            Notification::make()->danger()->title('カウント開始後に確定できます')->send();

            return;
        }

        if ($round > $this->currentProgressRound()) {
            Notification::make()->danger()->title('現在進行中より先の回数は確定できません')->send();

            return;
        }

        if ($this->isRoundConfirmed($round)) {
            Notification::make()->danger()->title('確定済みの回数は再確定できません')->send();

            return;
        }

        if (! $this->roundConfirmedDifferenceColumnsExist($round)) {
            Notification::make()->danger()->title('確定差分保存用のDB列が未作成です')->send();

            return;
        }

        $updates = [
            $this->roundConfirmedAtColumn($round) => now(),
            $this->roundConfirmedByColumn($round) => auth()->id(),
        ];

        if ($round < 3) {
            $updates['current_count_round'] = max($this->currentProgressRound(), $round + 1);
            $updates['status'] = WmsInventoryCount::STATUS_COUNTING;

            DB::connection('sakemaru')->transaction(function () use ($round, $updates): void {
                if ($round === 2) {
                    $this->fillMissingSecondRoundQuantitiesFromFirst();
                }

                $this->storeConfirmedRoundDifferences($round);
                $this->seedNextRoundQuantity($round);
                $this->record->update($updates);
            });

            $this->record->refresh();
            $this->activeCountRound = $this->currentProgressRound();
            $this->listTab = 'all';
            $this->itemPage = 1;
            Notification::make()
                ->success()
                ->title($this->roundLabel($round).'を確定しました')
                ->body($this->activeRoundLabel().'の入力に進みます')
                ->send();

            return;
        }

        $updates['current_count_round'] = 3;
        $updates['status'] = WmsInventoryCount::STATUS_CHECKED;

        DB::connection('sakemaru')->transaction(function () use ($round, $updates): void {
            $this->storeConfirmedRoundDifferences($round);
            $this->record->update($updates);
        });

        $this->record->refresh();
        Notification::make()->success()->title('3回目を確定しました')->body('差異確認済に変更しました')->send();
    }

    public function confirmActiveRound(): void
    {
        $this->confirmRound($this->activeCountRound);
    }

    public function reopenFinalRound(): void
    {
        if ($this->record->status !== WmsInventoryCount::STATUS_CHECKED) {
            Notification::make()->danger()->title('3回目に戻せる状態ではありません')->send();

            return;
        }

        $this->record->update([
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'current_count_round' => 3,
            'final_count_confirmed_at' => null,
            'final_count_confirmed_by' => null,
        ]);

        if ($this->roundConfirmedDifferenceColumnsExist(3)) {
            $this->inventoryCountItemsQuery()->update([
                'final_count_confirmed_system_quantity' => null,
                'final_count_confirmed_difference_quantity' => null,
                'final_count_confirmed_difference_amount' => null,
            ]);
        }

        $this->record->refresh();
        $this->activeCountRound = 3;
        $this->listTab = 'all';
        $this->itemPage = 1;

        Notification::make()->success()->title('3回目の入力に戻しました')->send();
    }

    private function writeWebCountLogs(WmsInventoryCountItem $item, array $rounds, string $deviceId = WmsInventoryCountItemLog::DEVICE_WEB): void
    {
        foreach ($rounds as $round => [$old, $new]) {
            if ((string) $old === (string) $new) {
                continue;
            }

            WmsInventoryCountItemLog::create([
                'inventory_count_item_id' => $item->id,
                'device_id' => $deviceId,
                'user_id' => auth()->id(),
                'count_round' => $round,
                'old_quantity' => $old,
                'new_quantity' => $new ?? 0,
                'request_uuid' => (string) \Illuminate\Support\Str::uuid(),
                'created_at' => now(),
            ]);
        }
    }

    private function setChangedActorNames(WmsInventoryCountItem $item, array $rounds): void
    {
        $actorName = $this->currentWebActorName();

        foreach ($rounds as $round => [$old, $new]) {
            if ((string) $old === (string) $new) {
                continue;
            }

            $item->{$this->roundActorNameColumn($round)} = $actorName;
        }
    }

    private function currentWebActorName(): string
    {
        return auth()->user()?->name
            ? 'WEB: '.auth()->user()->name
            : 'WEB';
    }

    private function currentProgressRound(): int
    {
        $round = (int) ($this->record->current_count_round ?: 1);

        return min(max($round, 1), 3);
    }

    private function storeConfirmedRoundDifferences(int $round): void
    {
        (new InventoryCountService)->storeConfirmedRoundDifferences($this->record, $round);
    }

    private function fillMissingSecondRoundQuantitiesFromFirst(): void
    {
        $this->inventoryCountItemsQuery()
            ->whereNull('second_count_quantity')
            ->whereNotNull('first_count_quantity')
            ->update([
                'second_count_quantity' => DB::raw('first_count_quantity'),
                'updated_at' => now(),
            ]);
    }

    private function calculateRoundDifferences(int $round): void
    {
        $this->inventoryCountItemsQuery()
            ->chunkById(500, function ($items) use ($round) {
                foreach ($items as $item) {
                    $countedQty = $item->roundQuantity($round);

                    if ($countedQty === null) {
                        $this->setLiveDifference($item, null);
                    } else {
                        $this->setLiveDifference($item, (int) $countedQty);
                    }

                    $item->save();
                }
            });
    }

    private function setLiveDifference(WmsInventoryCountItem $item, ?int $countedQty): void
    {
        $systemQty = $item->ending_system_quantity ?? $item->system_quantity;

        if ($countedQty === null || $systemQty === null) {
            $item->difference_quantity = null;
            $item->difference_amount = null;

            return;
        }

        $item->difference_quantity = (int) $countedQty - (int) $systemQty;
        $item->difference_amount = (float) $item->difference_quantity * (float) $item->cost_price;
    }

    private function seedNextRoundQuantity(int $round): void
    {
        $currentColumn = $this->roundColumn($round);
        $nextColumn = $this->roundColumn($round + 1);

        $this->inventoryCountItemsQuery()
            ->when($round === 2, fn ($query) => $query->where(function ($query) use ($currentColumn) {
                $query->whereNotNull($currentColumn)
                    ->orWhereNotNull('first_count_quantity');
            }), fn ($query) => $query->whereNotNull($currentColumn))
            ->whereNull($nextColumn)
            ->update([
                $nextColumn => DB::raw($round === 2 ? 'COALESCE(second_count_quantity, first_count_quantity)' : $currentColumn),
                'updated_at' => now(),
            ]);
    }

    private function roundColumn(int $round): string
    {
        return match ($round) {
            1 => 'first_count_quantity',
            2 => 'second_count_quantity',
            3 => 'final_count_quantity',
            default => 'first_count_quantity',
        };
    }

    private function roundActorNameColumn(int $round): string
    {
        return match ($round) {
            1 => 'first_count_actor_name',
            2 => 'second_count_actor_name',
            3 => 'final_count_actor_name',
            default => 'first_count_actor_name',
        };
    }

    private function roundConfirmedSystemQuantityColumn(int $round): string
    {
        return match ($round) {
            1 => 'first_count_confirmed_system_quantity',
            2 => 'second_count_confirmed_system_quantity',
            3 => 'final_count_confirmed_system_quantity',
            default => 'first_count_confirmed_system_quantity',
        };
    }

    private function roundConfirmedDifferenceQuantityColumn(int $round): string
    {
        return match ($round) {
            1 => 'first_count_confirmed_difference_quantity',
            2 => 'second_count_confirmed_difference_quantity',
            3 => 'final_count_confirmed_difference_quantity',
            default => 'first_count_confirmed_difference_quantity',
        };
    }

    private function roundConfirmedDifferenceAmountColumn(int $round): string
    {
        return match ($round) {
            1 => 'first_count_confirmed_difference_amount',
            2 => 'second_count_confirmed_difference_amount',
            3 => 'final_count_confirmed_difference_amount',
            default => 'first_count_confirmed_difference_amount',
        };
    }

    private function roundConfirmedDifferenceColumnsExist(int $round): bool
    {
        return $this->inventoryCountItemColumnExists($this->roundConfirmedSystemQuantityColumn($round))
            && $this->inventoryCountItemColumnExists($this->roundConfirmedDifferenceQuantityColumn($round))
            && $this->inventoryCountItemColumnExists($this->roundConfirmedDifferenceAmountColumn($round));
    }

    private function inventoryCountItemColumnExists(string $column): bool
    {
        return Schema::connection('sakemaru')->hasColumn('wms_inventory_count_items', $column);
    }

    private function roundConfirmedAtColumn(int $round): string
    {
        return match ($round) {
            1 => 'first_count_confirmed_at',
            2 => 'second_count_confirmed_at',
            3 => 'final_count_confirmed_at',
            default => 'first_count_confirmed_at',
        };
    }

    private function roundConfirmedByColumn(int $round): string
    {
        return match ($round) {
            1 => 'first_count_confirmed_by',
            2 => 'second_count_confirmed_by',
            3 => 'final_count_confirmed_by',
            default => 'first_count_confirmed_by',
        };
    }

    // ========================================
    // Header Actions
    // ========================================

    protected function getHeaderActions(): array
    {
        $record = $this->record;

        return [
            Action::make('viewLogs')
                ->label('ログ')
                ->icon('heroicon-o-list-bullet')
                ->color('gray')
                ->url(fn () => WmsInventoryCountResource::getUrl('logs', ['record' => $record])),

            Action::make('addSingleItem')
                ->label('商品追加')
                ->icon('heroicon-o-plus-circle')
                ->color('success')
                ->visible(fn () => ! in_array($record->status, [
                    WmsInventoryCount::STATUS_CONFIRMED,
                    WmsInventoryCount::STATUS_CANCELLED,
                ], true))
                ->schema([
                    TextInput::make('item_code')
                        ->label('商品CD')
                        ->required()
                        ->maxLength(20)
                        ->autocomplete(false),
                ])
                ->modalHeading('単品追加')
                ->modalDescription('商品CDを入力して、今回の棚卸しに追加します。既に登録済みの在庫行は追加しません。')
                ->modalSubmitActionLabel('追加')
                ->modalCancelActionLabel('追加せず閉じる')
                ->action(function (array $data) use ($record) {
                    try {
                        $result = (new InventoryCountService)->addSingleItemByCode($record, (string) ($data['item_code'] ?? ''));
                        $this->record->refresh();
                        $this->itemCodeFilter = $result['item_code'];
                        $this->listTab = 'all';
                        $this->itemPage = 1;

                        Notification::make()
                            ->success()
                            ->title('単品を追加しました')
                            ->body("追加: {$result['inserted_count']}件 / 登録済み: {$result['existing_count']}件")
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->danger()
                            ->title('単品を追加できません')
                            ->body($e->getMessage())
                            ->send();
                    }
                }),

            Action::make('saveCurrentStock')
                ->label('現状保存')
                ->icon('heroicon-o-bookmark-square')
                ->color('warning')
                ->visible(fn () => $record->canSaveCurrentStock())
                ->requiresConfirmation()
                ->modalHeading('現状保存')
                ->modalDescription('現在の棚卸し内容を現状保存に変更します。理論在庫や実棚数は変更しません。')
                ->modalFooterActionsAlignment(Alignment::End)
                ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('保存する')->color('danger'))
                ->modalCancelActionLabel('保存せず閉じる')
                ->action(function () use ($record) {
                    try {
                        (new InventoryCountService)->saveCurrentStock($record);
                        $this->record->refresh();
                        $this->itemPage = 1;

                        Notification::make()
                            ->success()
                            ->title('現状保存しました')
                            ->body('理論在庫や実棚数は変更していません。')
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->danger()
                            ->title('現状保存できません')
                            ->body($e->getMessage())
                            ->send();
                    }
                }),

            Action::make('resumeCurrentStockSavedForCounting')
                ->label('カウント再開')
                ->icon('heroicon-o-play')
                ->color('warning')
                ->visible(fn () => $record->canResumeCurrentStockSaved())
                ->requiresConfirmation()
                ->modalHeading('カウント再開')
                ->modalDescription('現状保存を取り消し、カウント中に戻します。理論在庫や実棚数は変更しません。終了時在庫取得と理論在庫更新を再度実行できます。')
                ->modalFooterActionsAlignment(Alignment::End)
                ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('再開する')->color('danger'))
                ->modalCancelActionLabel('再開せず閉じる')
                ->action(function () use ($record) {
                    try {
                        (new InventoryCountService)->resumeCurrentStockSavedForCounting($record);
                        $this->record->refresh();
                        $this->itemPage = 1;

                        Notification::make()
                            ->success()
                            ->title('カウントを再開しました')
                            ->body('終了時在庫取得と理論在庫更新を実行できます。')
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->danger()
                            ->title('棚卸しを再開できません')
                            ->body($e->getMessage())
                            ->send();
                    }
                }),

            Action::make('refreshCurrentStock')
                ->label('終了時在庫取得')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn () => $record->canRefreshSystemQuantities())
                ->requiresConfirmation()
                ->modalHeading('終了時在庫取得')
                ->modalDescription('現在の在庫数を理論在庫として取得します。理論在庫(開始)、実棚数、差異数量、現状保存状態は変更しません。初回生成時になかった在庫は理論在庫(開始)0で明細追加します。')
                ->modalFooterActionsAlignment(Alignment::End)
                ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('取得する')->color('danger'))
                ->modalCancelActionLabel('取得せず閉じる')
                ->action(function () use ($record) {
                    try {
                        $result = (new InventoryCountService)->refreshSystemQuantities($record);
                        $this->record->refresh();
                        $this->itemPage = 1;

                        Notification::make()
                            ->success()
                            ->title('終了時在庫を取得しました')
                            ->body("理論在庫: {$result['updated_items']}件 / 追加明細: {$result['inserted_items']}件 / 未取得: {$result['missing_real_stocks']}件")
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->danger()
                            ->title('終了時在庫を取得できません')
                            ->body($e->getMessage())
                            ->send();
                    }
                }),

            Action::make('refreshDailySnapshotStock')
                ->label('理論在庫更新')
                ->icon('heroicon-o-calendar-days')
                ->color('warning')
                ->visible(fn () => $record->canRefreshSystemQuantities())
                ->requiresConfirmation()
                ->modalHeading('理論在庫更新')
                ->modalDescription('選択した日の終了時点の受払残を再計算し、理論在庫に反映します。理論在庫(開始)、実棚数、現状保存状態は変更しません。')
                ->modalFooterActionsAlignment(Alignment::End)
                ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('更新する')->color('danger'))
                ->modalCancelActionLabel('更新せず閉じる')
                ->schema([
                    DatePicker::make('snapshot_date')
                        ->label('受払終了日')
                        ->default($record->count_date?->toDateString() ?? now()->toDateString())
                        ->maxDate(now())
                        ->required(),
                ])
                ->action(function (array $data) use ($record) {
                    try {
                        $result = (new InventoryCountService)->refreshEndingSystemQuantitiesFromLedger($record, (string) $data['snapshot_date']);
                        $this->record->refresh();
                        $this->itemPage = 1;

                        Notification::make()
                            ->success()
                            ->title('理論在庫を更新しました')
                            ->body("受払終了日: {$result['end_date']} / 理論在庫: {$result['updated_items']}件 / 追加明細: {$result['inserted_items']}件 / 対象外: {$result['skipped_items']}件 / バックアップID: {$result['backup_run_id']}")
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->danger()
                            ->title('理論在庫を更新できません')
                            ->body($e->getMessage())
                            ->send();
                    }
                }),

            Action::make('refreshSecondRoundConfirmedDifferences')
                ->label('2回目差異再計算')
                ->icon('heroicon-o-calculator')
                ->color('warning')
                ->visible(fn () => $record->second_count_confirmed_at !== null
                    && $record->final_count_confirmed_at === null
                    && ! $record->isCurrentStockSaved()
                    && ! in_array($record->status, [
                        WmsInventoryCount::STATUS_CONFIRMED,
                        WmsInventoryCount::STATUS_CANCELLED,
                    ], true))
                ->requiresConfirmation()
                ->modalHeading('2回目差異再計算')
                ->modalDescription('現在の理論在庫を基準に、2回目確定時の理論在庫・差異数量・差異金額を再保存します。1回目確定差異、入力数量、3回目数量は変更しません。更新前の行はバックアップします。')
                ->modalFooterActionsAlignment(Alignment::End)
                ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('再計算する')->color('danger'))
                ->modalCancelActionLabel('再計算せず閉じる')
                ->action(function () use ($record) {
                    try {
                        $result = (new InventoryCountService)->refreshSecondRoundConfirmedDifferences($record);
                        $this->record->refresh();
                        $this->itemPage = 1;

                        Notification::make()
                            ->success()
                            ->title('2回目確定差異を再計算しました')
                            ->body("対象: {$result['target_items']}件 / 差異あり: {$result['difference_items']}件 / 更新: {$result['updated_items']}件 / バックアップID: {$result['backup_run_id']}")
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->danger()
                            ->title('2回目確定差異を再計算できません')
                            ->body($e->getMessage())
                            ->send();
                    }
                }),

            Action::make('calculatePostCountMovements')
                ->label('受払計算')
                ->icon('heroicon-o-calculator')
                ->color('warning')
                ->visible(fn () => $record->canCalculatePostCountMovements())
                ->modalHeading('受払計算')
                ->modalDescription('棚卸し実施日時以降の受払をai-coreの受払履歴と同じ伝票日・出荷日・払出日・調整日基準で集計し、入力済み商品の受払合計に反映します。')
                ->modalFooterActionsAlignment(Alignment::End)
                ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('計算する')->color('danger'))
                ->modalCancelActionLabel('計算せず閉じる')
                ->schema([
                    DateTimePicker::make('counted_at')
                        ->label('棚卸し実施日時')
                        ->default($record->stock_movement_from_at?->format('Y-m-d H:i:s') ?? $record->count_date?->format('Y-m-d 02:00:00') ?? now()->format('Y-m-d H:i:s'))
                        ->maxDate(now())
                        ->required(),
                ])
                ->action(function (array $data) use ($record) {
                    try {
                        $result = (new InventoryCountService)->calculatePostCountMovements($record, (string) $data['counted_at']);
                        $this->record->refresh();
                        $this->itemPage = 1;

                        Notification::make()
                            ->success()
                            ->title('受払計算が完了しました')
                            ->body("実施日時: {$result['from_at']} / 入力済み商品: {$result['counted_item_count']}件 / 受払あり: {$result['moved_item_count']}件")
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->danger()
                            ->title('受払計算できません')
                            ->body($e->getMessage())
                            ->send();
                    }
                }),

            Action::make('downloadInstructionPdf')
                ->label('JAN')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->visible(fn () => $record->status !== WmsInventoryCount::STATUS_CANCELLED)
                ->action(function () use ($record) {
                    $pdfContent = (new InventoryInstructionPdfService)->generate($record);
                    $filename = 'JANブック_'.($record->count_no ?? 'unknown').'.pdf';

                    return response()->streamDownload(
                        fn () => print ($pdfContent),
                        $filename,
                        ['Content-Type' => 'application/pdf']
                    );
                }),

            Action::make('downloadInstructionSheet')
                ->label('指示書')
                ->icon('heroicon-o-clipboard-document-list')
                ->color('gray')
                ->visible(fn () => $record->status !== WmsInventoryCount::STATUS_CANCELLED)
                ->schema([
                    Select::make('category_ids')
                        ->label('中分類')
                        ->options(fn () => (new InventoryInstructionSheetPdfService)->getCategoryOptions($record))
                        ->multiple()
                        ->searchable()
                        ->placeholder('全て（未選択で全部門出力）'),
                ])
                ->modalHeading('指示書ダウンロード')
                ->modalDescription('中分類を選択して指示書をダウンロードします。未選択の場合は全部門が出力されます。')
                ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                ->modalFooterActionsAlignment(Alignment::End)
                ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('ダウンロード')->color('danger'))
                ->modalCancelActionLabel('ダウンロードせず閉じる')
                ->action(function (array $data) use ($record) {
                    $categoryIds = ! empty($data['category_ids']) ? array_map('intval', $data['category_ids']) : null;
                    $pdfContent = (new InventoryInstructionSheetPdfService)->generate($record, $categoryIds, InventoryInstructionSheetPdfService::ITEM_SCOPE_ALL, true);
                    $filename = '棚卸し指示書_'.($record->count_no ?? 'unknown').'.pdf';

                    return response()->streamDownload(
                        fn () => print ($pdfContent),
                        $filename,
                        ['Content-Type' => 'application/pdf']
                    );
                }),

            Action::make('toggleHandyReception')
                ->label(fn () => $record->handy_reception ? 'Handy受付 ON' : 'Handy受付 OFF')
                ->icon(fn () => $record->handy_reception ? 'heroicon-o-signal' : 'heroicon-o-signal-slash')
                ->color(fn () => $record->handy_reception ? 'success' : 'gray')
                ->disabled(fn () => ! $record->canToggleHandyReception())
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

            Action::make('startCounting')
                ->label('カウント開始')
                ->icon('heroicon-o-play')
                ->color('primary')
                ->visible(fn () => $record->status === WmsInventoryCount::STATUS_DRAFT)
                ->requiresConfirmation()
                ->modalHeading('カウント開始')
                ->modalDescription('棚卸しカウントを開始します。')
                ->action(function () use ($record) {
                    (new InventoryCountService)->startCounting($record);
                    Notification::make()->success()->title('カウントを開始しました')->send();

                    return redirect()->route('filament.admin.resources.wms-inventory-counts.view', $record);
                }),

            Action::make('calculateDifferences')
                ->label('差異計算')
                ->icon('heroicon-o-calculator')
                ->color('warning')
                ->visible(fn () => $record->status === WmsInventoryCount::STATUS_COUNTING)
                ->action(function () use ($record) {
                    (new InventoryCountService)->calculateDifferences($record);
                    Notification::make()->success()->title('差異計算が完了しました')->send();

                    return redirect()->route('filament.admin.resources.wms-inventory-counts.view', $record);
                }),

            Action::make('downloadDiffListPdf')
                ->label('差分PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () use ($record) {
                    $pdfContent = (new InventoryDiffListPdfService)->generate($record, $this->activeCountRound);
                    $filename = '棚卸差分確認_'.$this->activeRoundLabel().'_'.($record->count_no ?? 'unknown').'.pdf';

                    return response()->streamDownload(
                        fn () => print ($pdfContent),
                        $filename,
                        ['Content-Type' => 'application/pdf']
                    );
                }),

            Action::make('downloadUncountedListPdf')
                ->label('未PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->visible(fn () => $record->status !== WmsInventoryCount::STATUS_DRAFT)
                ->action(function () use ($record) {
                    $pdfContent = (new InventoryDiffListPdfService)->generateUncounted($record, $this->activeCountRound);
                    $filename = '棚卸未カウント_'.$this->activeRoundLabel().'_'.($record->count_no ?? 'unknown').'.pdf';

                    return response()->streamDownload(
                        fn () => print ($pdfContent),
                        $filename,
                        ['Content-Type' => 'application/pdf']
                    );
                }),

            Action::make('downloadDifferenceWorkbook')
                ->label('差異データ')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->visible(fn () => $record->status !== WmsInventoryCount::STATUS_DRAFT)
                ->schema([
                    Select::make('target_round')
                        ->label('出力回')
                        ->options(fn () => $this->differenceWorkbookRoundOptions())
                        ->default(fn () => $this->defaultDifferenceWorkbookRound())
                        ->required()
                        ->native(false),
                ])
                ->modalHeading('差異データダウンロード')
                ->modalDescription('選択した回数までの差異データを出力します。未確定の進行中回数も出力できます。')
                ->modalFooterActionsAlignment(Alignment::End)
                ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('ダウンロード')->color('danger'))
                ->modalCancelActionLabel('ダウンロードせず閉じる')
                ->action(function (array $data) use ($record) {
                    $targetRound = (int) ($data['target_round'] ?? $this->defaultDifferenceWorkbookRound());
                    $xlsxContent = (new InventoryDifferenceWorkbookService)->generate($record, $targetRound);
                    $filename = '棚卸差異データ_'.$this->roundLabel($targetRound).'_'.($record->count_no ?? 'unknown').'.xlsx';

                    return response()->streamDownload(
                        fn () => print ($xlsxContent),
                        $filename,
                        ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
                    );
                }),

            Action::make('restoreCancelledForCounting')
                ->label('取消キャンセル')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->visible(fn () => $record->status === WmsInventoryCount::STATUS_CANCELLED)
                ->requiresConfirmation()
                ->modalHeading('棚卸し取消キャンセル')
                ->modalDescription('取消済みの棚卸しをカウント中に戻します。カウント入力を再開できます。')
                ->modalFooterActionsAlignment(Alignment::End)
                ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('カウント中に戻す')->color('danger'))
                ->modalCancelActionLabel('戻さず閉じる')
                ->action(function () use ($record) {
                    try {
                        (new InventoryCountService)->restoreCancelledForCounting($record);
                        Notification::make()->success()->title('棚卸しをカウント中に戻しました')->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->danger()
                            ->title('棚卸しを戻せません')
                            ->body($e->getMessage())
                            ->send();

                        return null;
                    }

                    return redirect()->route('filament.admin.resources.wms-inventory-counts.view', $record);
                }),

            Action::make('fillUncountedWithZero')
                ->label('未0')
                ->icon('heroicon-o-check-circle')
                ->color('warning')
                ->visible(false)
                ->requiresConfirmation()
                ->modalHeading(fn () => $this->activeRoundLabel().'未カウント0入力')
                ->modalDescription(fn () => $this->activeRoundLabel().'の未カウント明細に0を入力します。現在回数以外の数量は変更しません。')
                ->modalSubmitActionLabel('0入力')
                ->modalCancelActionLabel('0入力せず閉じる')
                ->action(fn () => $this->fillActiveRoundUncountedWithZero()),

            Action::make('downloadEnteredListWorkbook')
                ->label('入力済Excel')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->visible(fn () => ! in_array($record->status, [
                    WmsInventoryCount::STATUS_DRAFT,
                    WmsInventoryCount::STATUS_CANCELLED,
                ], true))
                ->schema([
                    Select::make('target_round')
                        ->label('出力回')
                        ->options(fn () => $this->differenceWorkbookRoundOptions())
                        ->default(fn () => $this->defaultDifferenceWorkbookRound())
                        ->required()
                        ->native(false),
                ])
                ->modalHeading('入力済Excelダウンロード')
                ->modalDescription('選択した回数で、実数量が入力された明細だけを出力します。')
                ->modalFooterActionsAlignment(Alignment::End)
                ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('ダウンロード')->color('danger'))
                ->modalCancelActionLabel('ダウンロードせず閉じる')
                ->action(function (array $data) use ($record) {
                    $targetRound = (int) ($data['target_round'] ?? $this->defaultDifferenceWorkbookRound());
                    $xlsxContent = (new InventoryEnteredListWorkbookService)->generate($record, $targetRound);
                    $filename = '棚卸入力済リスト_'.$this->roundLabel($targetRound).'_'.($record->count_no ?? 'unknown').'.xlsx';

                    return response()->streamDownload(
                        fn () => print ($xlsxContent),
                        $filename,
                        ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
                    );
                }),

            Action::make('reopenFinalRound')
                ->label('3回目に戻す')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->visible(fn () => $record->status === WmsInventoryCount::STATUS_CHECKED)
                ->requiresConfirmation()
                ->modalHeading('3回目に戻す')
                ->modalDescription('最終確定前の状態に戻し、3回目の入力を再開します。入力済みの3回目数量は削除しません。')
                ->action(fn () => $this->reopenFinalRound()),

            Action::make('confirm')
                ->label('確定')
                ->icon('heroicon-o-check-circle')
                ->color('info')
                ->visible(fn () => $record->status === WmsInventoryCount::STATUS_CHECKED)
                ->action(fn () => Notification::make()
                    ->warning()
                    ->title(InventoryCountService::CONFIRM_DISABLED_MESSAGE)
                    ->send()),

            Action::make('cancel')
                ->label('取消')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => ! in_array($record->status, [
                    WmsInventoryCount::STATUS_CONFIRMED,
                    WmsInventoryCount::STATUS_CANCELLED,
                ]))
                ->requiresConfirmation()
                ->modalHeading('棚卸し取消')
                ->modalDescription('この棚卸しを取り消します。この操作は元に戻せません。')
                ->action(function () use ($record) {
                    (new InventoryCountService)->cancel($record);
                    Notification::make()->success()->title('棚卸しを取り消しました')->send();

                    return redirect()->route('filament.admin.resources.wms-inventory-counts.index');
                }),
        ];
    }
}
