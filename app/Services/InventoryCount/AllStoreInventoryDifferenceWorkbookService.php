<?php

namespace App\Services\InventoryCount;

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

class AllStoreInventoryDifferenceWorkbookService
{
    private const MAIN_SHEET = '最新';

    private const WORK_SHEET = '作業用シート';

    private const STORE_COLUMN_START = 5;

    private const REPORT_MAJOR_CATEGORY_CODES = ['1001', '1002', '1003', '1006'];

    private const CATEGORY_SHEETS = [
        [
            'title' => '11・12和酒',
            'middle_codes' => ['2011', '2012'],
            'major_codes' => [],
        ],
        [
            'title' => '14ビール',
            'middle_codes' => ['2014'],
            'major_codes' => [],
        ],
        [
            'title' => '15ワイン',
            'middle_codes' => ['2015'],
            'major_codes' => [],
        ],
        [
            'title' => '2・6食品飲料',
            'middle_codes' => [],
            'major_codes' => ['1002', '1006'],
        ],
        [
            'title' => '3ギフト',
            'middle_codes' => [],
            'major_codes' => ['1003'],
        ],
    ];

    /**
     * @param  Collection<int, WmsInventoryCount>  $inventoryCounts
     * @return non-empty-string
     */
    public function generate(Collection $inventoryCounts, ?int $targetRound = null): string
    {
        $targetRound = $this->normalizeTargetRound($targetRound);
        $inventoryCounts = $inventoryCounts
            ->filter(fn (WmsInventoryCount $inventoryCount): bool => $inventoryCount->id !== null)
            ->sort($this->inventoryCountSorter(...))
            ->values();

        if ($inventoryCounts->isEmpty()) {
            throw new RuntimeException('全店差異表の対象棚卸しがありません。');
        }

        $items = $this->queryItems($inventoryCounts);
        $costPrices = $this->costPricesByItem($items, $inventoryCounts);
        $supplierInfo = $this->supplierInfoByItem($items);
        $storeCodes = $inventoryCounts
            ->map(fn (WmsInventoryCount $inventoryCount): string => (string) ($inventoryCount->warehouse_code ?? ''))
            ->unique()
            ->values()
            ->all();
        $rows = $this->buildAllStoreRows($inventoryCounts, $items, $costPrices, $supplierInfo, $storeCodes, $targetRound);

        $spreadsheet = new Spreadsheet;
        $mainSheet = $spreadsheet->getActiveSheet();
        $mainSheet->setTitle(self::MAIN_SHEET);
        $this->writeMainSheet($mainSheet, $rows, $storeCodes, true);

        foreach (self::CATEGORY_SHEETS as $sheetDefinition) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($sheetDefinition['title']);
            $this->writeCategorySheet($sheet, $this->categoryRows($rows, $sheetDefinition, $storeCodes));
        }

        $workSheet = $spreadsheet->createSheet();
        $workSheet->setTitle(self::WORK_SHEET);
        $this->writeWorkSheet($workSheet, $rows, $storeCodes);

        $spreadsheet->setActiveSheetIndex(0);

        $tempPath = tempnam(sys_get_temp_dir(), 'wms-all-store-diff-');
        if ($tempPath === false) {
            throw new RuntimeException('一時ファイルを作成できません。');
        }

