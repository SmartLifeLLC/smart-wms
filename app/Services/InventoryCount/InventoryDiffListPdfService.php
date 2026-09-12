<?php

namespace App\Services\InventoryCount;

use App\Models\Sakemaru\ItemCategory;
use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use TCPDF;

class InventoryDiffListPdfService
{
    private const UNCOUNTED_TARGET_MAJOR_CATEGORY_CODES = [1001, 1002, 1003, 1006];

    private const FONT_SIZE_TITLE = 18;

    private const FONT_SIZE_HEADER = 9;

    private const FONT_SIZE_NORMAL = 8;

    private const FONT_SIZE_COL_HEADER = 7;

    private const BLOCK_ROW_HEIGHT = 6.5;

    private const CATEGORY_HEADER_HEIGHT = 6;

    private const LINE_WIDTH = 0.2;

    private const MARGIN_LEFT = 10;

    private const MARGIN_TOP = 8;

    private const MARGIN_RIGHT = 10;

    private const MARGIN_BOTTOM = 12;

    // A4 Portrait
    private const PAGE_WIDTH = 210;

    private const PAGE_HEIGHT = 297;

    private const CONTENT_WIDTH = 190; // 210 - 10 - 10

    private const COL_W_JAN = 36;

    private const COL_W_ITEM = 100;

    private const COL_W_LOCATION = 12;

    private const COL_W_LOT = 20;

    private const COL_W_EXPIRATION = 22;

    private const COL_W_INPUT = 10;

    private const COL_W_SYSTEM = 16;

    private const COL_W_ACTUAL = 14;

    private const COL_W_DIFF = 14;

    private const BARCODE_WIDTH = 34;

    private const BARCODE_HEIGHT = 7.5;

    private const BARCODE_TEXT_HEIGHT = 3;

    private const FONT_SIZE_JAN = 7;

    private TCPDF $pdf;

    private float $currentY;

    private int $totalPages = 0;

    private string $pdfTitle = '棚卸差異リスト';

    private string $emptyMessage = '差異データなし';

    private ?int $diffRound = null;

    private ?int $uncountedRound = null;

    public function generate(WmsInventoryCount $inventoryCount, ?int $round = null): string
    {
        $this->diffRound = $round === null ? null : min(max($round, 1), 3);
        $this->pdfTitle = $this->diffRound === null ? '棚卸差異リスト' : "{$this->diffRound}回目棚卸差異リスト";
        $this->emptyMessage = $this->diffRound === null ? '差異データなし' : "{$this->diffRound}回目差異データなし";
        $this->uncountedRound = null;

        return $this->generatePdf($inventoryCount);
    }

    public function generateUncounted(WmsInventoryCount $inventoryCount, int $round): string
    {
        $this->diffRound = null;
        $this->uncountedRound = min(max($round, 1), 3);
        $this->pdfTitle = "{$this->uncountedRound}回目未カウントリスト";
        $this->emptyMessage = "{$this->uncountedRound}回目未カウントデータなし";

        return $this->generatePdf($inventoryCount);
    }

    /**
     * @return Collection<int, WmsInventoryCountItem>
     */
    public function diffItemsForRound(WmsInventoryCount $inventoryCount, int $round): Collection
    {
        $this->diffRound = min(max($round, 1), 3);
        $this->uncountedRound = null;

        return $this->queryItems($inventoryCount);
    }

    /**
     * @param  Collection<int, WmsInventoryCount>  $inventoryCounts
     */
    public function generateUncountedForCounts(Collection $inventoryCounts, int $round): string
    {
        $inventoryCounts = $inventoryCounts
            ->filter(fn ($inventoryCount): bool => $inventoryCount instanceof WmsInventoryCount && $inventoryCount->exists)
            ->values();
        $round = min(max($round, 1), 3);

        $this->diffRound = null;
        $this->pdfTitle = "{$round}回目未カウントリスト";
        $this->emptyMessage = "{$round}回目未カウントデータなし";
        $this->uncountedRound = $round;

        return $this->generatePdfForItems(
            $this->queryMultiCountUncountedItems($inventoryCounts, $round),
            $this->buildMultiHeader($inventoryCounts),
        );
    }

    private function generatePdf(WmsInventoryCount $inventoryCount): string
    {
        return $this->generatePdfForItems(
            $this->queryItems($inventoryCount),
            $this->buildHeader($inventoryCount),
        );
    }

