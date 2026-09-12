<?php

namespace App\Services\InventoryCount;

use App\Models\Sakemaru\ItemCategory;
use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

class InventoryDifferenceWorkbookService
{
    private const REPORT_MAJOR_CATEGORY_CODES = ['1001', '1002', '1003', '1006'];

    private const REPORT_MAJOR_CATEGORY_LABELS = [
        '1001' => '１：酒類',
        '1002' => '２：飲料・食品',
        '1003' => '３：ギフト',
        '1006' => '６：アクト商品',
    ];

    /**
     * @return non-empty-string
     */
    public function generate(WmsInventoryCount $inventoryCount, ?int $targetRound = null): string
    {
        $exportRound = $this->exportRound($inventoryCount, $targetRound);
        $items = $this->queryItems($inventoryCount);
        $costPrices = $this->costPricesByItem($items, $inventoryCount);
        $managedItems = $items
            ->filter(fn (WmsInventoryCountItem $item): bool => $this->isManagedStockItem($item))
            ->values();
        $spreadsheet = new Spreadsheet;
        $allRows = $this->allRows($inventoryCount, $managedItems, $costPrices, $exportRound);
        $stockAmountRows = $this->allRows($inventoryCount, $items, $costPrices, $exportRound);
        $diffRows = $this->diffRows($inventoryCount, $managedItems, $costPrices, $exportRound);
        $uncountedRows = $this->uncountedRows($inventoryCount, $managedItems, $costPrices, $exportRound);
        $departmentRows = $this->departmentRows(
            $allRows,
            $this->reportStockAmountsByMajorCategory($items, $costPrices),
        );

        $departmentSheet = $spreadsheet->getActiveSheet();
        $departmentSheet->setTitle('部門別');
        $this->writeDepartmentReport($departmentSheet, $inventoryCount, $departmentRows, $exportRound, false);

        $absoluteDepartmentSheet = $spreadsheet->createSheet();
        $absoluteDepartmentSheet->setTitle('部門別(絶対値)');
        $this->writeDepartmentReport($absoluteDepartmentSheet, $inventoryCount, $departmentRows, $exportRound, true);

        $executiveSheet = $spreadsheet->createSheet();
        $executiveSheet->setTitle('社長用');
        $this->writeExecutiveSummaryReport($executiveSheet, $inventoryCount, $departmentRows, $exportRound);

        $summarySheet = $spreadsheet->createSheet();
        $summarySheet->setTitle('集計');
        $this->writeRows($summarySheet, $this->summaryColumns(), $this->summaryRows($allRows, $diffRows, $uncountedRows, $stockAmountRows));

        $diffSheet = $spreadsheet->createSheet();
        $diffSheet->setTitle('差異');
        $this->writeRows($diffSheet, $this->diffColumns(), $diffRows);

        $uncountedSheet = $spreadsheet->createSheet();
        $uncountedSheet->setTitle('未棚');
        $this->writeRows($uncountedSheet, $this->uncountedColumns(), $uncountedRows);

        foreach ([1 => '１回目', 2 => '２回目', 3 => '３回目'] as $round => $sheetTitle) {
            $topListSheet = $spreadsheet->createSheet();
            $topListSheet->setTitle($sheetTitle);
            $this->writeTopListRows($topListSheet, $this->topListRows($inventoryCount, $diffRows, $round));
        }

        $spreadsheet->setActiveSheetIndex(0);

        $tempPath = tempnam(sys_get_temp_dir(), 'wms-inventory-diff-');
        if ($tempPath === false) {
            throw new RuntimeException('一時ファイルを作成できません。');
        }

        try {
            $writer = new Xlsx($spreadsheet);
            $writer->save($tempPath);

            return (string) file_get_contents($tempPath);
        } finally {
            $spreadsheet->disconnectWorksheets();

            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * @return Collection<int, WmsInventoryCountItem>
     */
    private function queryItems(WmsInventoryCount $inventoryCount): Collection
    {
        return WmsInventoryCountItem::query()
            ->where('inventory_count_id', $inventoryCount->id)
            ->withoutOwnedSetItems()
            ->with(['inventoryCount', 'item.item_category1', 'item.item_category2', 'item.item_category3'])
            ->get()
            ->sort($this->inventoryItemSorter(...))
            ->values();
    }

    /**
     * @param  Collection<int, WmsInventoryCountItem>  $items
     * @param  Collection<int, float>  $costPrices
     * @return array<int, array<string, mixed>>
     */
    private function allRows(WmsInventoryCount $inventoryCount, Collection $items, Collection $costPrices, ?int $exportRound): array
    {
        return $items
            ->map(fn (WmsInventoryCountItem $item): array => $this->buildDiffRow($inventoryCount, $item, $costPrices, $exportRound))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, WmsInventoryCountItem>  $items
     * @param  Collection<int, float>  $costPrices
     * @return array<int, array<string, mixed>>
     */
    private function diffRows(WmsInventoryCount $inventoryCount, Collection $items, Collection $costPrices, ?int $exportRound): array
    {
        return $items
            ->filter(fn (WmsInventoryCountItem $item): bool => $this->baseSystemQuantity($item) !== null)
            ->filter(fn (WmsInventoryCountItem $item): bool => $this->hasRoundDifference($inventoryCount, $item, $exportRound))
            ->map(fn (WmsInventoryCountItem $item): array => $this->buildDiffRow($inventoryCount, $item, $costPrices, $exportRound))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, WmsInventoryCountItem>  $items
     * @param  Collection<int, float>  $costPrices
     * @return array<int, array<string, mixed>>
     */
    private function uncountedRows(WmsInventoryCount $inventoryCount, Collection $items, Collection $costPrices, ?int $exportRound): array
    {
        $uncountedRound = $exportRound;

        if ($uncountedRound === null) {
            return [];
        }

        return $items
            ->filter(fn (WmsInventoryCountItem $item): bool => $this->isUncountedTargetItem($item))
            ->filter(fn (WmsInventoryCountItem $item): bool => $this->physicalRoundQuantity($item, $uncountedRound) === null)
            ->map(fn (WmsInventoryCountItem $item): array => $this->buildUncountedRow($inventoryCount, $item, $costPrices, $exportRound, $uncountedRound))
            ->values()
            ->all();
    }

    private function isUncountedTargetItem(WmsInventoryCountItem $item): bool
    {
        if (! in_array($this->majorCategoryCode($item), self::REPORT_MAJOR_CATEGORY_CODES, true)) {
            return false;
        }

        $systemQuantity = $this->baseSystemQuantity($item);
        $differenceQuantity = $item->difference_quantity;

        return (float) ($systemQuantity ?? 0) !== 0.0
            || ($differenceQuantity !== null && (float) $differenceQuantity !== 0.0);
    }

    /**
     * @return array<int, string>
     */
    private function diffColumns(): array
    {
        return array_merge(
            $this->baseColumns(),
            $this->roundColumns(),
        );
    }

    /**
     * @return array<int, string>
     */
    private function uncountedColumns(): array
    {
        return array_merge(
            ['未入力回'],
            $this->baseColumns(),
            $this->roundColumns(),
        );
    }

    /**
     * @return array<int, string>
     */
    private function baseColumns(): array
    {
        return [
            '棚卸しNo',
            '棚卸日',
            '倉庫CD',
            '倉庫名',
            '商品CD',
            '商品名',
            '大分類CD',
            '大分類名',
            '理論在庫',
            '原価',
            'CP在庫金額',
            '入力回数',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function summaryColumns(): array
    {
        return array_merge(
            ['区分', '件数', 'CP在庫金額'],
            $this->roundAmountColumns(),
        );
    }

    /**
     * @return array<int, string>
     */
    private function roundColumns(): array
    {
        return [
            '1回目数量',
            '1回目±差異',
            '1回目絶対差異',
            '1回目±差異金額',
            '1回目絶対差異金額',
            '2回目数量',
            '2回目±差異',
            '2回目絶対差異',
            '2回目±差異金額',
            '2回目絶対差異金額',
            '3回目数量',
            '3回目±差異',
            '3回目絶対差異',
            '3回目±差異金額',
            '3回目絶対差異金額',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function roundAmountColumns(): array
    {
        return [
            '1回目±差異金額',
            '1回目絶対差異金額',
            '2回目±差異金額',
            '2回目絶対差異金額',
            '3回目±差異金額',
            '3回目絶対差異金額',
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $diffRows
     * @param  array<int, array<string, mixed>>  $uncountedRows
     * @return array<int, array<string, mixed>>
     */
    private function summaryRows(
        array $allRows,
        array $diffRows,
        array $uncountedRows,
        array $stockAmountRows,
    ): array {
        return [
            $this->summaryRow('全体', $allRows, $this->sumRows($stockAmountRows, 'CP在庫金額')),
            $this->summaryRow('差異あり', $diffRows),
            $this->summaryRow('未棚', $uncountedRows),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summaryRow(string $label, array $rows, float|int|null $stockAmountOverride = null): array
    {
        $row = [
            '区分' => $label,
            '件数' => count($rows),
            'CP在庫金額' => $stockAmountOverride ?? $this->sumRows($rows, 'CP在庫金額'),
        ];

        foreach ($this->roundAmountColumns() as $column) {
            $row[$column] = $this->sumRows($rows, $column);
        }

        return $row;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function departmentRows(array $rows, array $stockAmountsByMajorCategory = []): array
    {
        $targetRows = collect($rows)
            ->filter(fn (array $row): bool => in_array(trim((string) ($row['大分類CD'] ?? '')), self::REPORT_MAJOR_CATEGORY_CODES, true))
            ->values();
        $groupedRows = $targetRows
            ->groupBy(fn (array $row): string => trim((string) ($row['大分類CD'] ?? '')))
            ->all();

        $departmentRows = collect(self::REPORT_MAJOR_CATEGORY_CODES)
            ->map(fn (string $code): array => $this->departmentRow(
                ($groupedRows[$code] ?? collect())->all(),
                $code,
                self::REPORT_MAJOR_CATEGORY_LABELS[$code],
                $stockAmountsByMajorCategory[$code] ?? null,
            ))
            ->values()
            ->all();

        $departmentRows[] = $this->departmentRow(
            $targetRows->all(),
            '合計',
            '合計',
            collect(self::REPORT_MAJOR_CATEGORY_CODES)
                ->sum(fn (string $code): float => (float) ($stockAmountsByMajorCategory[$code] ?? 0)),
        );

        return $departmentRows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function departmentRow(array $rows, ?string $departmentCode = null, ?string $departmentName = null, float|int|null $stockAmountOverride = null): array
    {
        $stockAmount = $stockAmountOverride ?? $this->sumRows($rows, 'CP在庫金額') ?? 0;
        $totalCount = count($rows);
        $row = [
            '部門CD' => $departmentCode ?? (string) ($rows[0]['大分類CD'] ?? ''),
            '部門名' => $departmentName ?? (string) ($rows[0]['大分類名'] ?? '分類なし'),
            '総数' => $totalCount,
            'CP在庫金額' => $stockAmount,
        ];

        foreach ([1, 2, 3] as $round) {
            $differenceColumn = "{$round}回目±差異";
            $signedAmountColumn = "{$round}回目±差異金額";
            $absoluteAmountColumn = "{$round}回目絶対差異金額";
            $signedAmount = $this->sumRows($rows, $signedAmountColumn);
            $absoluteAmount = $this->sumRows($rows, $absoluteAmountColumn);
            $differenceCount = collect($rows)
                ->filter(fn (array $row): bool => ($row[$differenceColumn] ?? null) !== null && (int) $row[$differenceColumn] !== 0)
                ->count();

            $row["{$round}回目差異数"] = $differenceCount;
            $row["{$round}回目差異率"] = $totalCount === 0 ? 0 : $differenceCount / $totalCount;
            $row["{$round}回目±不明差異金額"] = $signedAmount;
            $row["{$round}回目±在庫差異率"] = $this->amountRate($signedAmount, $stockAmount);
            $row["{$round}回目絶対値不明差異金額"] = $absoluteAmount;
            $row["{$round}回目絶対値在庫差異率"] = $this->amountRate($absoluteAmount, $stockAmount);
        }

        return $row;
    }

    private function amountRate(float|int|null $amount, float|int|null $stockAmount): ?float
    {
        if ($amount === null) {
            return null;
        }

        if ((float) $stockAmount === 0.0) {
            return 0.0;
        }

        return (float) $amount / (float) $stockAmount;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function sumRows(array $rows, string $column): float|int|null
    {
        $values = collect($rows)
            ->pluck($column)
            ->filter(fn ($value): bool => $value !== null && $value !== '');

        if ($values->isEmpty()) {
            return null;
        }

        return $values->sum(fn ($value): float => (float) $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDiffRow(WmsInventoryCount $inventoryCount, WmsInventoryCountItem $item, Collection $costPrices, ?int $exportRound): array
    {
        $costPrice = $this->costPrice($item, $costPrices);
        $row = $this->baseRow($inventoryCount, $item, $costPrices, $costPrice);

        foreach ([1, 2, 3] as $round) {
            $values = $this->roundValues($inventoryCount, $item, $round, $exportRound, $costPrice);

            $row["{$round}回目数量"] = $values['quantity'];
            $row["{$round}回目±差異"] = $values['difference'];
            $row["{$round}回目絶対差異"] = $values['absolute_difference'];
            $row["{$round}回目±差異金額"] = $values['difference_amount'];
            $row["{$round}回目絶対差異金額"] = $values['absolute_difference_amount'];

        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildUncountedRow(WmsInventoryCount $inventoryCount, WmsInventoryCountItem $item, Collection $costPrices, ?int $exportRound, ?int $uncountedRound = null): array
    {
        $costPrice = $this->costPrice($item, $costPrices);
        $row = $this->baseRow($inventoryCount, $item, $costPrices, $costPrice);
        $uncountedRounds = [];
        $roundsToCheck = $uncountedRound === null ? [1, 2, 3] : [$uncountedRound];

        foreach ([1, 2, 3] as $round) {
            $values = $this->roundValues($inventoryCount, $item, $round, $exportRound, $costPrice);

            $row["{$round}回目数量"] = $values['quantity'];
            $row["{$round}回目±差異"] = $values['difference'];
            $row["{$round}回目絶対差異"] = $values['absolute_difference'];
            $row["{$round}回目±差異金額"] = $values['difference_amount'];
            $row["{$round}回目絶対差異金額"] = $values['absolute_difference_amount'];

            if (in_array($round, $roundsToCheck, true) && $this->shouldExportRound($round, $exportRound) && $this->physicalRoundQuantity($item, $round) === null) {
                $uncountedRounds[] = "{$round}回目";
            }
        }

        return array_merge([
            '未入力回' => implode(',', $uncountedRounds),
        ], $row);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseRow(WmsInventoryCount $inventoryCount, WmsInventoryCountItem $item, Collection $costPrices, ?float $costPrice = null): array
    {
        $systemQuantity = $this->baseSystemQuantity($item);
        $costPrice ??= $this->costPrice($item, $costPrices);

        return [
            '棚卸しNo' => $inventoryCount->count_no ?? '',
            '棚卸日' => $inventoryCount->count_date?->format('Y/m/d') ?? '',
            '倉庫CD' => $inventoryCount->warehouse_code ?? '',
            '倉庫名' => $inventoryCount->warehouse_name ?? '',
            '商品CD' => $item->item_code ?? '',
            '商品名' => $item->item_name ?? '',
            '大分類CD' => $this->majorCategoryCode($item),
            '大分類名' => $this->majorCategoryName($item),
            '理論在庫' => $systemQuantity,
            '原価' => $costPrice,
            'CP在庫金額' => $systemQuantity !== null && $costPrice !== null ? $systemQuantity * $costPrice : null,
            '入力回数' => $item->input_count ?? 0,
        ];
    }

    private function hasRoundDifference(WmsInventoryCount $inventoryCount, WmsInventoryCountItem $item, ?int $exportRound): bool
    {
        foreach ([1, 2, 3] as $round) {
            $difference = $this->roundValues($inventoryCount, $item, $round, $exportRound)['difference'];

            if ($difference !== null && (int) $difference !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{quantity: ?int, difference: ?int, absolute_difference: ?int, difference_amount: ?float, absolute_difference_amount: ?float}
     */
    private function roundValues(WmsInventoryCount $inventoryCount, WmsInventoryCountItem $item, int $round, ?int $exportRound, ?float $costPrice = null): array
    {
        if (! $this->shouldExportRound($round, $exportRound)) {
            return ['quantity' => null, 'difference' => null, 'absolute_difference' => null, 'difference_amount' => null, 'absolute_difference_amount' => null];
        }

        $physicalQuantity = $this->physicalRoundQuantity($item, $round);
        $quantity = $physicalQuantity ?? $this->roundQuantity($item, $round);
        $usesZeroQuantityForUncounted = false;

        if ($physicalQuantity === null && $this->isUncountedTargetItem($item)) {
            $quantity = 0;
            $usesZeroQuantityForUncounted = true;
        }

        if ($quantity === null) {
            return ['quantity' => null, 'difference' => null, 'absolute_difference' => null, 'difference_amount' => null, 'absolute_difference_amount' => null];
        }

        $systemQuantity = $this->systemQuantityForRound($inventoryCount, $item, $round);

        if ($systemQuantity === null) {
            return ['quantity' => $quantity, 'difference' => null, 'absolute_difference' => null, 'difference_amount' => null, 'absolute_difference_amount' => null];
        }

        $difference = ! $usesZeroQuantityForUncounted && $this->isRoundConfirmed($inventoryCount, $round)
            ? $item->confirmedRoundDifference($round)
            : null;
        $difference ??= $quantity - $systemQuantity;
        $difference = (int) $difference;
        $differenceAmount = $costPrice === null ? null : $difference * $costPrice;

        return [
            'quantity' => $quantity,
            'difference' => $difference,
            'absolute_difference' => abs($difference),
            'difference_amount' => $differenceAmount,
            'absolute_difference_amount' => $differenceAmount === null ? null : abs($differenceAmount),
        ];
    }

    private function roundQuantity(WmsInventoryCountItem $item, int $round): ?int
    {
        return match ($round) {
            1 => $item->first_count_quantity,
            2 => $item->second_count_quantity ?? $item->first_count_quantity,
            3 => $item->final_count_quantity,
            default => null,
        };
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

    private function systemQuantityForRound(WmsInventoryCount $inventoryCount, WmsInventoryCountItem $item, int $round): ?int
    {
        $confirmedSystemQuantity = $this->isRoundConfirmed($inventoryCount, $round)
            ? $item->confirmedRoundSystemQuantity($round)
            : null;

        if ($confirmedSystemQuantity !== null) {
            return (int) $confirmedSystemQuantity;
        }

        return $this->baseSystemQuantity($item);
    }

    private function baseSystemQuantity(WmsInventoryCountItem $item): ?int
    {
        $quantity = $item->ending_system_quantity ?? $item->system_quantity;

        return $quantity === null ? null : (int) $quantity;
    }

    private function costPrice(WmsInventoryCountItem $item, Collection $costPrices): ?float
    {
        if ($item->item_id === null) {
            return null;
        }

        return (float) ($costPrices->get((int) $item->item_id) ?? 0);
    }

    /**
     * @param  Collection<int, WmsInventoryCountItem>  $items
     * @return Collection<int, float>
     */
    private function costPricesByItem(Collection $items, WmsInventoryCount $inventoryCount): Collection
    {
        $itemIds = $items
            ->pluck('item_id')
            ->filter(fn ($itemId): bool => $itemId !== null)
            ->map(fn ($itemId): int => (int) $itemId)
            ->unique()
            ->values();

        if ($itemIds->isEmpty()) {
            return collect();
        }

        $itemsByItemId = $items
            ->filter(fn (WmsInventoryCountItem $item): bool => $item->item_id !== null)
            ->keyBy(fn (WmsInventoryCountItem $item): int => (int) $item->item_id);
        $priceDate = CarbonImmutable::today()->toDateString();
        $costPrices = collect();

        foreach ($itemIds->chunk(1000) as $chunkItemIds) {
            $rankedPrices = DB::connection('sakemaru')
                ->table('item_prices as ip')
                ->join('items as i', function ($join): void {
                    $join
                        ->on('i.id', '=', 'ip.item_id')
                        ->on('i.client_id', '=', 'ip.client_id');
                })
                ->select([
                    'ip.item_id',
                    'ip.cost_unit_price',
                    'ip.sale_unit_price',
                    DB::raw('ROW_NUMBER() OVER (PARTITION BY ip.item_id ORDER BY ip.start_date DESC, ip.id DESC) as price_rank'),
                ])
                ->whereIn('ip.item_id', $chunkItemIds->all())
                ->where('ip.client_id', (int) $inventoryCount->client_id)
                ->where('ip.start_date', '<=', $priceDate);

            DB::connection('sakemaru')
                ->query()
                ->fromSub($rankedPrices, 'ranked_prices')
                ->where('ranked_prices.price_rank', 1)
                ->get(['ranked_prices.item_id', 'ranked_prices.cost_unit_price', 'ranked_prices.sale_unit_price'])
                ->each(function ($price) use ($costPrices, $itemsByItemId): void {
                    $item = $itemsByItemId->get((int) $price->item_id);

                    if ($item instanceof WmsInventoryCountItem && ! $this->isManagedStockItem($item)) {
                        $costPrices->put(
                            (int) $price->item_id,
                            (float) ($price->sale_unit_price ?? 0) * $this->categoryCostRate($item) / 100,
                        );

                        return;
                    }

                    $costPrices->put((int) $price->item_id, (float) ($price->cost_unit_price ?? 0));
                });
        }

        return $costPrices;
    }

    /**
     * 管理品は棚卸終了理論数×現在原価、非管理品は棚卸終了理論数×現在販売価格×分類原価率で評価する。
     *
     * @param  Collection<int, WmsInventoryCountItem>  $items
     * @param  Collection<int, float>  $costPrices
     * @return array<string, float>
     */
    private function reportStockAmountsByMajorCategory(Collection $items, Collection $costPrices): array
    {
        $amounts = array_fill_keys(self::REPORT_MAJOR_CATEGORY_CODES, 0.0);

        foreach ($items as $item) {
            $categoryCode = trim($this->majorCategoryCode($item));

            if (! in_array($categoryCode, self::REPORT_MAJOR_CATEGORY_CODES, true)) {
                continue;
            }

            $quantity = $this->baseSystemQuantity($item);

            if ($quantity === null) {
                continue;
            }

            $amounts[$categoryCode] += $quantity * (float) ($this->costPrice($item, $costPrices) ?? 0);
        }

        return $amounts;
    }

    private function shouldExportRound(int $round, ?int $exportRound): bool
    {
        return $exportRound !== null && $round <= $exportRound;
    }

    private function exportRound(WmsInventoryCount $inventoryCount, ?int $targetRound): ?int
    {
        if ($targetRound === null) {
            return $this->latestConfirmedRound($inventoryCount);
        }

        if (! in_array($targetRound, [1, 2, 3], true)) {
            throw new RuntimeException('差異データの出力回が不正です。');
        }

        return $targetRound;
    }

    private function latestConfirmedRound(WmsInventoryCount $inventoryCount): ?int
    {
        foreach ([3, 2, 1] as $round) {
            if ($this->isRoundConfirmed($inventoryCount, $round)) {
                return $round;
            }
        }

        return null;
    }

    private function isRoundConfirmed(WmsInventoryCount $inventoryCount, int $round): bool
    {
        return $inventoryCount->{$this->roundConfirmedAtColumn($round)} !== null;
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

    private function inventoryItemSorter(WmsInventoryCountItem $a, WmsInventoryCountItem $b): int
    {
        return $this->inventoryItemSortValues($a) <=> $this->inventoryItemSortValues($b);
    }

    /**
     * @return array<int, int|string>
     */
    private function inventoryItemSortValues(WmsInventoryCountItem $item): array
    {
        $inventoryCount = $item->inventoryCount;
        $locationMissing = $item->location_id === null
            || trim((string) ($item->location_no ?? '')) === ''
            || trim((string) ($item->location_code1 ?? '')) === '' ? 1 : 0;

        if ($inventoryCount instanceof WmsInventoryCount && $this->isWarehouse91($inventoryCount)) {
            return [
                $locationMissing,
                $this->shelfPrefix($item) ?? '',
                (string) ($item->location_code1 ?? ''),
                (string) ($item->location_code2 ?? ''),
                (string) ($item->location_code3 ?? ''),
                (string) ($item->item_code ?? ''),
                (int) $item->id,
            ];
        }

        return [
            ...$this->middleCategorySortValues($item),
            $locationMissing,
            (string) ($item->location_code1 ?? ''),
            (string) ($item->location_code2 ?? ''),
            (string) ($item->location_code3 ?? ''),
            (string) ($item->item_code ?? ''),
            (int) $item->id,
        ];
    }

    private function isWarehouse91(WmsInventoryCount $inventoryCount): bool
    {
        return trim((string) $inventoryCount->warehouse_code) === '91'
            || (int) $inventoryCount->warehouse_id === 91;
    }

    private function shelfPrefix(WmsInventoryCountItem $item): ?string
    {
        $locationNo = trim((string) ($item->location_no ?? ''));

        return $locationNo === '' ? null : mb_substr($locationNo, 0, 2);
    }

    private function middleCategory(WmsInventoryCountItem $item): ?ItemCategory
    {
        $category = $item->item?->item_category2;

        if ($category === null || (int) ($category->depth ?? 0) !== 2) {
            return null;
        }

        return $category;
    }

    private function majorCategory(WmsInventoryCountItem $item): ?ItemCategory
    {
        $category = $item->item?->item_category1;

        if ($category === null || (int) ($category->depth ?? 0) !== 1) {
            return null;
        }

        return $category;
    }

    private function isManagedStockItem(WmsInventoryCountItem $item): bool
    {
        $isManagedStock = $item->item?->is_managed_stock;

        if ($isManagedStock === null) {
            return true;
        }

        return (bool) $isManagedStock;
    }

    private function majorCategoryCode(WmsInventoryCountItem $item): string
    {
        $code = $this->majorCategory($item)?->code;

        return $code === null ? '' : (string) $code;
    }

    private function majorCategoryName(WmsInventoryCountItem $item): string
    {
        $category = $this->majorCategory($item);

        return $category === null ? '' : (string) ($category->name ?? '');
    }

    private function categoryCostRate(WmsInventoryCountItem $item): float
    {
        foreach ([$item->item?->item_category3, $item->item?->item_category2, $item->item?->item_category1] as $category) {
            $costRate = $category?->cost_rate;

            if ($costRate !== null && is_numeric($costRate)) {
                return (float) $costRate;
            }
        }

        return 0.0;
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

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeDepartmentReport(Worksheet $sheet, WmsInventoryCount $inventoryCount, array $rows, ?int $latestRound, bool $absolute): void
    {
        $currentLabel = $this->departmentReportCurrentLabel($inventoryCount);
        $currentRound = $latestRound ?? 1;

        $sheet->setCellValue('B1', $this->departmentReportTitle($inventoryCount, $absolute));
        $sheet->setCellValue('R1', now()->format('Y/m/d H:i'));

        foreach ([
            'E2:F2' => '調査(棚卸直後)',
            'G2:H2' => '調査(数えミス調査後)',
            'I2:J2' => '調査（差異調査）',
            'K2:M2' => 'ｱｲﾃﾑ数',
            'N2:O2' => $currentLabel,
            'P2:Q2' => '前回9月調査（最終）',
            'R2:S2' => '棚卸日',
        ] as $range => $label) {
            $sheet->mergeCells($range);
            $sheet->setCellValue(explode(':', $range)[0], $label);
        }

        $headers = [
            'D' => 'CP在庫金額',
            'E' => '不明差異金額',
            'F' => '在庫差異率(%)',
            'G' => '不明差異金額',
            'H' => '在庫差異率(%)',
            'I' => '不明差異金額',
            'J' => '在庫差異率(%)',
            'K' => '差異数',
            'L' => '総数',
            'M' => '差異率',
            'N' => '不明差異金額',
            'O' => '在庫差異率(%)',
            'P' => '不明差異金額',
            'Q' => '在庫差異率(%)',
            'R' => '前回',
            'S' => '今回',
        ];

        foreach ($headers as $column => $label) {
            $sheet->setCellValue("{$column}3", $label);
        }

        $countDate = $inventoryCount->count_date?->format('Y/m/d') ?? '';
        $rowIndex = 4;

        foreach ($rows as $index => $row) {
            if ($index === 1) {
                $sheet->setCellValue("B{$rowIndex}", $inventoryCount->warehouse_name ?? '');
            } elseif ($index === 3) {
                $sheet->setCellValue("B{$rowIndex}", filled($inventoryCount->warehouse_code) ? '<'.$inventoryCount->warehouse_code.'>' : '');
            }

            $sheet->setCellValue("C{$rowIndex}", $row['部門名'] ?? '');
            $this->setReportCellValue($sheet, "D{$rowIndex}", $row['CP在庫金額'] ?? null);

            foreach ([1 => ['E', 'F'], 2 => ['G', 'H'], 3 => ['I', 'J']] as $round => [$amountColumn, $rateColumn]) {
                $this->setReportCellValue($sheet, "{$amountColumn}{$rowIndex}", $this->departmentRoundAmount($row, $round, $absolute));
                $this->setReportCellValue($sheet, "{$rateColumn}{$rowIndex}", $this->departmentRoundRate($row, $round, $absolute));
            }

            $this->setReportCellValue($sheet, "K{$rowIndex}", $row["{$currentRound}回目差異数"] ?? null);
            $this->setReportCellValue($sheet, "L{$rowIndex}", $row['総数'] ?? null);
            $this->setReportCellValue($sheet, "M{$rowIndex}", $row["{$currentRound}回目差異率"] ?? null);
            $this->setReportCellValue($sheet, "N{$rowIndex}", $this->departmentRoundAmount($row, $currentRound, $absolute));
            $this->setReportCellValue($sheet, "O{$rowIndex}", $this->departmentRoundRate($row, $currentRound, $absolute));
            $sheet->setCellValue("S{$rowIndex}", $countDate);

            $rowIndex++;
        }

        $this->styleStoreDepartmentReport($sheet, count($rows));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeExecutiveSummaryReport(Worksheet $sheet, WmsInventoryCount $inventoryCount, array $rows, ?int $latestRound): void
    {
        $currentLabel = $this->departmentReportCurrentLabel($inventoryCount);
        $currentRound = $latestRound ?? 1;

        $sheet->setCellValue('A1', $this->departmentReportBaseTitle($inventoryCount));
        $sheet->setCellValue('K1', now()->format('Y/m/d H:i'));

        $this->writeDepartmentTopSection($sheet, $inventoryCount, $rows, $currentLabel, $currentRound);
        $this->styleExecutiveSummaryReport($sheet, count($rows));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeDepartmentTopSection(Worksheet $sheet, WmsInventoryCount $inventoryCount, array $rows, string $currentLabel, int $currentRound): void
    {
        foreach ([
            'E2:H2' => $currentLabel,
            'I2:J2' => '前回9月調査（最終）',
            'K2:L2' => '前回3月調査（最終）',
            'E3:F3' => 'プラスマイナス差異',
            'G3:H3' => '絶対値差異',
        ] as $range => $label) {
            $sheet->mergeCells($range);
            $sheet->setCellValue(explode(':', $range)[0], $label);
        }

        $sheet->setCellValue('I3', 'プラスマイナス差異');
        $sheet->setCellValue('J3', '絶対値差異');
        $sheet->setCellValue('K3', 'プラスマイナス差異');
        $sheet->setCellValue('L3', '絶対値差異');
        $sheet->setCellValue('D4', 'CP在庫金額');
        $sheet->setCellValue('E4', '不明差異金額');
        $sheet->setCellValue('F4', '在庫差異率(%)');
        $sheet->setCellValue('G4', '不明差異金額');
        $sheet->setCellValue('H4', '在庫差異率(%)');
        $sheet->setCellValue('I4', '不明差異金額');
        $sheet->setCellValue('J4', '不明差異金額');
        $sheet->setCellValue('K4', '不明差異金額');
        $sheet->setCellValue('L4', '不明差異金額');

        $countDate = $inventoryCount->count_date?->format('n月j日') ?? '';
        $rowIndex = 5;

        foreach ($rows as $index => $row) {
            if ($index === 0) {
                $sheet->setCellValue("B{$rowIndex}", '棚卸');
            } elseif ($index === 1) {
                $sheet->setCellValue("A{$rowIndex}", $inventoryCount->warehouse_name ?? '');
                $sheet->setCellValue("B{$rowIndex}", '実施日');
                $sheet->setCellValue("C{$rowIndex}", $row['部門名'] ?? '');
            } elseif ($index === 2) {
                $sheet->setCellValue("B{$rowIndex}", $countDate);
                $sheet->setCellValue("C{$rowIndex}", $row['部門名'] ?? '');
            } elseif ($index === 3) {
                $sheet->setCellValue("A{$rowIndex}", filled($inventoryCount->warehouse_code) ? '<'.$inventoryCount->warehouse_code.'>' : '');
                $sheet->setCellValue("C{$rowIndex}", $row['部門名'] ?? '');
            } else {
                $sheet->setCellValue("C{$rowIndex}", $row['部門名'] ?? '');
            }

            if ($index !== 1 && $index !== 2 && $index !== 3) {
                $sheet->setCellValue("C{$rowIndex}", $row['部門名'] ?? '');
            }

            $this->setReportCellValue($sheet, "D{$rowIndex}", $row['CP在庫金額'] ?? null);
            $this->setReportCellValue($sheet, "E{$rowIndex}", $row["{$currentRound}回目±不明差異金額"] ?? null);
            $this->setReportCellValue($sheet, "F{$rowIndex}", $row["{$currentRound}回目±在庫差異率"] ?? null);
            $this->setReportCellValue($sheet, "G{$rowIndex}", $row["{$currentRound}回目絶対値不明差異金額"] ?? null);
            $this->setReportCellValue($sheet, "H{$rowIndex}", $row["{$currentRound}回目絶対値在庫差異率"] ?? null);

            $rowIndex++;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeDepartmentDetailSection(
        Worksheet $sheet,
        WmsInventoryCount $inventoryCount,
        array $rows,
        string $currentLabel,
        int $currentRound,
        int $sectionStartRow,
    ): void {
        $groupRow = $sectionStartRow + 2;
        $headerRow = $sectionStartRow + 3;
        $dataStartRow = $sectionStartRow + 4;

        $sheet->setCellValue("A{$sectionStartRow}", '棚卸差異状況一覧');
        $sheet->setCellValue("S{$sectionStartRow}", now()->format('Y/m/d H:i'));

        foreach ([
            "E{$groupRow}:F{$groupRow}" => '調査(棚卸直後)',
            "G{$groupRow}:H{$groupRow}" => '調査(数えミス調査後)',
            "I{$groupRow}:J{$groupRow}" => '調査（差異調査）',
            "K{$groupRow}:M{$groupRow}" => 'ｱｲﾃﾑ数',
            "N{$groupRow}:O{$groupRow}" => $currentLabel,
            "P{$groupRow}:Q{$groupRow}" => '前回9月調査（最終）',
            "R{$groupRow}:S{$groupRow}" => '棚卸日',
        ] as $range => $label) {
            $sheet->mergeCells($range);
            $sheet->setCellValue(explode(':', $range)[0], $label);
        }

        $headers = [
            'C' => '部門',
            'D' => 'CP在庫金額',
            'E' => '不明差異金額',
            'F' => '在庫差異率(%)',
            'G' => '不明差異金額',
            'H' => '在庫差異率(%)',
            'I' => '不明差異金額',
            'J' => '在庫差異率(%)',
            'K' => '差異数',
            'L' => '総数',
            'M' => '差異率',
            'N' => '不明差異金額',
            'O' => '在庫差異率(%)',
            'P' => '不明差異金額',
            'Q' => '在庫差異率(%)',
            'R' => '前回',
            'S' => '今回',
        ];

        foreach ($headers as $column => $label) {
            $sheet->setCellValue("{$column}{$headerRow}", $label);
        }

        $countDate = $inventoryCount->count_date?->format('Y/m/d') ?? '';
        $rowIndex = $dataStartRow;

        foreach ($rows as $index => $row) {
            if ($index === 0) {
                $sheet->setCellValue("A{$rowIndex}", $inventoryCount->warehouse_name ?? '');
                $sheet->setCellValue("B{$rowIndex}", filled($inventoryCount->warehouse_code) ? '<'.$inventoryCount->warehouse_code.'>' : '');
            }

            $sheet->setCellValue("C{$rowIndex}", $row['部門名'] ?? '');
            $this->setReportCellValue($sheet, "D{$rowIndex}", $row['CP在庫金額'] ?? null);
            $this->setReportCellValue($sheet, "E{$rowIndex}", $row['1回目絶対値不明差異金額'] ?? null);
            $this->setReportCellValue($sheet, "F{$rowIndex}", $row['1回目絶対値在庫差異率'] ?? null);
            $this->setReportCellValue($sheet, "G{$rowIndex}", $row['2回目絶対値不明差異金額'] ?? null);
            $this->setReportCellValue($sheet, "H{$rowIndex}", $row['2回目絶対値在庫差異率'] ?? null);
            $this->setReportCellValue($sheet, "I{$rowIndex}", $row['3回目絶対値不明差異金額'] ?? null);
            $this->setReportCellValue($sheet, "J{$rowIndex}", $row['3回目絶対値在庫差異率'] ?? null);
            $this->setReportCellValue($sheet, "K{$rowIndex}", $row["{$currentRound}回目差異数"] ?? null);
            $this->setReportCellValue($sheet, "L{$rowIndex}", $row['総数'] ?? null);
            $this->setReportCellValue($sheet, "M{$rowIndex}", $row["{$currentRound}回目差異率"] ?? null);
            $this->setReportCellValue($sheet, "N{$rowIndex}", $row["{$currentRound}回目絶対値不明差異金額"] ?? null);
            $this->setReportCellValue($sheet, "O{$rowIndex}", $row["{$currentRound}回目絶対値在庫差異率"] ?? null);
            $sheet->setCellValue("S{$rowIndex}", $countDate);

            $rowIndex++;
        }
    }

    private function setReportCellValue(Worksheet $sheet, string $cell, mixed $value): void
    {
        if ($value === null || $value === '') {
            $sheet->setCellValue($cell, null);

            return;
        }

        $sheet->setCellValue($cell, $value);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function departmentRoundAmount(array $row, int $round, bool $absolute): mixed
    {
        $column = $absolute
            ? "{$round}回目絶対値不明差異金額"
            : "{$round}回目±不明差異金額";

        return $row[$column] ?? null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function departmentRoundRate(array $row, int $round, bool $absolute): mixed
    {
        $column = $absolute
            ? "{$round}回目絶対値在庫差異率"
            : "{$round}回目±在庫差異率";

        return $row[$column] ?? null;
    }

    private function departmentReportTitle(WmsInventoryCount $inventoryCount, bool $absolute): string
    {
        $date = $inventoryCount->count_date ?? $inventoryCount->ending_stock_taken_at ?? CarbonImmutable::today();
        $suffix = $absolute ? '絶対値' : '+　-';

        return $date->format('y').'.'.$date->format('n').'月実施　在庫差異状況一覧＜'.$suffix.'＞';
    }

    private function departmentReportBaseTitle(WmsInventoryCount $inventoryCount): string
    {
        $date = $inventoryCount->count_date ?? $inventoryCount->ending_stock_taken_at ?? CarbonImmutable::today();

        return $date->format('y').'.'.$date->format('n').'月実施　在庫差異状況一覧';
    }

    private function departmentReportCurrentLabel(WmsInventoryCount $inventoryCount): string
    {
        $date = $inventoryCount->ending_stock_taken_at ?? $inventoryCount->count_date;

        return $date === null ? '今回終了時点' : $date->format('n/j').'終了時点';
    }

    private function styleStoreDepartmentReport(Worksheet $sheet, int $rowCount): void
    {
        $lastRow = max(3 + $rowCount, 3);

        $sheet->freezePane('A4');
        $sheet->setAutoFilter("C3:S{$lastRow}");
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setFitToWidth(1)
            ->setFitToHeight(0);

        foreach ([
            'A' => 0.24,
            'B' => 15.86,
            'C' => 15.75,
            'D' => 24.99,
            'E' => 19.49,
            'F' => 19.74,
            'G' => 18.99,
            'H' => 19.49,
            'I' => 19.49,
            'J' => 19.49,
            'K' => 12.86,
            'L' => 12.24,
            'M' => 13.86,
            'N' => 20.49,
            'O' => 19.49,
            'P' => 19.25,
            'Q' => 17.36,
            'R' => 11.12,
            'S' => 11.49,
        ] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $sheet->getRowDimension(1)->setRowHeight(24);
        $sheet->getRowDimension(2)->setRowHeight(22);
        $sheet->getRowDimension(3)->setRowHeight(22);

        $sheet->getStyle("A1:S{$lastRow}")
            ->getFont()
            ->setName('Arial Unicode MS')
            ->setSize(10);
        $sheet->getStyle('B1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('R1:S1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $headerStyle = [
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'D9D9D9'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '555555']],
            ],
        ];

        $sheet->getStyle('C2:S3')->applyFromArray($headerStyle);
        $sheet->getStyle("A4:S{$lastRow}")->applyFromArray([
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '808080']],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        foreach (['P', 'Q'] as $column) {
            $sheet->getStyle("{$column}2:{$column}{$lastRow}")->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()
                ->setRGB('FFFF99');
        }

        foreach (['R', 'S'] as $column) {
            $sheet->getStyle("{$column}2:{$column}{$lastRow}")->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()
                ->setRGB('C999CC');
        }

        if ($rowCount > 0) {
            $sheet->getStyle("A{$lastRow}:O{$lastRow}")->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()
                ->setRGB('BFBFBF');
            $sheet->getStyle("A{$lastRow}:S{$lastRow}")->getFont()->setBold(true);
        }

        foreach (['D', 'E', 'G', 'I', 'N', 'P'] as $column) {
            $sheet->getStyle("{$column}4:{$column}{$lastRow}")
                ->getNumberFormat()
                ->setFormatCode('#,##0');
        }

        foreach (['F', 'H', 'J', 'M', 'O', 'Q'] as $column) {
            $sheet->getStyle("{$column}4:{$column}{$lastRow}")
                ->getNumberFormat()
                ->setFormatCode('0.00%');
        }

        foreach (['K', 'L'] as $column) {
            $sheet->getStyle("{$column}4:{$column}{$lastRow}")
                ->getNumberFormat()
                ->setFormatCode('0');
        }

        $sheet->getStyle("B4:C{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle("D4:S{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("B2:S{$lastRow}")->getAlignment()->setWrapText(true);
    }

    private function styleExecutiveSummaryReport(Worksheet $sheet, int $rowCount): void
    {
        $lastRow = max(4 + $rowCount, 4);

        $sheet->freezePane('A5');
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setFitToWidth(1)
            ->setFitToHeight(0);

        foreach ([
            'A' => 12.24,
            'B' => 8.24,
            'C' => 12.49,
            'D' => 24.99,
            'E' => 12.24,
            'F' => 12.12,
            'G' => 11.86,
            'H' => 12.12,
            'I' => 17.75,
            'J' => 11.86,
            'K' => 17.75,
            'L' => 11.74,
            'M' => 1.25,
            'N' => 8.87,
            'O' => 2.62,
            'P' => 12.86,
            'Q' => 12.99,
            'R' => 13.0,
        ] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $sheet->getStyle("A1:R{$lastRow}")
            ->getFont()
            ->setName('Arial Unicode MS')
            ->setSize(10);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('K1:L1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $headerStyle = [
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'D9D9D9'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '555555']],
            ],
        ];

        $sheet->getStyle('D2:L4')->applyFromArray($headerStyle);
        $sheet->getStyle("A5:L{$lastRow}")->applyFromArray([
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '808080']],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        foreach (['E', 'I', 'K'] as $column) {
            $sheet->getStyle("{$column}4:{$column}{$lastRow}")->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()
                ->setRGB('FFFF99');
        }

        foreach (['G', 'J', 'L'] as $column) {
            $sheet->getStyle("{$column}4:{$column}{$lastRow}")->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()
                ->setRGB('CCFFCC');
        }

        if ($rowCount > 0) {
            $sheet->getStyle("C{$lastRow}:L{$lastRow}")->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()
                ->setRGB('D9D9D9');
            $sheet->getStyle("A{$lastRow}:L{$lastRow}")->getFont()->setBold(true);
        }

        foreach (['D', 'E', 'G', 'I', 'J', 'K', 'L'] as $column) {
            $sheet->getStyle("{$column}5:{$column}{$lastRow}")
                ->getNumberFormat()
                ->setFormatCode('#,##0');
        }

        foreach (['F', 'H'] as $column) {
            $sheet->getStyle("{$column}5:{$column}{$lastRow}")
                ->getNumberFormat()
                ->setFormatCode('0.00%');
        }

        $sheet->getStyle("A5:C{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle("D5:L{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("A2:L{$lastRow}")->getAlignment()->setWrapText(true);
    }

    private function styleDepartmentReport(Worksheet $sheet, int $rowCount, int $detailStartRow): void
    {
        $topLastRow = 5 + $rowCount;
        $detailGroupRow = $detailStartRow + 2;
        $detailHeaderRow = $detailStartRow + 3;
        $detailLastRow = $detailStartRow + 3 + $rowCount;

        $sheet->freezePane('A6');
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setFitToWidth(1)
            ->setFitToHeight(0);

        foreach ([
            'A' => 16,
            'B' => 10,
            'C' => 20,
            'D' => 14,
            'E' => 14,
            'F' => 13,
            'G' => 14,
            'H' => 13,
            'I' => 14,
            'J' => 13,
            'K' => 9,
            'L' => 9,
            'M' => 10,
            'N' => 14,
            'O' => 13,
            'P' => 14,
            'Q' => 13,
            'R' => 11,
            'S' => 11,
        ] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $headerStyle = [
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'D9D9D9'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '555555']],
            ],
        ];

        $sheet->getStyle('A3:I5')->applyFromArray($headerStyle);
        $sheet->getStyle("C{$detailGroupRow}:S{$detailHeaderRow}")->applyFromArray($headerStyle);

        foreach (["A6:I{$topLastRow}", "A{$detailStartRow}:S{$detailLastRow}"] as $range) {
            $sheet->getStyle($range)->applyFromArray([
                'borders' => [
                    'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '808080']],
                ],
                'alignment' => [
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ]);
        }

        $sheet->getStyle("A{$topLastRow}:I{$topLastRow}")->getFont()->setBold(true);
        $sheet->getStyle("C{$detailLastRow}:S{$detailLastRow}")->getFont()->setBold(true);
        $sheet->getStyle("A1:S{$detailLastRow}")->getFont()->setName('Arial Unicode MS')->setSize(10);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle("A{$detailStartRow}")->getFont()->setBold(true)->setSize(12);

        foreach (['B', 'D', 'F', 'G', 'H', 'I'] as $column) {
            $sheet->getStyle("{$column}6:{$column}{$topLastRow}")
                ->getNumberFormat()
                ->setFormatCode('#,##0');
        }

        foreach (['C', 'E'] as $column) {
            $sheet->getStyle("{$column}6:{$column}{$topLastRow}")
                ->getNumberFormat()
                ->setFormatCode('0.00%');
        }

        foreach (['D', 'E', 'G', 'I', 'N', 'P'] as $column) {
            $sheet->getStyle("{$column}".($detailHeaderRow + 1).":{$column}{$detailLastRow}")
                ->getNumberFormat()
                ->setFormatCode('#,##0');
        }

        foreach (['F', 'H', 'J', 'M', 'O', 'Q'] as $column) {
            $sheet->getStyle("{$column}".($detailHeaderRow + 1).":{$column}{$detailLastRow}")
                ->getNumberFormat()
                ->setFormatCode('0.00%');
        }

        foreach (['K', 'L'] as $column) {
            $sheet->getStyle("{$column}".($detailHeaderRow + 1).":{$column}{$detailLastRow}")
                ->getNumberFormat()
                ->setFormatCode('0');
        }

        $sheet->getStyle("A3:S{$detailLastRow}")
            ->getAlignment()
            ->setWrapText(true);
        $sheet->getStyle("A6:A{$topLastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle('C'.($detailHeaderRow + 1).":C{$detailLastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    }

    /**
     * @param  array<int, array<string, mixed>>  $diffRows
     * @return array<int, array<string, mixed>>
     */
    private function topListRows(WmsInventoryCount $inventoryCount, array $diffRows, int $round): array
    {
        $quantityColumn = "{$round}回目±差異";
        $absoluteAmountColumn = "{$round}回目絶対差異金額";
        $signedAmountColumn = "{$round}回目±差異金額";

        return collect($diffRows)
            ->filter(fn (array $row): bool => ($row[$quantityColumn] ?? null) !== null && (int) $row[$quantityColumn] !== 0)
            ->map(fn (array $row): array => [
                '店舗ＣＤ' => $inventoryCount->warehouse_code ?? '',
                '単品ＣＤ' => $row['商品CD'] ?? '',
                '表示正式名称' => $row['商品名'] ?? '',
                '差異数' => (int) ($row[$quantityColumn] ?? 0),
                '絶対値差異' => (float) ($row[$absoluteAmountColumn] ?? 0),
                '＋-差異' => (float) ($row[$signedAmountColumn] ?? 0),
            ])
            ->sort(fn (array $a, array $b): int => [
                -1 * (float) abs($a['絶対値差異'] ?? 0),
                (string) ($a['単品ＣＤ'] ?? ''),
            ] <=> [
                -1 * (float) abs($b['絶対値差異'] ?? 0),
                (string) ($b['単品ＣＤ'] ?? ''),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeTopListRows(Worksheet $sheet, array $rows): void
    {
        $columns = ['店舗ＣＤ', '単品ＣＤ', '表示正式名称', '差異数', '絶対値差異', '＋-差異'];

        foreach ($columns as $index => $label) {
            $sheet->setCellValue([$index + 1, 1], $label);
        }

        $rowIndex = 2;
        foreach ($rows as $row) {
            foreach ($columns as $columnIndex => $label) {
                $excelColumn = $columnIndex + 1;
                $value = $row[$label] ?? null;

                if ($value === null || $value === '') {
                    $sheet->setCellValue([$excelColumn, $rowIndex], null);
                } elseif (in_array($label, ['店舗ＣＤ', '単品ＣＤ', '表示正式名称'], true)) {
                    $sheet->setCellValueExplicit([$excelColumn, $rowIndex], (string) $value, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue([$excelColumn, $rowIndex], $value);
                }
            }

            $rowIndex++;
        }

        $this->styleTopListSheet($sheet, count($rows));
    }

    private function styleTopListSheet(Worksheet $sheet, int $rowCount): void
    {
        $lastRow = max($rowCount + 1, 1);

        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:F{$lastRow}");
        $sheet->getStyle('A1:F1')->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'D9D9D9'],
            ],
        ]);
        $sheet->getStyle("A1:F{$lastRow}")
            ->getAlignment()
            ->setVertical(Alignment::VERTICAL_TOP);

        foreach ([
            'A' => 8,
            'B' => 10,
            'C' => 57,
            'D' => 9,
            'E' => 12,
            'F' => 12,
        ] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        if ($rowCount === 0) {
            return;
        }

        $sheet->getStyle("D2:D{$lastRow}")
            ->getNumberFormat()
            ->setFormatCode('#,##0;(#,##0);0');
        $sheet->getStyle("E2:E{$lastRow}")
            ->getNumberFormat()
            ->setFormatCode('#,##0');
        $sheet->getStyle("F2:F{$lastRow}")
            ->getNumberFormat()
            ->setFormatCode('#,##0;(#,##0);0');
    }

    /**
     * @param  array<int, string>  $columns
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeRows(Worksheet $sheet, array $columns, array $rows): void
    {
        foreach ($columns as $index => $label) {
            $sheet->setCellValue([$index + 1, 1], $label);
        }

        $rowIndex = 2;
        foreach ($rows as $row) {
            foreach ($columns as $columnIndex => $label) {
                $value = $row[$label] ?? null;
                $excelColumn = $columnIndex + 1;

                if ($value === null || $value === '') {
                    $sheet->setCellValue([$excelColumn, $rowIndex], null);
                } elseif ($this->isStringColumn($label)) {
                    $sheet->setCellValueExplicit([$excelColumn, $rowIndex], (string) $value, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue([$excelColumn, $rowIndex], $value);
                }
            }

            $rowIndex++;
        }

        $this->styleSheet($sheet, $columns, count($rows));
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function styleSheet(Worksheet $sheet, array $columns, int $rowCount): void
    {
        $lastColumn = Coordinate::stringFromColumnIndex(count($columns));
        $lastRow = max($rowCount + 1, 1);

        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:{$lastColumn}{$lastRow}");
        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E5EEF8'],
            ],
        ]);
        $sheet->getStyle("A1:{$lastColumn}{$lastRow}")
            ->getAlignment()
            ->setVertical(Alignment::VERTICAL_TOP);

        foreach ($columns as $index => $label) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->getColumnDimension($column)->setWidth($this->columnWidth($label));

            if ($rowCount === 0) {
                continue;
            }

            if (str_contains($label, '±差異金額')) {
                $sheet->getStyle("{$column}2:{$column}{$lastRow}")
                    ->getNumberFormat()
                    ->setFormatCode('+#,##0;-#,##0;0');
            } elseif (str_contains($label, '金額')) {
                $sheet->getStyle("{$column}2:{$column}{$lastRow}")
                    ->getNumberFormat()
                    ->setFormatCode('#,##0');
            } elseif ($this->isPercentColumn($label)) {
                $sheet->getStyle("{$column}2:{$column}{$lastRow}")
                    ->getNumberFormat()
                    ->setFormatCode('0.00%');
            } elseif (str_contains($label, '±差異')) {
                $sheet->getStyle("{$column}2:{$column}{$lastRow}")
                    ->getNumberFormat()
                    ->setFormatCode('+0;-0;0');
            } elseif ($this->isDecimalColumn($label)) {
                $sheet->getStyle("{$column}2:{$column}{$lastRow}")
                    ->getNumberFormat()
                    ->setFormatCode('#,##0.00');
            } elseif ($this->isIntegerColumn($label)) {
                $sheet->getStyle("{$column}2:{$column}{$lastRow}")
                    ->getNumberFormat()
                    ->setFormatCode('0');
            }
        }
    }

    private function isStringColumn(string $label): bool
    {
        return in_array($label, [
            '未入力回',
            '区分',
            '部門CD',
            '部門名',
            '棚卸しNo',
            '棚卸日',
            '倉庫CD',
            '倉庫名',
            '商品CD',
            '商品名',
            '大分類CD',
            '大分類名',
        ], true);
    }

    private function isIntegerColumn(string $label): bool
    {
        return $label === '理論在庫'
            || $label === '件数'
            || $label === '総数'
            || str_contains($label, '差異数')
            || str_contains($label, '金額')
            || $label === '入力回数'
            || str_contains($label, '数量')
            || str_contains($label, '絶対差異');
    }

    private function isPercentColumn(string $label): bool
    {
        return str_contains($label, '差異率');
    }

    private function isDecimalColumn(string $label): bool
    {
        return $label === '原価';
    }

    private function columnWidth(string $label): int
    {
        return match ($label) {
            '商品名' => 46,
            '大分類名' => 20,
            '棚卸しNo' => 24,
            '倉庫名' => 18,
            '未入力回' => 16,
            '区分' => 14,
            '部門名' => 20,
            '原価', 'CP在庫金額' => 14,
            default => str_contains($label, '金額') ? 18 : (str_contains($label, '差異率') ? 14 : (str_contains($label, '絶対差異') ? 13 : 11)),
        };
    }
}