        try {
            $writer = new Xlsx($spreadsheet);
            $writer->setPreCalculateFormulas(false);
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
     * @param  Collection<int, WmsInventoryCount>  $inventoryCounts
     * @return Collection<int, WmsInventoryCountItem>
     */
    private function queryItems(Collection $inventoryCounts): Collection
    {
        return WmsInventoryCountItem::query()
            ->whereIn('inventory_count_id', $inventoryCounts->pluck('id')->all())
            ->withoutOwnedSetItems()
            ->managedStockItems()
            ->whereHas('item.item_category1', function ($query): void {
                $query->whereIn('code', self::REPORT_MAJOR_CATEGORY_CODES);
            })
            ->with(['inventoryCount', 'item.item_category1', 'item.item_category2'])
            ->get();
    }

    /**
     * @param  Collection<int, WmsInventoryCount>  $inventoryCounts
     * @param  Collection<int, WmsInventoryCountItem>  $items
     * @param  Collection<int, float>  $costPrices
     * @param  Collection<int, array{supplier_code: string, supplier_name: string}>  $supplierInfo
     * @param  array<int, string>  $storeCodes
     * @return array<int, array<string, mixed>>
     */
    private function buildAllStoreRows(
        Collection $inventoryCounts,
        Collection $items,
        Collection $costPrices,
        Collection $supplierInfo,
        array $storeCodes,
        ?int $targetRound,
    ): array {
        $countsById = $inventoryCounts->keyBy(fn (WmsInventoryCount $inventoryCount): int => (int) $inventoryCount->id);
        $rowsByItem = [];

        foreach ($items as $item) {
            $inventoryCount = $countsById->get((int) $item->inventory_count_id);
            if (! $inventoryCount instanceof WmsInventoryCount) {
                continue;
            }

            $round = $targetRound ?? $this->reportRound($inventoryCount);
            if ($round === null) {
                continue;
            }

            $difference = $targetRound === null
                ? $this->differenceForRound($inventoryCount, $item, $round)
                : $this->differenceForSelectedRound($item, $round);
            if ($difference === null || $difference === 0) {
                continue;
            }

            $key = $this->itemKey($item);
            $costPrice = (float) ($costPrices->get((int) $item->item_id) ?? 0);
            $supplier = $supplierInfo->get((int) $item->item_id, ['supplier_code' => '', 'supplier_name' => '']);

            $rowsByItem[$key] ??= [
                'item_id' => $item->item_id === null ? null : (int) $item->item_id,
                'item_code' => (string) ($item->item_code ?? ''),
                'item_name' => (string) ($item->item_name ?? ''),
                'supplier_code' => $supplier['supplier_code'],
                'supplier_name' => $supplier['supplier_name'],
                'major_category_code' => $this->majorCategoryCode($item),
                'middle_category_code' => $this->middleCategoryCode($item),
                'cost_price' => $costPrice,
                'stores' => array_fill_keys($storeCodes, 0),
            ];

            $storeCode = (string) ($inventoryCount->warehouse_code ?? '');
            $rowsByItem[$key]['stores'][$storeCode] = (int) ($rowsByItem[$key]['stores'][$storeCode] ?? 0) + $difference;
        }

        return collect($rowsByItem)
            ->map(function (array $row) use ($storeCodes): array {
                $row['signed_total'] = collect($storeCodes)->sum(fn (string $storeCode): int => (int) ($row['stores'][$storeCode] ?? 0));
                $row['absolute_total'] = collect($storeCodes)->sum(fn (string $storeCode): int => abs((int) ($row['stores'][$storeCode] ?? 0)));

                return $row;
            })
            ->filter(fn (array $row): bool => (int) $row['absolute_total'] !== 0)
            ->sortBy([
                fn (array $a, array $b): int => (int) $b['absolute_total'] <=> (int) $a['absolute_total'],
                fn (array $a, array $b): int => strnatcmp((string) $a['item_code'], (string) $b['item_code']),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, WmsInventoryCountItem>  $items
     * @param  Collection<int, WmsInventoryCount>  $inventoryCounts
     * @return Collection<int, float>
     */
    private function costPricesByItem(Collection $items, Collection $inventoryCounts): Collection
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

        $clientIds = $inventoryCounts
            ->pluck('client_id')
            ->filter(fn ($clientId): bool => $clientId !== null)
            ->map(fn ($clientId): int => (int) $clientId)
            ->unique()
            ->values()
            ->all();
        $priceDate = CarbonImmutable::today()->toDateString();
        $costPrices = collect();

        foreach ($itemIds->chunk(1000) as $chunkItemIds) {
            $rankedPrices = DB::connection('sakemaru')
                ->table('item_prices as ip')
                ->select([
                    'ip.item_id',
                    'ip.cost_unit_price',
                    DB::raw('ROW_NUMBER() OVER (PARTITION BY ip.item_id ORDER BY ip.start_date DESC, ip.id DESC) as price_rank'),
                ])
                ->whereIn('ip.item_id', $chunkItemIds->all())
                ->where('ip.start_date', '<=', $priceDate)
                ->when($clientIds !== [], fn ($query) => $query->whereIn('ip.client_id', $clientIds));

            DB::connection('sakemaru')
                ->query()
                ->fromSub($rankedPrices, 'ranked_prices')
                ->where('ranked_prices.price_rank', 1)
                ->get(['ranked_prices.item_id', 'ranked_prices.cost_unit_price'])
                ->each(fn ($price) => $costPrices->put((int) $price->item_id, (float) ($price->cost_unit_price ?? 0)));
        }

        return $costPrices;
    }

    /**
     * @param  Collection<int, WmsInventoryCountItem>  $items
     * @return Collection<int, array{supplier_code: string, supplier_name: string}>
     */
    private function supplierInfoByItem(Collection $items): Collection
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

        $supplierInfo = collect();

        foreach ($itemIds->chunk(1000) as $chunkItemIds) {
            $rankedItemContractors = DB::connection('sakemaru')
                ->table('item_contractors as ic')
                ->join('contractors as c', 'c.id', '=', 'ic.contractor_id')
                ->whereIn('ic.item_id', $chunkItemIds->all())
                ->select([
                    'ic.item_id',
                    'c.code as supplier_code',
                    'c.name as supplier_name',
                    DB::raw('ROW_NUMBER() OVER (PARTITION BY ic.item_id ORDER BY ic.id ASC) as supplier_rank'),
                ]);

            DB::connection('sakemaru')
                ->query()
                ->fromSub($rankedItemContractors, 'ranked_item_contractors')
                ->where('ranked_item_contractors.supplier_rank', 1)
                ->get([
                    'ranked_item_contractors.item_id',
                    'ranked_item_contractors.supplier_code',
                    'ranked_item_contractors.supplier_name',
                ])
                ->each(fn ($row) => $supplierInfo->put((int) $row->item_id, [
                    'supplier_code' => $row->supplier_code === null ? '' : (string) $row->supplier_code,
                    'supplier_name' => $row->supplier_name === null ? '' : (string) $row->supplier_name,
                ]));

            DB::connection('sakemaru')
                ->table('items as i')
                ->leftJoin('suppliers as s', 's.id', '=', 'i.supplier_id')
                ->leftJoin('partners as p', 'p.id', '=', 's.partner_id')
                ->whereIn('i.id', $chunkItemIds->all())
                ->get([
                    'i.id as item_id',
                    'p.code as supplier_code',
                    'p.name as supplier_name',
                ])
                ->each(function ($row) use ($supplierInfo): void {
                    if ($supplierInfo->has((int) $row->item_id)) {
                        return;
                    }

                    $supplierInfo->put((int) $row->item_id, [
                        'supplier_code' => $row->supplier_code === null ? '' : (string) $row->supplier_code,
                        'supplier_name' => $row->supplier_name === null ? '' : (string) $row->supplier_name,
                    ]);
                });
        }

        return $supplierInfo;
    }

    private function normalizeTargetRound(?int $targetRound): ?int
    {
        if ($targetRound === null) {
            return null;
        }

        if (! in_array($targetRound, [1, 2, 3], true)) {
            throw new RuntimeException('全店差異表の出力回が不正です。');
        }

        return $targetRound;
    }

    private function reportRound(WmsInventoryCount $inventoryCount): ?int
    {
        foreach ([3, 2, 1] as $round) {
            if ($inventoryCount->{$this->roundConfirmedAtColumn($round)} !== null) {
                return $round;
            }
        }

        $currentRound = (int) ($inventoryCount->current_count_round ?? 0);

        return $currentRound >= 1 && $currentRound <= 3 ? $currentRound : null;
    }

    private function differenceForRound(WmsInventoryCount $inventoryCount, WmsInventoryCountItem $item, int $round): ?int
    {
        $physicalQuantity = $this->physicalRoundQuantity($item, $round);
        $quantity = $physicalQuantity ?? 0;
        $systemQuantity = $this->systemQuantityForRound($inventoryCount, $item, $round);

        if ($systemQuantity === null) {
            return null;
        }

        if ($physicalQuantity !== null && $inventoryCount->{$this->roundConfirmedAtColumn($round)} !== null) {
            $confirmedDifference = $item->confirmedRoundDifference($round);
            if ($confirmedDifference !== null) {
                return (int) $confirmedDifference;
            }
        }

        return (int) $quantity - (int) $systemQuantity;
    }

    private function differenceForSelectedRound(WmsInventoryCountItem $item, int $round): ?int
    {
        $physicalQuantity = $this->physicalRoundQuantity($item, $round);
        if ($physicalQuantity === null) {
            return null;
        }

        $systemQuantity = $this->baseSystemQuantity($item);
        if ($systemQuantity === null) {
            return null;
        }

        return (int) $physicalQuantity - (int) $systemQuantity;
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
        $confirmedSystemQuantity = $inventoryCount->{$this->roundConfirmedAtColumn($round)} !== null
            ? $item->confirmedRoundSystemQuantity($round)
            : null;

        $quantity = $confirmedSystemQuantity ?? $this->baseSystemQuantity($item);

        return $quantity === null ? null : (int) $quantity;
    }

    private function baseSystemQuantity(WmsInventoryCountItem $item): ?int
    {
        $quantity = $item->ending_system_quantity ?? $item->system_quantity;

        return $quantity === null ? null : (int) $quantity;
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

    private function itemKey(WmsInventoryCountItem $item): string
    {
        if ($item->item_id !== null) {
            return 'id:'.(int) $item->item_id;
        }

        return 'code:'.(string) ($item->item_code ?? '');
    }

    private function majorCategoryCode(WmsInventoryCountItem $item): string
    {
        $category = $item->item?->item_category1;

        if ($category === null || (int) ($category->depth ?? 0) !== 1) {
            return '';
        }

        return (string) ($category->code ?? '');
    }

    private function middleCategoryCode(WmsInventoryCountItem $item): string
    {
        $category = $item->item?->item_category2;

        if ($category === null || (int) ($category->depth ?? 0) !== 2) {
            return '';
        }

        return (string) ($category->code ?? '');
    }

    private function inventoryCountSorter(WmsInventoryCount $a, WmsInventoryCount $b): int
    {
        return $this->inventoryCountSortValues($a) <=> $this->inventoryCountSortValues($b);
    }

    /**
     * @return array<int, int|string>
     */
    private function inventoryCountSortValues(WmsInventoryCount $inventoryCount): array
    {
        $code = trim((string) ($inventoryCount->warehouse_code ?? ''));
        $isNumeric = preg_match('/^\d+$/', $code) === 1;

        return [
            $isNumeric ? 0 : 1,
            $isNumeric ? (int) $code : $code,
            $code,
            (int) $inventoryCount->id,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $storeCodes
     */
    private function writeMainSheet(Worksheet $sheet, array $rows, array $storeCodes, bool $sortByAbsoluteTotal): void
    {
        $rowsToWrite = $sortByAbsoluteTotal
            ? $rows
            : collect($rows)->sortBy(fn (array $row): string => (string) $row['item_code'])->values()->all();
        $headers = $this->mainHeaders($storeCodes);

        $this->writeHeaders($sheet, $headers);

        $rowIndex = 2;
        foreach ($rowsToWrite as $row) {
            $this->writeMainRow($sheet, $rowIndex, $row, $storeCodes, true);
            $rowIndex++;
        }

        $this->styleMainSheet($sheet, count($headers), $rowIndex - 1, true);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $storeCodes
     */
    private function writeWorkSheet(Worksheet $sheet, array $rows, array $storeCodes): void
    {
        $headers = $this->workHeaders($storeCodes);
        $rowsToWrite = collect($rows)
            ->sortBy(fn (array $row): string => (string) $row['item_code'])
            ->values()
            ->all();

        $this->writeHeaders($sheet, $headers);

        $rowIndex = 2;
        foreach ($rowsToWrite as $row) {
            $this->writeMainRow($sheet, $rowIndex, $row, $storeCodes, false);
            $rowIndex++;
        }

        $this->styleMainSheet($sheet, count($headers), $rowIndex - 1, false);
    }

    /**
     * @param  array<int, string>  $storeCodes
     * @return array<int, string>
     */
    private function mainHeaders(array $storeCodes): array
    {
        return array_merge(
            ['単品ＣＤ', '表示正式名称', '主仕入先ＣＤ', '仕入先名'],
            $storeCodes,
            ['差異', '絶対値', '差異の全店合計が0の商品（店間でのテレコがないでしょうか）'],
        );
    }

    /**
     * @param  array<int, string>  $storeCodes
     * @return array<int, string>
     */
    private function workHeaders(array $storeCodes): array
    {
        return array_merge(
            ['単品ＣＤ', '表示正式名称', '主仕入先ＣＤ', '仕入先名'],
            $storeCodes,
            ['差異の全店合計が0の商品（店間でのテレコがないでしょうか）'],
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $storeCodes
     */
    private function writeMainRow(Worksheet $sheet, int $rowIndex, array $row, array $storeCodes, bool $withTotals): void
    {
        $sheet->setCellValueExplicit("A{$rowIndex}", (string) $row['item_code'], DataType::TYPE_STRING);
        $sheet->setCellValue("B{$rowIndex}", (string) $row['item_name']);
        $sheet->setCellValueExplicit("C{$rowIndex}", (string) $row['supplier_code'], DataType::TYPE_STRING);
        $sheet->setCellValue("D{$rowIndex}", (string) $row['supplier_name']);

        foreach ($storeCodes as $index => $storeCode) {
            $column = Coordinate::stringFromColumnIndex(self::STORE_COLUMN_START + $index);
            $sheet->setCellValue("{$column}{$rowIndex}", (int) ($row['stores'][$storeCode] ?? 0));
        }

        $firstStoreColumn = Coordinate::stringFromColumnIndex(self::STORE_COLUMN_START);
        $lastStoreColumn = Coordinate::stringFromColumnIndex(self::STORE_COLUMN_START + count($storeCodes) - 1);

        if ($withTotals) {
            $signedTotalColumn = Coordinate::stringFromColumnIndex(self::STORE_COLUMN_START + count($storeCodes));
            $absoluteTotalColumn = Coordinate::stringFromColumnIndex(self::STORE_COLUMN_START + count($storeCodes) + 1);
            $checkColumn = Coordinate::stringFromColumnIndex(self::STORE_COLUMN_START + count($storeCodes) + 2);
            $absoluteFormula = $this->absoluteStoreFormula($rowIndex, count($storeCodes));

            $sheet->setCellValue("{$signedTotalColumn}{$rowIndex}", "=SUM({$firstStoreColumn}{$rowIndex}:{$lastStoreColumn}{$rowIndex})");
            $sheet->setCellValue("{$absoluteTotalColumn}{$rowIndex}", "={$absoluteFormula}");
            $sheet->setCellValue("{$checkColumn}{$rowIndex}", "=IF(AND({$signedTotalColumn}{$rowIndex}=0,{$absoluteTotalColumn}{$rowIndex}>0),\"要確認\",\"\")");

            return;
        }

        $checkColumn = Coordinate::stringFromColumnIndex(self::STORE_COLUMN_START + count($storeCodes));
        $absoluteFormula = $this->absoluteStoreFormula($rowIndex, count($storeCodes));
        $sheet->setCellValue("{$checkColumn}{$rowIndex}", "=IF(AND(SUM({$firstStoreColumn}{$rowIndex}:{$lastStoreColumn}{$rowIndex})=0,{$absoluteFormula}>0),\"要確認\",\"\")");
    }

    private function absoluteStoreFormula(int $rowIndex, int $storeCount): string
    {
        return collect(range(self::STORE_COLUMN_START, self::STORE_COLUMN_START + $storeCount - 1))
            ->map(fn (int $columnIndex): string => 'ABS('.Coordinate::stringFromColumnIndex($columnIndex).$rowIndex.')')
            ->implode('+');
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeCategorySheet(Worksheet $sheet, array $rows): void
    {
        $headers = ['店舗ＣＤ', '単品ＣＤ', '表示正式名称', '主仕入先ＣＤ', '仕入先名', '差異数', '絶対値差異', '＋-差異'];
        $this->writeHeaders($sheet, $headers);

        $rowIndex = 2;
        foreach ($rows as $row) {
            $sheet->setCellValueExplicit("A{$rowIndex}", (string) $row['store_code'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("B{$rowIndex}", (string) $row['item_code'], DataType::TYPE_STRING);
            $sheet->setCellValue("C{$rowIndex}", (string) $row['item_name']);
            $sheet->setCellValueExplicit("D{$rowIndex}", (string) $row['supplier_code'], DataType::TYPE_STRING);
            $sheet->setCellValue("E{$rowIndex}", (string) $row['supplier_name']);
            $sheet->setCellValue("F{$rowIndex}", (int) $row['difference_quantity']);
            $sheet->setCellValue("G{$rowIndex}", (float) $row['absolute_difference_amount']);
            $sheet->setCellValue("H{$rowIndex}", (float) $row['signed_difference_amount']);
            $rowIndex++;
        }

        $this->styleCategorySheet($sheet, $rowIndex - 1);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array{title: string, middle_codes: array<int, string>, major_codes: array<int, string>}  $sheetDefinition
     * @param  array<int, string>  $storeCodes
     * @return array<int, array<string, mixed>>
     */
    private function categoryRows(array $rows, array $sheetDefinition, array $storeCodes): array
    {
        return collect($rows)
            ->filter(fn (array $row): bool => $this->matchesCategorySheet($row, $sheetDefinition))
            ->flatMap(function (array $row) use ($storeCodes): array {
                $categoryRows = [];

                foreach ($storeCodes as $storeCode) {
                    $differenceQuantity = (int) ($row['stores'][$storeCode] ?? 0);
                    if ($differenceQuantity === 0) {
                        continue;
                    }

                    $signedAmount = $differenceQuantity * (float) $row['cost_price'];
                    $categoryRows[] = [
                        'store_code' => $storeCode,
                        'item_code' => $row['item_code'],
                        'item_name' => $row['item_name'],
                        'supplier_code' => $row['supplier_code'],
                        'supplier_name' => $row['supplier_name'],
                        'difference_quantity' => $differenceQuantity,
                        'absolute_difference_amount' => abs($signedAmount),
                        'signed_difference_amount' => $signedAmount,
                    ];
                }

                return $categoryRows;
            })
            ->sortBy([
                fn (array $a, array $b): int => (float) $b['absolute_difference_amount'] <=> (float) $a['absolute_difference_amount'],
                fn (array $a, array $b): int => strnatcmp((string) $a['item_code'], (string) $b['item_code']),
                fn (array $a, array $b): int => strnatcmp((string) $a['store_code'], (string) $b['store_code']),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{middle_codes: array<int, string>, major_codes: array<int, string>}  $sheetDefinition
     */
    private function matchesCategorySheet(array $row, array $sheetDefinition): bool
    {
        $middleCodes = $sheetDefinition['middle_codes'];
        $majorCodes = $sheetDefinition['major_codes'];

        if ($middleCodes !== []) {
            return in_array((string) $row['middle_category_code'], $middleCodes, true);
        }

        return in_array((string) $row['major_category_code'], $majorCodes, true);
    }

    /**
     * @param  array<int, string>  $headers
     */
    private function writeHeaders(Worksheet $sheet, array $headers): void
    {
        foreach ($headers as $index => $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1).'1', $header);
        }
    }

    private function styleMainSheet(Worksheet $sheet, int $lastColumnIndex, int $lastRow, bool $withTotals): void
    {
        $lastColumn = Coordinate::stringFromColumnIndex($lastColumnIndex);
        $lastRow = max($lastRow, 1);

        $sheet->freezePane('E2');
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray($this->headerStyle());
        $sheet->getStyle("A1:{$lastColumn}{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A1:{$lastColumn}{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle("A1:{$lastColumn}1")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        if ($lastRow >= 2) {
            $sheet->getStyle("E2:{$lastColumn}{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');
        }

        $sheet->getColumnDimension('A')->setWidth(12);
        $sheet->getColumnDimension('B')->setWidth(42);
        $sheet->getColumnDimension('C')->setWidth(13);
        $sheet->getColumnDimension('D')->setWidth(28);

        $storeCount = $lastColumnIndex - ($withTotals ? 7 : 5);
        if ($storeCount > 0) {
            foreach (range(self::STORE_COLUMN_START, self::STORE_COLUMN_START + $storeCount - 1) as $columnIndex) {
                $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($columnIndex))->setWidth(7);
            }
        }

        if ($withTotals) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($lastColumnIndex - 2))->setWidth(9);
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($lastColumnIndex - 1))->setWidth(9);
        }

        $sheet->getColumnDimension($lastColumn)->setWidth(34);
    }

    private function styleCategorySheet(Worksheet $sheet, int $lastRow): void
    {
        $lastRow = max($lastRow, 1);

        $sheet->freezePane('A2');
        $sheet->getStyle('A1:H1')->applyFromArray($this->headerStyle());
        $sheet->getStyle("A1:H{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A1:H{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        if ($lastRow >= 2) {
            $sheet->getStyle("F2:H{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');
        }

        $sheet->getColumnDimension('A')->setWidth(10);
        $sheet->getColumnDimension('B')->setWidth(13);
        $sheet->getColumnDimension('C')->setWidth(46);
        $sheet->getColumnDimension('D')->setWidth(13);
        $sheet->getColumnDimension('E')->setWidth(28);
        $sheet->getColumnDimension('F')->setWidth(11);
        $sheet->getColumnDimension('G')->setWidth(13);
        $sheet->getColumnDimension('H')->setWidth(13);
    }

    /**
     * @return array<string, mixed>
     */
    private function headerStyle(): array
    {
        return [
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'D9EAF7'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ];
    }
}