    /**
     * @param  Collection<int, WmsInventoryCountItem>  $items
     * @param  array{count_date: string, warehouse_id: mixed, warehouse_code: string, warehouse_name: string}  $header
     */
    private function generatePdfForItems(Collection $items, array $header): string
    {
        $this->initPdf();
        $janCodes = $items->isEmpty()
            ? []
            : (new InventoryJanCodeResolver)->forItems($items);

        $currentPageGroupKey = null;
        $isFirstPage = true;

        if ($items->isEmpty()) {
            $this->addNewPage($header, $this->emptyPageGroupTitle($header));
            $this->pdf->SetFont('kozgopromedium', '', 12);
            $this->pdf->SetXY(self::MARGIN_LEFT, $this->currentY);
            $this->pdf->Cell(self::CONTENT_WIDTH, 10, $this->emptyMessage, 0, 0, 'C');
        } else {
            foreach ($items as $item) {
                $pageGroupKey = $this->pageGroupKey($item, $header);
                $pageGroupTitle = $this->pageGroupTitle($item, $header);

                if ($isFirstPage || $currentPageGroupKey !== $pageGroupKey) {
                    $this->addNewPage($header, $pageGroupTitle);
                    $currentPageGroupKey = $pageGroupKey;
                    $isFirstPage = false;
                }

                $blockHeight = self::BLOCK_ROW_HEIGHT * $this->itemBlockRowCount();

                if ($this->currentY + $blockHeight > self::PAGE_HEIGHT - self::MARGIN_BOTTOM) {
                    $this->addNewPage($header, $pageGroupTitle);
                }

                $this->renderItemBlock($item, $janCodes[(int) $item->item_id] ?? '');
            }
        }

        $this->totalPages = $this->pdf->getNumPages();
        $this->renderPageNumbers();

        return $this->pdf->Output('', 'S');
    }

