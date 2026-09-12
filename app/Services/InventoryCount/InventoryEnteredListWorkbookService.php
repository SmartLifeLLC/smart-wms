<?php

namespace App\Services\InventoryCount;

use App\Models\Sakemaru\ItemCategory;
use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use App\Models\WmsInventoryCountItemLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
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

class InventoryEnteredListWorkbookService
{
    private const REPORT_MAJOR_CATEGORY_CODES = [1001, 1002, 1003, 1006];

    /**
     * @return non-empty-string
     */
    public function generate(WmsInventoryCount $inventoryCount, int $round): string
    {
        $round = min(max($round, 1), 3);
        $items = $this->queryItems($inventoryCount, $round);
        $janCodes = $items->isEmpty()
            ? []
            : (new InventoryJanCodeResolver)->forItems($items);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('入力済');
        $this->writeRows($sheet, $this->rows($inventoryCount, $items, $janCodes, $round));
        $spreadsheet->setActiveSheetIndex(0);

        $tempPath = tempnam(sys_get_temp_dir(), 'wms-inventory-entered-');
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
    private function queryItems(WmsInventoryCount $inventoryCount, int $round): Collection
    {
        $roundColumn = $this->roundColumn($round);

        $query = WmsInventoryCountItem::query()
            ->where('inventory_count_id', $inventoryCount->id)
            ->withoutOwnedSetItems()
            ->managedStockItems()
            ->whereNotNull($roundColumn)
            ->whereHas('logs', fn (Builder $query): Builder => $this->normalInputLogQuery($query, $round))
            ->with(['inventoryCount', 'item.item_category1', 'item.item_category2']);

        $this->applyReportTargetCategoryFilter($query);

        return $query
            ->get()
            ->sort($this->inventoryItemSorter(...))
            ->values();
    }

    private function applyReportTargetCategoryFilter(Builder $query): void
    {
        $query->whereHas('item.item_category1', function (Builder $query): void {
            $query->whereIn('code', self::REPORT_MAJOR_CATEGORY_CODES);
        });
    }

    /**
     * @param  Collection<int, WmsInventoryCountItem>  $items
     * @param  array<int, string>  $janCodes
     * @return array<int, array<string, mixed>>
     */
    private function rows(WmsInventoryCount $inventoryCount, Collection $items, array $janCodes, int $round): array
    {
        return $items
            ->map(fn (WmsInventoryCountItem $item): array => $this->row($inventoryCount, $item, $janCodes, $round))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $janCodes
     * @return array<string, mixed>
     */
    private function row(WmsInventoryCount $inventoryCount, WmsInventoryCountItem $item, array $janCodes, int $round): array
    {
        $systemQuantity = $this->systemQuantityForRound($inventoryCount, $item, $round);
        $actualQuantity = $this->physicalRoundQuantity($item, $round);
        $differenceQuantity = null;

        if ($actualQuantity !== null && $systemQuantity !== null) {
            $confirmedDifference = $this->isRoundConfirmed($inventoryCount, $round)
                ? $item->confirmedRoundDifference($round)
                : null;
            $differenceQuantity = (int) ($confirmedDifference ?? ($actualQuantity - $systemQuantity));
        }

        $unitCost = round((float) $item->cost_price, 2);

        return [
            'JANコード' => $janCodes[(int) $item->item_id] ?? '',
            'アイテムコード' => $item->item_code ?? '',
            'アイテム名称' => $item->item_name ?? '',
            'ロケ' => $item->location_no ?? '',
            'ロットNO' => $item->lot_no ?? '',
            '賞味期限' => $item->expiration_date?->format('Y/m/d') ?? '',
            '終了理論' => $systemQuantity,
            '実数量' => $actualQuantity,
            '終了差異' => $differenceQuantity,
            'バラ原価' => $unitCost,
            '理論合計' => $systemQuantity === null ? null : round($systemQuantity * $unitCost, 2),
            '実績合計' => $actualQuantity === null ? null : round($actualQuantity * $unitCost, 2),
            '理論と実績差分合計' => $differenceQuantity === null ? null : round($differenceQuantity * $unitCost, 2),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeRows(Worksheet $sheet, array $rows): void
    {
        $columns = $this->columns();

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

        $this->styleSheet($sheet, count($rows));
    }

    /**
     * @return array<int, string>
     */
    private function columns(): array
    {
        return [
            'JANコード',
            'アイテムコード',
            'アイテム名称',
            'ロケ',
            'ロットNO',
            '賞味期限',
            '終了理論',
            '実数量',
            '終了差異',
            'バラ原価',
            '理論合計',
            '実績合計',
            '理論と実績差分合計',
        ];
    }

    private function styleSheet(Worksheet $sheet, int $rowCount): void
    {
        $lastColumn = Coordinate::stringFromColumnIndex(count($this->columns()));
        $lastRow = max($rowCount + 1, 1);

        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:{$lastColumn}{$lastRow}");
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setFitToWidth(1)
            ->setFitToHeight(0);

        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E5EEF8'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '808080']],
            ],
        ]);
        $sheet->getStyle("A1:{$lastColumn}{$lastRow}")
            ->getAlignment()
            ->setVertical(Alignment::VERTICAL_TOP);

        foreach ([
            'A' => 18,
            'B' => 14,
            'C' => 60,
            'D' => 14,
            'E' => 18,
            'F' => 13,
            'G' => 12,
            'H' => 12,
            'I' => 12,
            'J' => 12,
            'K' => 14,
            'L' => 14,
            'M' => 18,
        ] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        if ($rowCount === 0) {
            return;
        }

        $sheet->getStyle("A2:F{$lastRow}")
            ->getAlignment()
            ->setWrapText(true);
        $sheet->getStyle("G2:M{$lastRow}")
            ->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("G2:H{$lastRow}")
            ->getNumberFormat()
            ->setFormatCode('#,##0;[Red]-#,##0;0');
        $sheet->getStyle("I2:I{$lastRow}")
            ->getNumberFormat()
            ->setFormatCode('+#,##0;-#,##0;0');
        $sheet->getStyle("J2:L{$lastRow}")
            ->getNumberFormat()
            ->setFormatCode('#,##0.00;[Red]-#,##0.00;0.00');
        $sheet->getStyle("M2:M{$lastRow}")
            ->getNumberFormat()
            ->setFormatCode('+#,##0.00;[Red]-#,##0.00;0.00');
    }

    private function isStringColumn(string $label): bool
    {
        return in_array($label, [
            'JANコード',
            'アイテムコード',
            'アイテム名称',
            'ロケ',
            'ロットNO',
            '賞味期限',
        ], true);
    }

    private function normalInputLogQuery(Builder $query, int $round): Builder
    {
        return $query
            ->where('count_round', $round)
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('device_id')
                    ->orWhere('device_id', '!=', WmsInventoryCountItemLog::DEVICE_WEB_AUTO_ZERO);
            });
    }

    private function systemQuantityForRound(WmsInventoryCount $inventoryCount, WmsInventoryCountItem $item, int $round): ?int
    {
        $confirmedSystemQuantity = $this->isRoundConfirmed($inventoryCount, $round)
            ? $item->confirmedRoundSystemQuantity($round)
            : null;

        if ($confirmedSystemQuantity !== null) {
            return (int) $confirmedSystemQuantity;
        }

        $quantity = $item->ending_system_quantity ?? $item->system_quantity;

        return $quantity === null ? null : (int) $quantity;
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

    private function roundColumn(int $round): string
    {
        return match ($round) {
            1 => 'first_count_quantity',
            2 => 'second_count_quantity',
            3 => 'final_count_quantity',
            default => 'first_count_quantity',
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
}
