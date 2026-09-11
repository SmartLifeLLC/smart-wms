<?php

namespace App\Services\InventoryCount;

use App\Models\Sakemaru\ItemCategory;
use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
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

class InventoryDiffListWorkbookService
{
    /**
     * @return non-empty-string
     */
    public function generate(WmsInventoryCount $inventoryCount, int $round): string
    {
        $round = min(max($round, 1), 3);
        $items = (new InventoryDiffListPdfService)->diffItemsForRound($inventoryCount, $round);
        $janCodes = $items->isEmpty()
            ? []
            : (new InventoryJanCodeResolver)->forItems($items);

        $spreadsheet = new Spreadsheet;
        $sheetNames = [];
        $groups = $items
            ->groupBy(fn (WmsInventoryCountItem $item): string => $this->majorCategoryKey($item))
            ->sortKeys();

        if ($groups->isEmpty()) {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('差分');
            $this->writeRows($sheet, []);
        } else {
            foreach ($groups->values() as $index => $groupItems) {
                $sheet = $index === 0
                    ? $spreadsheet->getActiveSheet()
                    : $spreadsheet->createSheet();
                $sheet->setTitle($this->uniqueSheetName($this->sheetName($groupItems->first()), $sheetNames));
                $this->writeRows($sheet, $this->rows($inventoryCount, $groupItems->values(), $janCodes));
            }
        }

        $spreadsheet->setActiveSheetIndex(0);

        $tempPath = tempnam(sys_get_temp_dir(), 'wms-inventory-diff-list-');
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
     * @param  Collection<int, WmsInventoryCountItem>  $items
     * @param  array<int, string>  $janCodes
     * @return array<int, array<string, mixed>>
     */
    private function rows(WmsInventoryCount $inventoryCount, Collection $items, array $janCodes): array
    {
        return $items
            ->map(fn (WmsInventoryCountItem $item): array => $this->row($inventoryCount, $item, $janCodes))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $janCodes
     * @return array<string, mixed>
     */
    private function row(WmsInventoryCount $inventoryCount, WmsInventoryCountItem $item, array $janCodes): array
    {
        return [
            '棚卸しNo' => $inventoryCount->count_no ?? '',
            '棚卸日' => $inventoryCount->count_date?->format('Y/m/d') ?? '',
            '倉庫CD' => $inventoryCount->warehouse_code ?? '',
            '倉庫名' => $inventoryCount->warehouse_name ?? '',
            'JANコード' => $janCodes[(int) $item->item_id] ?? '',
            'アイテムコード' => $item->item_code ?? '',
            'アイテム名称' => $item->item_name ?? '',
            '部門CD' => $this->majorCategoryCode($item),
            '部門名' => $this->majorCategoryName($item),
            '中分類CD' => $this->middleCategoryCode($item),
            '中分類名' => $this->middleCategoryName($item),
            '棚番' => $this->shelfPrefix($item),
            'ロケ' => $item->location_no ?? '',
            'ロットNO' => $item->lot_no ?? '',
            '賞味期限' => $item->expiration_date?->format('Y/m/d') ?? '',
            '入力' => $item->input_count ?? 0,
            '終了理論' => $item->getAttribute('pdf_system_quantity'),
            '実数量' => $item->getAttribute('pdf_actual_quantity'),
            '終了差異' => $item->getAttribute('pdf_end_difference_quantity'),
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

        foreach ($rows as $rowIndex => $row) {
            $excelRow = $rowIndex + 2;

            foreach ($columns as $columnIndex => $label) {
                $value = $row[$label] ?? null;
                $excelColumn = $columnIndex + 1;

                if ($value === null || $value === '') {
                    $sheet->setCellValue([$excelColumn, $excelRow], null);
                } elseif ($this->isStringColumn($label)) {
                    $sheet->setCellValueExplicit([$excelColumn, $excelRow], (string) $value, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue([$excelColumn, $excelRow], $value);
                }
            }
        }

        $this->styleSheet($sheet, count($rows));
    }

    /**
     * @return array<int, string>
     */
    private function columns(): array
    {
        return [
            '棚卸しNo',
            '棚卸日',
            '倉庫CD',
            '倉庫名',
            'JANコード',
            'アイテムコード',
            'アイテム名称',
            '部門CD',
            '部門名',
            '中分類CD',
            '中分類名',
            '棚番',
            'ロケ',
            'ロットNO',
            '賞味期限',
            '入力',
            '終了理論',
            '実数量',
            '終了差異',
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
            'A' => 20,
            'B' => 12,
            'C' => 10,
            'D' => 16,
            'E' => 18,
            'F' => 14,
            'G' => 54,
            'H' => 10,
            'I' => 18,
            'J' => 10,
            'K' => 20,
            'L' => 10,
            'M' => 14,
            'N' => 18,
            'O' => 13,
            'P' => 10,
            'Q' => 12,
            'R' => 12,
            'S' => 12,
        ] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        if ($rowCount === 0) {
            return;
        }

        $sheet->getStyle("A2:O{$lastRow}")
            ->getAlignment()
            ->setWrapText(true);
        $sheet->getStyle("P2:S{$lastRow}")
            ->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("P2:R{$lastRow}")
            ->getNumberFormat()
            ->setFormatCode('#,##0;[Red]-#,##0;0');
        $sheet->getStyle("S2:S{$lastRow}")
            ->getNumberFormat()
            ->setFormatCode('+#,##0;[Red]-#,##0;0');
    }

    private function isStringColumn(string $label): bool
    {
        return ! in_array($label, [
            '入力',
            '終了理論',
            '実数量',
            '終了差異',
        ], true);
    }

    private function majorCategoryKey(WmsInventoryCountItem $item): string
    {
        return str_pad($this->majorCategoryCode($item), 8, '0', STR_PAD_LEFT).'|'.$this->majorCategoryName($item);
    }

    private function sheetName(?WmsInventoryCountItem $item): string
    {
        if (! $item instanceof WmsInventoryCountItem) {
            return '差分';
        }

        $code = $this->majorCategoryCode($item);
        $name = $this->majorCategoryName($item);
        $label = trim($code.' '.$name);

        return $label === '' ? '部門なし' : $label;
    }

    /**
     * @param  array<int, string>  $usedNames
     */
    private function uniqueSheetName(string $name, array &$usedNames): string
    {
        $baseName = mb_substr($this->sanitizeSheetName($name), 0, 31);
        $sheetName = $baseName;
        $suffix = 2;

        while (in_array($sheetName, $usedNames, true)) {
            $suffixText = ' '.$suffix;
            $sheetName = mb_substr($baseName, 0, 31 - mb_strlen($suffixText)).$suffixText;
            $suffix++;
        }

        $usedNames[] = $sheetName;

        return $sheetName;
    }

    private function sanitizeSheetName(string $name): string
    {
        $name = trim((string) preg_replace('/[\\\\\\/\\?\\*\\[\\]:]+/', ' ', $name));

        return $name === '' ? '差分' : $name;
    }

    private function shelfPrefix(WmsInventoryCountItem $item): string
    {
        $locationNo = trim((string) ($item->location_no ?? ''));

        return $locationNo === '' ? '' : mb_substr($locationNo, 0, 2);
    }

    private function majorCategory(WmsInventoryCountItem $item): ?ItemCategory
    {
        $category = $item->item?->item_category1;

        if ($category === null || (int) ($category->depth ?? 0) !== 1) {
            return null;
        }

        return $category;
    }

    private function middleCategory(WmsInventoryCountItem $item): ?ItemCategory
    {
        $category = $item->item?->item_category2;

        if ($category === null || (int) ($category->depth ?? 0) !== 2) {
            return null;
        }

        return $category;
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

    private function middleCategoryCode(WmsInventoryCountItem $item): string
    {
        $code = $this->middleCategory($item)?->code;

        return $code === null ? '' : (string) $code;
    }

    private function middleCategoryName(WmsInventoryCountItem $item): string
    {
        $category = $this->middleCategory($item);

        return $category === null ? '' : (string) ($category->name ?? '');
    }
}