    /**
     * @return Collection<int, WmsInventoryCountItem>
     */
    private function queryItems(WmsInventoryCount $inventoryCount): Collection
    {
        $query = WmsInventoryCountItem::where('inventory_count_id', $inventoryCount->id)
            ->withoutOwnedSetItems()
            ->managedStockItems()
            ->with(['inventoryCount', 'item.item_category1', 'item.item_category2']);

        $this->applyReportTargetCategoryFilter($query);

        if ($this->uncountedRound !== null) {
            $roundColumn = $this->roundColumn($this->uncountedRound);

            $query->whereNull($roundColumn);
            $this->applyUncountedQuantityFilters($query);
        } elseif ($this->diffRound === null) {
            $query->whereRaw($this->systemQuantityExpression().' IS NOT NULL');

            $query->where(function ($query) {
                $query->whereNotNull('final_count_quantity')
                    ->orWhereNotNull('second_count_quantity')
                    ->orWhereNotNull('first_count_quantity');
            });
        }

        $items = $query
            ->orderByRaw("
                CASE
                    WHEN location_id IS NULL
                        OR COALESCE(location_no, '') = ''
                        OR COALESCE(location_code1, '') = ''
                    THEN 1
                    ELSE 0
                END
            ")
            ->orderBy('location_code1')
            ->orderBy('location_code2')
            ->orderBy('location_code3')
            ->orderBy('item_code')
            ->get();

        if ($this->uncountedRound !== null) {
            return $items
                ->sort($this->inventoryItemSorter(...))
                ->values();
        }

        return $items
            ->map(fn (WmsInventoryCountItem $item): WmsInventoryCountItem => $this->attachDiffListValues($item))
            ->filter(fn (WmsInventoryCountItem $item): bool => $this->hasPrintableDifference($item))
            ->sort($this->inventoryItemSorter(...))
            ->values();
    }

    /**
     * @param  Collection<int, WmsInventoryCount>  $inventoryCounts
     * @return Collection<int, WmsInventoryCountItem>
     */
    private function queryMultiCountUncountedItems(Collection $inventoryCounts, int $round): Collection
    {
        $inventoryCountIds = $inventoryCounts
            ->pluck('id')
            ->filter()
            ->unique()
            ->values();

        if ($inventoryCountIds->isEmpty()) {
            return collect();
        }

        $roundColumn = $this->roundColumn($round);

        $query = WmsInventoryCountItem::with(['inventoryCount', 'item.item_category1', 'item.item_category2'])
            ->whereIn('inventory_count_id', $inventoryCountIds)
            ->withoutOwnedSetItems()
            ->managedStockItems();

        $this->applyReportTargetCategoryFilter($query);
        $this->applyUncountedQuantityFilters($query);

        return $query
            ->get()
            ->groupBy(fn (WmsInventoryCountItem $item): string => $this->inventoryItemKey($item))
            ->filter(fn (Collection $items): bool => $items->every(
                fn (WmsInventoryCountItem $item): bool => $item->{$roundColumn} === null,
            ))
            ->map(fn (Collection $items): WmsInventoryCountItem => $this->latestRepresentativeItem($items))
            ->values()
            ->sort($this->inventoryItemSorter(...))
            ->values();
    }

    private function applyReportTargetCategoryFilter(Builder $query): void
    {
        $query->whereHas('item.item_category1', function (Builder $query): void {
            $query->whereIn('code', self::UNCOUNTED_TARGET_MAJOR_CATEGORY_CODES);
        });
    }

    private function applyUncountedQuantityFilters(Builder $query): void
    {
        $systemQuantityExpression = $this->systemQuantityExpression();

        $query->where(function (Builder $query) use ($systemQuantityExpression): void {
            $query
                ->whereRaw("{$systemQuantityExpression} != 0")
                ->orWhere(function (Builder $query): void {
                    $query
                        ->whereNotNull('difference_quantity')
                        ->where('difference_quantity', '!=', 0);
                });
        });
    }

    private function inventoryItemKey(WmsInventoryCountItem $item): string
    {
        if ($item->real_stock_id !== null) {
            return 'real_stock:'.$item->real_stock_id;
        }

        return implode('|', [
            'item',
            $item->item_id ?? '',
            $item->lot_id ?? '',
            $item->lot_no ?? '',
            $item->expiration_date?->format('Y-m-d') ?? '',
            $item->location_id ?? '',
            $item->location_code1 ?? '',
            $item->location_code2 ?? '',
            $item->location_code3 ?? '',
            $item->location_no ?? '',
        ]);
    }

    /**
     * @param  Collection<int, WmsInventoryCountItem>  $items
     */
    private function latestRepresentativeItem(Collection $items): WmsInventoryCountItem
    {
        return $items
            ->sort(fn (WmsInventoryCountItem $a, WmsInventoryCountItem $b): int => $this->representativeSortValues($a) <=> $this->representativeSortValues($b))
            ->last();
    }

    /**
     * @return array<int, int>
     */
    private function representativeSortValues(WmsInventoryCountItem $item): array
    {
        return [
            $item->inventoryCount?->count_date?->getTimestamp() ?? 0,
            (int) $item->inventory_count_id,
            (int) $item->id,
        ];
    }

    private function inventoryItemSorter(WmsInventoryCountItem $a, WmsInventoryCountItem $b): int
    {
        return $this->inventoryItemSortValues($a) <=> $this->inventoryItemSortValues($b);
    }

    /**
     * @return array<int, int|string>
     */
    private function inventoryItemSortValues(WmsInventoryCountItem $item): array
    {
        $locationMissing = $item->location_id === null
            || trim((string) ($item->location_no ?? '')) === ''
            || trim((string) ($item->location_code1 ?? '')) === '' ? 1 : 0;

        if ($this->isWarehouse91Item($item)) {
            return [
                ...$this->warehouseSortValues($item),
                $locationMissing,
                $this->shelfPagePrefix($item) ?? '',
                (string) ($item->location_code1 ?? ''),
                (string) ($item->location_code2 ?? ''),
                (string) ($item->location_code3 ?? ''),
                (string) ($item->item_code ?? ''),
                (int) $item->inventory_count_id,
                (int) $item->id,
            ];
        }

        return [
            ...$this->warehouseSortValues($item),
            ...$this->middleCategorySortValues($item),
            $item->location_id === null
                || trim((string) ($item->location_no ?? '')) === ''
                || trim((string) ($item->location_code1 ?? '')) === '' ? 1 : 0,
            (string) ($item->location_code1 ?? ''),
            (string) ($item->location_code2 ?? ''),
            (string) ($item->location_code3 ?? ''),
            (string) ($item->item_code ?? ''),
            (int) $item->inventory_count_id,
            (int) $item->id,
        ];
    }

    private function attachDiffListValues(WmsInventoryCountItem $item): WmsInventoryCountItem
    {
        $actualQty = $this->actualQuantity($item);
        $actualRound = $this->actualRound($item);

        if ($actualQty !== null) {
            $item->setAttribute('pdf_actual_quantity', $actualQty);
        }

        $useConfirmedSnapshot = $actualRound !== null && $this->isRoundConfirmed($item, $actualRound);
        $systemQty = $actualRound !== null
            ? ($useConfirmedSnapshot ? $item->confirmedRoundSystemQuantity($actualRound) : null)
            : null;
        $systemQty ??= $item->ending_system_quantity ?? $item->system_quantity;

        if ($systemQty !== null) {
            $item->setAttribute('pdf_system_quantity', $systemQty);
        }

        if ($actualQty !== null && $systemQty !== null) {
            $endDifferenceQuantity = $actualRound !== null
                ? ($useConfirmedSnapshot ? $item->confirmedRoundDifference($actualRound) : null)
                : null;
            $endDifferenceQuantity ??= (float) $actualQty - (float) $systemQty;

            $item->setAttribute('pdf_end_difference_quantity', $endDifferenceQuantity);
        }

        return $item;
    }

    private function hasPrintableDifference(WmsInventoryCountItem $item): bool
    {
        return (float) ($item->getAttribute('pdf_end_difference_quantity') ?? 0) !== 0.0;
    }

    private function actualQuantity(WmsInventoryCountItem $item): mixed
    {
        $actualRound = $this->actualRound($item);

        if ($actualRound === null) {
            return null;
        }

        $physicalQuantity = $this->physicalRoundQuantity($item, $actualRound);
        if ($physicalQuantity !== null) {
            return $physicalQuantity;
        }

        $usesConfirmedSnapshot = $this->isRoundConfirmed($item, $actualRound)
            && $item->confirmedRoundDifference($actualRound) !== null;

        if ($usesConfirmedSnapshot) {
            return $item->roundQuantity($actualRound);
        }

        $roundQuantity = $item->roundQuantity($actualRound);
        if ($roundQuantity !== null) {
            return $roundQuantity;
        }

        if ($this->diffRound !== null && $this->isUncountedTargetItem($item)) {
            return 0;
        }

        return null;
    }

    private function physicalRoundQuantity(WmsInventoryCountItem $item, int $round): ?int
    {
        return match ($round) {
            1 => $item->first_count_quantity,
            2 => $item->second_count_quantity,
            3 => $item->final_count_quantity,
            default => null,
        };
    }

    private function isUncountedTargetItem(WmsInventoryCountItem $item): bool
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

    private function systemQuantityExpression(): string
    {
        return Schema::connection('sakemaru')->hasColumn('wms_inventory_count_items', 'ending_system_quantity')
            ? 'COALESCE(ending_system_quantity, system_quantity)'
            : 'system_quantity';
    }

    private function actualRound(WmsInventoryCountItem $item): ?int
    {
        if ($this->diffRound !== null) {
            return $this->diffRound;
        }

        return match (true) {
            $item->final_count_quantity !== null => 3,
            $item->second_count_quantity !== null => 2,
            $item->first_count_quantity !== null => 1,
            default => null,
        };
    }

    private function isRoundConfirmed(WmsInventoryCountItem $item, int $round): bool
    {
        $inventoryCount = $item->relationLoaded('inventoryCount') ? $item->inventoryCount : null;

        return $inventoryCount?->{$this->roundConfirmedAtColumn($round)} !== null;
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

    private function buildHeader(WmsInventoryCount $inventoryCount): array
    {
        return [
            'count_date' => $inventoryCount->count_date?->format('Y/m/d') ?? '',
            'warehouse_id' => $inventoryCount->warehouse_id,
            'warehouse_code' => $inventoryCount->warehouse_code ?? '',
            'warehouse_name' => $inventoryCount->warehouse_name ?? '',
        ];
    }

    /**
     * @param  Collection<int, WmsInventoryCount>  $inventoryCounts
     * @return array{count_date: string, warehouse_id: mixed, warehouse_code: string, warehouse_name: string}
     */
    private function buildMultiHeader(Collection $inventoryCounts): array
    {
        $dates = $inventoryCounts
            ->pluck('count_date')
            ->filter()
            ->sortBy(fn ($date): string => $date->format('Y-m-d'))
            ->values();

        $warehouseCodes = $inventoryCounts
            ->pluck('warehouse_code')
            ->filter(fn ($value): bool => filled($value))
            ->unique()
            ->values();

        $warehouseNames = $inventoryCounts
            ->pluck('warehouse_name')
            ->filter(fn ($value): bool => filled($value))
            ->unique()
            ->values();

        $warehouseIds = $inventoryCounts
            ->pluck('warehouse_id')
            ->filter(fn ($value): bool => filled($value))
            ->unique()
            ->values();

        return [
            'count_date' => $this->formatMultiDateLabel($dates),
            'warehouse_id' => $warehouseIds->count() === 1 ? $warehouseIds->first() : null,
            'warehouse_code' => $warehouseCodes->count() === 1 ? (string) $warehouseCodes->first() : '複数',
            'warehouse_name' => $warehouseNames->count() === 1 ? (string) $warehouseNames->first() : '複数倉庫',
        ];
    }

    /**
     * @param  Collection<int, mixed>  $dates
     */
    private function formatMultiDateLabel(Collection $dates): string
    {
        if ($dates->isEmpty()) {
            return '';
        }

        $first = $dates->first()?->format('Y/m/d');
        $last = $dates->last()?->format('Y/m/d');

        if ($first === $last) {
            return $first;
        }

        return "{$first}-{$last}";
    }

    /**
     * @param  array{warehouse_id?: mixed, warehouse_code?: string}  $header
     */
    private function emptyPageGroupTitle(array $header): string
    {
        return $this->isWarehouse91Header($header) ? '棚番：' : '中分類：';
    }

    /**
     * @param  array{warehouse_id?: mixed, warehouse_code?: string}  $header
     */
    private function pageGroupKey(WmsInventoryCountItem $item, array $header): string
    {
        $warehouseIdentity = $this->itemWarehouseIdentity($item, $header);

        if ($this->isWarehouse91Item($item, $header)) {
            return $warehouseIdentity.'|shelf:'.($this->shelfPagePrefix($item) ?? '');
        }

        return $warehouseIdentity.'|middle_category:'.$this->middleCategoryKey($item);
    }

    /**
     * @param  array{warehouse_id?: mixed, warehouse_code?: string}  $header
     */
    private function pageGroupTitle(WmsInventoryCountItem $item, array $header): string
    {
        if ($this->isWarehouse91Item($item, $header)) {
            return '棚番：'.($this->shelfPagePrefix($item) ?? '');
        }

        return '中分類：'.$this->middleCategoryName($item);
    }

    /**
     * @param  array{warehouse_id?: mixed, warehouse_code?: string}  $header
     */
    private function itemWarehouseIdentity(WmsInventoryCountItem $item, array $header = []): string
    {
        $inventoryCount = $item->relationLoaded('inventoryCount') ? $item->inventoryCount : null;
        $warehouseId = $inventoryCount?->warehouse_id ?? ($header['warehouse_id'] ?? null);
        $warehouseCode = trim((string) ($inventoryCount?->warehouse_code ?? ($header['warehouse_code'] ?? '')));

        if ($warehouseId !== null && $warehouseId !== '') {
            return 'warehouse_id:'.$warehouseId;
        }

        if ($warehouseCode !== '') {
            return 'warehouse_code:'.$warehouseCode;
        }

        return 'warehouse_unknown';
    }

    /**
     * @return array<int, int|string>
     */
    private function warehouseSortValues(WmsInventoryCountItem $item): array
    {
        $inventoryCount = $item->relationLoaded('inventoryCount') ? $item->inventoryCount : null;

        return [
            (string) ($inventoryCount?->warehouse_code ?? ''),
            (int) ($inventoryCount?->warehouse_id ?? 0),
        ];
    }

    /**
     * @param  array{warehouse_id?: mixed, warehouse_code?: string}  $header
     */
    private function isWarehouse91Item(WmsInventoryCountItem $item, array $header = []): bool
    {
        $inventoryCount = $item->relationLoaded('inventoryCount') ? $item->inventoryCount : null;

        return $this->isWarehouse91(
            $inventoryCount?->warehouse_id ?? ($header['warehouse_id'] ?? null),
            $inventoryCount?->warehouse_code ?? ($header['warehouse_code'] ?? ''),
        );
    }

    /**
     * @param  array{warehouse_id?: mixed, warehouse_code?: string}  $header
     */
    private function isWarehouse91Header(array $header): bool
    {
        return $this->isWarehouse91($header['warehouse_id'] ?? null, $header['warehouse_code'] ?? '');
    }

    private function isWarehouse91(mixed $warehouseId, mixed $warehouseCode): bool
    {
        return trim((string) $warehouseCode) === '91'
            || ($warehouseId !== null && $warehouseId !== '' && (int) $warehouseId === 91);
    }

    private function shelfPagePrefix(WmsInventoryCountItem $item): ?string
    {
        $locationNo = trim((string) ($item->location_no ?? ''));

        if ($locationNo === '') {
            return null;
        }

        return mb_substr($locationNo, 0, 2);
    }

    private function middleCategory(WmsInventoryCountItem $item): ?ItemCategory
    {
        $category = $item->item?->item_category2;

        if ($category === null || (int) ($category->depth ?? 0) !== 2) {
            return null;
        }

        return $category;
    }

    private function middleCategoryKey(WmsInventoryCountItem $item): string
    {
        $category = $this->middleCategory($item);

        return $category?->id !== null
            ? (string) $category->id
            : 'no_middle_category';
    }

    private function middleCategoryName(WmsInventoryCountItem $item): string
    {
        $category = $this->middleCategory($item);

        if ($category === null) {
            return '中分類なし';
        }

        $name = trim((string) ($category->name ?? ''));
        if ($name !== '') {
            return $name;
        }

        $code = trim((string) ($category->code ?? ''));

        return $code !== '' ? $code : '中分類なし';
    }

    /**
     * @return array<int, int|string>
     */
    private function middleCategorySortValues(WmsInventoryCountItem $item): array
    {
        $category = $this->middleCategory($item);

        return [
            $category === null ? 1 : 0,
            (string) ($category?->code ?? ''),
            (int) ($category?->id ?? 0),
        ];
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

    private function initPdf(): void
    {
        $this->pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $this->pdf->SetCreator('Smart WMS');
        $this->pdf->SetAuthor('Smart WMS');
        $this->pdf->SetTitle($this->pdfTitle);
        $this->pdf->SetMargins(self::MARGIN_LEFT, self::MARGIN_TOP, self::MARGIN_RIGHT);
        $this->pdf->SetAutoPageBreak(false);
        $this->pdf->setPrintHeader(false);
        $this->pdf->setPrintFooter(false);
        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_NORMAL);
    }

    private function addNewPage(array $header, ?string $pageGroupTitle): void
    {
        $this->pdf->AddPage();
        $this->currentY = self::MARGIN_TOP;
        $this->renderPageHeader($header, $pageGroupTitle);
        $this->renderColumnHeaders();
    }

    private function renderPageHeader(array $header, ?string $pageGroupTitle): void
    {
        $x = self::MARGIN_LEFT;
        $pageGroupTitle ??= $this->emptyPageGroupTitle($header);
        $isMiddleCategoryTitle = str_starts_with($pageGroupTitle, '中分類：');
        $titleWidth = $isMiddleCategoryTitle ? 100 : 55;

        // Row 1: title + 棚卸日 + print datetime
        $this->pdf->SetFont('kozgopromedium', 'B', $isMiddleCategoryTitle ? 12 : self::FONT_SIZE_TITLE);
        $this->pdf->SetXY($x, $this->currentY);
        $this->pdf->Cell($titleWidth, 10, $this->truncateText($pageGroupTitle, $titleWidth - 2), 0, 0, 'L');

        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_HEADER);
        $this->pdf->SetXY($x + ($isMiddleCategoryTitle ? 104 : 57), $this->currentY + 2);
        $this->pdf->Cell(40, 5, '棚卸日 '.$header['count_date'], 0, 0, 'L');

        $printTimestamp = now()->format('Y/m/d H:i:s');
        $this->pdf->SetXY($x + self::CONTENT_WIDTH - 45, $this->currentY);
        $this->pdf->Cell(45, 5, $printTimestamp, 0, 0, 'R');

        // Row 2: 倉庫コード + 倉庫名称
        $row2Y = $this->currentY + 7;
        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_HEADER);
        $this->pdf->SetXY($x + 57, $row2Y);
        $this->pdf->Cell(35, 5, '倉庫コード '.$header['warehouse_code'], 0, 0, 'L');

        $this->pdf->SetXY($x + 95, $row2Y);
        $this->pdf->Cell(60, 5, '倉庫名称 '.$header['warehouse_name'], 0, 0, 'L');

        $this->currentY = $row2Y + 7;
    }

    private function renderColumnHeaders(): void
    {
        $x = self::MARGIN_LEFT;
        $y = $this->currentY;
        $rowH = self::BLOCK_ROW_HEIGHT;

        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_COL_HEADER);
        $this->pdf->SetLineWidth(self::LINE_WIDTH);

        // Row 1 headers
        $this->pdf->SetXY($x, $y);
        $this->pdf->Cell(self::COL_W_JAN, $rowH * 2, 'JANコード', 0, 0, 'C');

        $row1X = $x + self::COL_W_JAN;
        $this->pdf->SetXY($row1X, $y);
        $this->pdf->Cell(self::COL_W_ITEM, $rowH, 'アイテムコード / アイテム名称', 0, 0, 'L');

        $row1X += self::COL_W_ITEM;
        $this->pdf->SetXY($row1X, $y);
        $this->pdf->Cell(self::COL_W_LOCATION, $rowH, 'ロケ', 0, 0, 'C');

        $row1X += self::COL_W_LOCATION;
        $this->pdf->SetXY($row1X, $y);
        $this->pdf->Cell(self::COL_W_LOT, $rowH, 'ロットNO', 0, 0, 'C');

        $row1X += self::COL_W_LOT;
        $this->pdf->SetXY($row1X, $y);
        $this->pdf->Cell(self::COL_W_EXPIRATION, $rowH, '賞味期限', 0, 0, 'C');

        // Row 2 headers
        $y2 = $y + $rowH;
        $row2X = $x + self::COL_W_JAN;
        $this->pdf->SetXY($row2X, $y2);
        $this->pdf->Cell(self::COL_W_ITEM, $rowH, 'アイテム名称', 0, 0, 'L');

        $row2X += self::COL_W_ITEM;
        $this->pdf->SetXY($row2X, $y2);
        $this->pdf->Cell(self::COL_W_INPUT, $rowH, '入力', 0, 0, 'C');

        $row2X += self::COL_W_INPUT;
        $this->pdf->SetXY($row2X, $y2);
        $this->pdf->Cell(self::COL_W_SYSTEM, $rowH, $this->uncountedRound === null ? '終了理論' : '理論数量', 0, 0, 'C');

        $row2X += self::COL_W_SYSTEM;
        $this->pdf->SetXY($row2X, $y2);
        $this->pdf->Cell(self::COL_W_ACTUAL, $rowH, '実数量', 0, 0, 'C');

        $row2X += self::COL_W_ACTUAL;
        $this->pdf->SetXY($row2X, $y2);
        $this->pdf->Cell(self::COL_W_DIFF, $rowH, $this->uncountedRound === null ? '終了差異' : '差異数量', 0, 0, 'C');

        // Separator line below headers
        $sepY = $y + ($rowH * $this->itemBlockRowCount());
        $this->pdf->Line($x, $sepY, $x + self::CONTENT_WIDTH, $sepY);

        $this->currentY = $sepY + 0.5;
    }

    private function renderCategoryHeader(string $categoryName): void
    {
        $x = self::MARGIN_LEFT;
        $y = $this->currentY;

        $this->pdf->SetLineWidth(self::LINE_WIDTH);
        $this->pdf->SetDrawColor(180, 190, 200);
        $this->pdf->SetFillColor(245, 247, 250);
        $this->pdf->Rect($x, $y, self::CONTENT_WIDTH, self::CATEGORY_HEADER_HEIGHT, 'DF');
        $this->pdf->SetDrawColor(0, 0, 0);

        $this->pdf->SetFont('kozgopromedium', 'B', self::FONT_SIZE_NORMAL);
        $this->pdf->SetXY($x + 2, $y + 0.8);
        $this->pdf->Cell(
            self::CONTENT_WIDTH - 4,
            self::CATEGORY_HEADER_HEIGHT - 1,
            $this->truncateText('中分類：'.$categoryName, self::CONTENT_WIDTH - 4),
            0,
            0,
            'L'
        );

        $this->currentY = $y + self::CATEGORY_HEADER_HEIGHT + 0.5;
    }

    private function renderItemBlock(WmsInventoryCountItem $countItem, string $janCode): void
    {
        $x = self::MARGIN_LEFT;
        $y = $this->currentY;
        $rowH = self::BLOCK_ROW_HEIGHT;

        // Separator line (dashed)
        $this->pdf->SetLineWidth(self::LINE_WIDTH);
        $this->pdf->SetLineStyle(['dash' => '2,1']);
        $this->pdf->Line($x, $y, $x + self::CONTENT_WIDTH, $y);
        $this->pdf->SetLineStyle(['dash' => '']);

        $endDifferenceQuantity = $this->uncountedRound !== null
            ? null
            : $countItem->getAttribute('pdf_end_difference_quantity');

        $this->renderBarcodeCell($x, $y, self::COL_W_JAN, $janCode);
        $contentX = $x + self::COL_W_JAN;

        [$itemNameLine1, $itemNameLine2] = $this->splitItemNameForItemCell(
            (string) ($countItem->item_code ?? ''),
            (string) ($countItem->item_name ?? ''),
        );

        // === Row 1: item_code + item_name | location | lot | expiration ===
        $this->pdf->SetFont('kozgopromedium', 'B', self::FONT_SIZE_NORMAL);
        $this->pdf->SetXY($contentX, $y);
        $itemCode = (string) ($countItem->item_code ?? '');
        $itemCodeWidth = $itemCode === '' ? 0 : $this->pdf->GetStringWidth($itemCode);

        if ($itemCode !== '') {
            $this->pdf->Cell($itemCodeWidth, $rowH, $itemCode, 0, 0, 'L');
        }

        $itemNameX = $contentX + ($itemCode === '' ? 0 : $itemCodeWidth + 2);
        $itemNameWidth = self::COL_W_ITEM - ($itemNameX - $contentX) - 2;

        if ($itemNameLine1 !== '' && $itemNameWidth > 0) {
            $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_NORMAL);
            $this->pdf->SetXY($itemNameX, $y);
            $this->pdf->Cell($itemNameWidth, $rowH, $itemNameLine1, 0, 0, 'L');
        }

        $row1X = $contentX + self::COL_W_ITEM;
        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_NORMAL);
        $this->pdf->SetXY($row1X, $y);
        $this->pdf->Cell(self::COL_W_LOCATION, $rowH, $countItem->location_no ?? '', 0, 0, 'C');

        $row1X += self::COL_W_LOCATION;
        $this->pdf->SetXY($row1X, $y);
        $this->pdf->Cell(self::COL_W_LOT, $rowH, $countItem->lot_no ?? '', 0, 0, 'C');

        $row1X += self::COL_W_LOT;
        $this->pdf->SetXY($row1X, $y);
        $this->pdf->Cell(self::COL_W_EXPIRATION, $rowH, $countItem->expiration_date?->format('Y/m/d') ?? '', 0, 0, 'C');

        // === Row 2: item_name continued | input_count | system_qty | actual_qty | diff_qty ===
        $y2 = $y + $rowH;

        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_NORMAL);
        $this->pdf->SetXY($contentX, $y2);
        $this->pdf->Cell(self::COL_W_ITEM, $rowH, $itemNameLine2, 0, 0, 'L');

        $row2X = $contentX + self::COL_W_ITEM;
        $this->pdf->SetXY($row2X, $y2);
        $this->pdf->Cell(self::COL_W_INPUT, $rowH, (string) ($countItem->input_count ?? 0), 0, 0, 'C');

        $row2X += self::COL_W_INPUT;
        $this->pdf->SetXY($row2X, $y2);
        $systemQty = $this->uncountedRound === null
            ? ($countItem->getAttribute('pdf_system_quantity') ?? $countItem->ending_system_quantity)
            : $countItem->system_quantity;
        $this->pdf->Cell(
            self::COL_W_SYSTEM,
            $rowH,
            $this->uncountedRound === null ? $this->formatOptionalQuantity($systemQty) : $this->formatQuantity($systemQty),
            0,
            0,
            'C'
        );

        $actualQty = $this->uncountedRound !== null
            ? $countItem->{$this->roundColumn($this->uncountedRound)}
            : $countItem->getAttribute('pdf_actual_quantity');
        $row2X += self::COL_W_SYSTEM;
        $this->pdf->SetXY($row2X, $y2);
        $this->pdf->Cell(self::COL_W_ACTUAL, $rowH, $this->formatQuantity($actualQty), 0, 0, 'C');

        $row2X += self::COL_W_ACTUAL;
        $this->pdf->SetXY($row2X, $y2);
        $this->pdf->Cell(self::COL_W_DIFF, $rowH, $this->uncountedRound !== null ? '0' : $this->formatOptionalQuantity($endDifferenceQuantity), 0, 0, 'C');

        $this->currentY = $y + ($rowH * $this->itemBlockRowCount());
    }

    private function renderBarcodeCell(float $x, float $y, float $width, string $janCode): void
    {
        if ($janCode === '') {
            return;
        }

        $barcodeW = min(self::BARCODE_WIDTH, $width - 4);
        $barcodeX = $x + (($width - $barcodeW) / 2);
        $barcodeY = $y + 1;

        $this->pdf->write1DBarcode(
            $janCode,
            'C128',
            $barcodeX,
            $barcodeY,
            $barcodeW,
            self::BARCODE_HEIGHT,
            0.35,
            ['position' => '', 'border' => false, 'padding' => 0, 'fgcolor' => [0, 0, 0], 'bgcolor' => false, 'text' => false, 'font' => 'kozgopromedium', 'fontsize' => 0, 'stretchtext' => 0],
            'N'
        );

        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_JAN);
        $this->pdf->SetXY($x, $barcodeY + self::BARCODE_HEIGHT + 0.8);
        $this->pdf->Cell($width, self::BARCODE_TEXT_HEIGHT, $this->truncateText($janCode, $width - 2), 0, 0, 'C');
    }

    private function itemBlockRowCount(): int
    {
        return 2;
    }

    private function renderPageNumbers(): void
    {
        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_HEADER);

        for ($i = 1; $i <= $this->totalPages; $i++) {
            $this->pdf->setPage($i);
            $pageText = "{$i} ／ {$this->totalPages}";
            $textWidth = $this->pdf->GetStringWidth($pageText);
            $x = self::PAGE_WIDTH - self::MARGIN_RIGHT - $textWidth;
            $y = self::MARGIN_TOP + 7;
            $this->pdf->SetXY($x, $y);
            $this->pdf->Cell($textWidth, 5, $pageText, 0, 0, 'R');
        }
    }

    private function formatQuantity(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        $floatVal = (float) $value;

        if ($floatVal == (int) $floatVal) {
            return number_format((int) $floatVal);
        }

        return number_format($floatVal, 3);
    }

    private function formatOptionalQuantity(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return $this->formatQuantity($value);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitItemNameForItemCell(string $itemCode, string $itemName): array
    {
        $itemName = trim($itemName);

        if ($itemName === '') {
            return ['', ''];
        }

        $this->pdf->SetFont('kozgopromedium', 'B', self::FONT_SIZE_NORMAL);
        $itemCodeWidth = $itemCode === '' ? 0 : $this->pdf->GetStringWidth($itemCode) + 2;

        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_NORMAL);
        [$firstLine, $remainingText] = $this->takeTextForWidth(
            $itemName,
            max(0, self::COL_W_ITEM - $itemCodeWidth - 2),
        );

        return [
            $firstLine,
            $this->truncateText($remainingText, self::COL_W_ITEM - 2),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function takeTextForWidth(string $text, float $maxWidthMm): array
    {
        if ($text === '' || $maxWidthMm <= 0) {
            return ['', $text];
        }

        if ($this->pdf->GetStringWidth($text) <= $maxWidthMm) {
            return [$text, ''];
        }

        $chars = mb_str_split($text);
        $result = '';
        $width = 0;

        foreach ($chars as $index => $char) {
            $charWidth = $this->pdf->GetStringWidth($char);
            if ($width + $charWidth > $maxWidthMm) {
                return [$result, implode('', array_slice($chars, $index))];
            }

            $result .= $char;
            $width += $charWidth;
        }

        return [$result, ''];
    }

    private function truncateText(string $text, float $maxWidthMm): string
    {
        if ($text === '') {
            return '';
        }

        $currentWidth = $this->pdf->GetStringWidth($text);
        if ($currentWidth <= $maxWidthMm) {
            return $text;
        }

        $ellipsis = '…';
        $ellipsisWidth = $this->pdf->GetStringWidth($ellipsis);
        $targetWidth = $maxWidthMm - $ellipsisWidth;

        $chars = mb_str_split($text);
        $result = '';
        $width = 0;

        foreach ($chars as $char) {
            $charWidth = $this->pdf->GetStringWidth($char);
            if ($width + $charWidth > $targetWidth) {
                break;
            }
            $result .= $char;
            $width += $charWidth;
        }

        return $result.$ellipsis;
    }
}
