<?php

namespace App\Services\InventoryCount;

use App\Models\Sakemaru\ItemCategory;
use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use TCPDF;

class InventoryInstructionSheetPdfService
{
    public const ITEM_SCOPE_ALL = 'all';

    public const ITEM_SCOPE_TOP_50 = 'top_50';

    private const TOP_SYSTEM_QUANTITY_LIMIT = 50;

    private const FONT_SIZE_TITLE = 18;

    private const FONT_SIZE_PRINT_TIMESTAMP = 9;

    private const FONT_SIZE_HEADER = 10;

    private const FONT_SIZE_NORMAL = 8;

    private const FONT_SIZE_COL_HEADER = 9;

    private const FONT_SIZE_PRODUCT = 9;

    private const FONT_SIZE_STOCK = 10.5;

    private const FONT_SIZE_JAN = 7;

    private const PRODUCT_LINE_HEIGHT = 4.2;

    private const HEADER_ROW_HEIGHT = 10;

    private const ITEM_ROW_HEIGHT = 19;

    private const LINE_WIDTH = 0.2;

    private const MARGIN_LEFT = 10;

    private const MARGIN_TOP = 8;

    private const MARGIN_RIGHT = 10;

    private const MARGIN_BOTTOM = 12;

    private const PAGE_WIDTH = 210;

    private const PAGE_HEIGHT = 297;

    private const CONTENT_WIDTH = 190;

    private const COL_W_JAN = 38;

    private const COL_W_NAME = 81;

    private const COL_W_CAPACITY = 16;

    private const COL_W_TOTAL_PIECES = 14;

    private const COL_W_TOTAL_TO_ACTUAL_GAP = 17;

    private const COL_W_ACTUAL_QUANTITY = 24;

    private const BARCODE_WIDTH = 34;

    private const BARCODE_HEIGHT = 8;

    private const BARCODE_TEXT_HEIGHT = 3;

    private TCPDF $pdf;

    private float $currentY;

    private int $totalPages = 0;

    public function generate(
        WmsInventoryCount $inventoryCount,
        ?array $categoryIds = null,
        string $itemScope = self::ITEM_SCOPE_ALL,
        bool $excludeDepartmentSystemItems = true,
    ): string {
        $items = $this->queryItems($inventoryCount, $categoryIds, $itemScope, $excludeDepartmentSystemItems);
        $janCodes = (new InventoryJanCodeResolver)->forItems($items);

        $this->initPdf();

        $header = $this->buildHeader($inventoryCount);
        $currentDepartmentKey = null;
        $currentDepartmentName = null;
        $isFirstPage = true;

        foreach ($items as $item) {
            $departmentKey = $this->departmentPageKey($item);
            $departmentName = $this->departmentName($item);

            if ($currentDepartmentKey !== null && $currentDepartmentKey !== $departmentKey) {
                $this->addNewPage($header, $departmentName);
                $isFirstPage = false;
            } elseif ($isFirstPage && $currentDepartmentKey === null) {
                $this->addNewPage($header, $departmentName);
                $isFirstPage = false;
            }

            $currentDepartmentKey = $departmentKey;
            $currentDepartmentName = $departmentName;

            $blockHeight = $this->itemRowHeight($item);

            if ($this->currentY + $blockHeight > self::PAGE_HEIGHT - self::MARGIN_BOTTOM) {
                $this->addNewPage($header, $currentDepartmentName);
            }

            $this->renderItemBlock($item, $janCodes[(int) $item->item_id] ?? '', $blockHeight);
        }

        if ($this->pdf->getNumPages() === 0) {
            $this->addNewPage($header, null);
            $this->pdf->SetFont('kozgopromedium', '', 12);
            $this->pdf->SetXY(self::MARGIN_LEFT, $this->currentY);
            $this->pdf->Cell(self::CONTENT_WIDTH, 10, '対象データなし', 0, 0, 'C');
        }

        $this->totalPages = $this->pdf->getNumPages();
        $this->renderPageNumbers();

        return $this->pdf->Output('', 'S');
    }

    public function getCategoryOptions(WmsInventoryCount $inventoryCount): array
    {
        $itemIds = WmsInventoryCountItem::where('inventory_count_id', $inventoryCount->id)
            ->withoutOwnedSetItems()
            ->whereNotNull('item_id')
            ->distinct()
            ->pluck('item_id');

        if ($itemIds->isEmpty()) {
            return [];
        }

        return ItemCategory::query()
            ->where('is_active', true)
            ->whereExists(function ($query) use ($itemIds) {
                $query->selectRaw('1')
                    ->from('items')
                    ->whereColumn('items.item_category2_id', 'item_categories.id')
                    ->whereIn('items.id', $itemIds);
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(fn (ItemCategory $cat) => [$cat->id => "[{$cat->code}]{$cat->name}"])
            ->toArray();
    }

    public static function itemScopeOptions(): array
    {
        return [
            self::ITEM_SCOPE_ALL => '全件',
        ];
    }

    private function queryItems(
        WmsInventoryCount $inventoryCount,
        ?array $categoryIds = null,
        string $itemScope = self::ITEM_SCOPE_ALL,
        bool $excludeDepartmentSystemItems = true,
    ): Collection {
        $query = WmsInventoryCountItem::where('inventory_count_id', $inventoryCount->id)
            ->withoutOwnedSetItems()
            ->with(['item.item_category2']);

        if ($categoryIds !== null && $categoryIds !== []) {
            $query->whereHas('item', function ($q) use ($categoryIds) {
                $q->whereIn('item_category2_id', $categoryIds);
            });
        }

        if ($excludeDepartmentSystemItems) {
            $this->excludeDepartmentSystemItems($query);
        }

        if ($this->normalizeItemScope($itemScope) === self::ITEM_SCOPE_TOP_50) {
            $topItemIds = (clone $query)
                ->reorder()
                ->orderByDesc('system_quantity')
                ->orderBy('id')
                ->limit(self::TOP_SYSTEM_QUANTITY_LIMIT)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($topItemIds === []) {
                return $query->whereRaw('1 = 0')->get();
            }

            $query->whereKey($topItemIds);
        }

        return $this->applyInstructionOrder($query)->get();
    }

    private function excludeDepartmentSystemItems(Builder $query): void
    {
        $query
            ->where(function (Builder $rowQuery) {
                $rowQuery
                    ->whereNull('item_name')
                    ->orWhere('item_name', 'not like', '%部システム%');
            })
            ->whereDoesntHave('item', function (Builder $itemQuery) {
                $itemQuery
                    ->where('name', 'like', '%部システム%')
                    ->orWhereHas('item_category3', function (Builder $categoryQuery) {
                        $categoryQuery->where('name', 'like', '%部システム%');
                    });
            });
    }

    private function applyInstructionOrder(Builder $query): Builder
    {
        $table = (new WmsInventoryCountItem)->getTable();

        return $query
            ->select("{$table}.*")
            ->leftJoin('items as instruction_items', 'instruction_items.id', '=', "{$table}.item_id")
            ->leftJoin('item_categories as instruction_item_categories', 'instruction_item_categories.id', '=', 'instruction_items.item_category2_id')
            ->orderByRaw("
                CASE
                    WHEN instruction_item_categories.id IS NULL
                        OR COALESCE(instruction_item_categories.code, '') = ''
                    THEN 1
                    ELSE 0
                END
            ")
            ->orderBy('instruction_item_categories.code')
            ->orderBy('instruction_item_categories.id')
            ->orderBy("{$table}.item_code")
            ->orderBy("{$table}.id");
    }

    private function normalizeItemScope(?string $itemScope): string
    {
        return $itemScope === self::ITEM_SCOPE_TOP_50
            ? self::ITEM_SCOPE_TOP_50
            : self::ITEM_SCOPE_ALL;
    }

    private function buildHeader(WmsInventoryCount $inventoryCount): array
    {
        return [
            'count_date' => $inventoryCount->count_date?->format('Y/m/d') ?? '',
            'warehouse_code' => $inventoryCount->warehouse_code ?? '',
            'warehouse_name' => $inventoryCount->warehouse_name ?? '',
        ];
    }

    private function initPdf(): void
    {
        $this->pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $this->pdf->SetCreator('Smart WMS');
        $this->pdf->SetAuthor('Smart WMS');
        $this->pdf->SetTitle('棚卸し指示書');
        $this->pdf->SetMargins(self::MARGIN_LEFT, self::MARGIN_TOP, self::MARGIN_RIGHT);
        $this->pdf->SetAutoPageBreak(false);
        $this->pdf->setPrintHeader(false);
        $this->pdf->setPrintFooter(false);
        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_NORMAL);
    }

    private function addNewPage(array $header, ?string $departmentName): void
    {
        $this->pdf->AddPage();
        $this->currentY = self::MARGIN_TOP;
        $this->renderPageHeader($header, $departmentName);
        $this->renderColumnHeaders();
    }

    private function renderPageHeader(array $header, ?string $departmentName): void
    {
        $leftX = self::MARGIN_LEFT;
        $contentW = self::CONTENT_WIDTH;

        $this->pdf->SetFont('kozgopromedium', 'B', self::FONT_SIZE_TITLE);
        $this->pdf->SetXY($leftX, $this->currentY);
        $this->pdf->Cell(100, 8, '棚卸し指示書', 0, 0, 'L');

        $printTimestamp = now()->format('Y/m/d H:i:s');
        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_PRINT_TIMESTAMP);
        $this->pdf->SetXY($leftX + $contentW - 50, $this->currentY);
        $this->pdf->Cell(50, 5, $printTimestamp, 0, 0, 'R');

        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_HEADER);
        $this->pdf->SetXY($leftX, $this->currentY + 8);
        $this->pdf->Cell(38, 5, '棚卸日 '.$header['count_date'], 0, 0, 'L');

        $this->pdf->SetXY($leftX + 42, $this->currentY + 8);
        $this->pdf->Cell(62, 5, $this->truncateText($header['warehouse_name'], 60), 0, 0, 'L');

        $this->pdf->SetXY($leftX + 108, $this->currentY + 8);
        $this->pdf->Cell(82, 5, $this->truncateText($departmentName ? '部門: '.$departmentName : '', 80), 0, 0, 'L');

        $this->currentY += 16;
    }

    private function renderColumnHeaders(): void
    {
        $x = self::MARGIN_LEFT;
        $y = $this->currentY;
        $rowH = self::HEADER_ROW_HEIGHT;

        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_COL_HEADER);
        $this->pdf->SetLineWidth(self::LINE_WIDTH);

        $headers = [
            ['JANコード', self::COL_W_JAN],
            ['商品名', self::COL_W_NAME],
            ['入数', self::COL_W_CAPACITY],
            ['総バラ', self::COL_W_TOTAL_PIECES],
            ['', self::COL_W_TOTAL_TO_ACTUAL_GAP],
            ['実棚数', self::COL_W_ACTUAL_QUANTITY],
        ];

        foreach ($headers as [$label, $width]) {
            $labelY = str_contains($label, "\n") ? $y + 0.8 : $y + 3;
            $this->pdf->MultiCell($width, 4.1, $label, 0, 'C', false, 0, $x, $labelY);
            $x += $width;
        }

        $this->pdf->Line(self::MARGIN_LEFT, $y + $rowH, self::MARGIN_LEFT + self::CONTENT_WIDTH, $y + $rowH);
        $this->currentY = $y + $rowH;
    }

    private function renderItemBlock(WmsInventoryCountItem $countItem, string $janCode, float $rowH): void
    {
        $x = self::MARGIN_LEFT;
        $y = $this->currentY;

        $this->pdf->SetLineWidth(self::LINE_WIDTH);
        $this->pdf->SetLineStyle(['dash' => '2,1']);
        $this->pdf->Line($x, $y, $x + self::CONTENT_WIDTH, $y);
        $this->pdf->SetLineStyle(['dash' => '']);

        $item = $countItem->item;
        $capacity = $this->capacityCase($item);
        $systemQuantity = (int) $countItem->system_quantity;
        $centerY = $y + ($rowH - self::HEADER_ROW_HEIGHT) / 2;
        $itemName = $this->displayItemName($countItem);

        $this->renderBarcodeCell($x, $y, self::COL_W_JAN, $janCode);
        $x += self::COL_W_JAN;

        $this->pdf->SetFont('kozgopromedium', 'B', self::FONT_SIZE_PRODUCT);
        $itemNameLineCount = max(1, $this->pdf->getNumLines($itemName, self::COL_W_NAME - 1));
        $itemNameHeight = $itemNameLineCount * self::PRODUCT_LINE_HEIGHT;
        $itemNameY = $y + max(2, ($rowH - $itemNameHeight) / 2);
        $this->pdf->MultiCell(self::COL_W_NAME, self::PRODUCT_LINE_HEIGHT, $itemName, 0, 'L', false, 0, $x, $itemNameY);
        $x += self::COL_W_NAME;

        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_STOCK);
        $this->pdf->SetXY($x, $centerY);
        $this->pdf->Cell(self::COL_W_CAPACITY, self::HEADER_ROW_HEIGHT, number_format($capacity).'入り', 0, 0, 'R');
        $x += self::COL_W_CAPACITY;

        $this->pdf->SetXY($x, $centerY);
        $this->pdf->Cell(self::COL_W_TOTAL_PIECES, self::HEADER_ROW_HEIGHT, number_format($systemQuantity), 0, 0, 'R');
        $x += self::COL_W_TOTAL_PIECES;
        $x += self::COL_W_TOTAL_TO_ACTUAL_GAP;

        $this->renderInputBox($x, $y, self::COL_W_ACTUAL_QUANTITY, $rowH);

        $this->currentY = $y + $rowH;
    }

    private function itemRowHeight(WmsInventoryCountItem $countItem): float
    {
        $this->pdf->SetFont('kozgopromedium', 'B', self::FONT_SIZE_PRODUCT);

        $lineCount = max(1, $this->pdf->getNumLines($this->displayItemName($countItem), self::COL_W_NAME - 1));

        return max(self::ITEM_ROW_HEIGHT, ($lineCount * self::PRODUCT_LINE_HEIGHT) + 4);
    }

    private function displayItemName(WmsInventoryCountItem $countItem): string
    {
        $itemName = (string) ($countItem->item_name ?? '');
        $itemCode = (string) ($countItem->item_code ?? '');

        if ($itemCode === '') {
            return $itemName;
        }

        return "[{$itemCode}] {$itemName}";
    }

    private function renderBarcodeCell(float $x, float $y, float $width, string $janCode): void
    {
        if ($janCode === '') {
            return;
        }

        $barcodeW = min(self::BARCODE_WIDTH, $width - 4);
        $barcodeX = $x + (($width - $barcodeW) / 2);
        $barcodeY = $y + 2;

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

    private function renderInputBox(float $x, float $y, float $width, float $height): void
    {
        $this->pdf->SetLineWidth(0.3);
        $this->pdf->SetLineStyle(['dash' => '']);
        $this->pdf->Rect($x + 1, $y + 2, $width - 2, $height - 4);
        $this->pdf->SetLineWidth(self::LINE_WIDTH);
    }

    private function capacityCase(?object $item): int
    {
        $capacity = (int) ($item?->capacity_case ?? 1);

        return max(1, $capacity);
    }

    private function renderPageNumbers(): void
    {
        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_HEADER);

        for ($i = 1; $i <= $this->totalPages; $i++) {
            $this->pdf->setPage($i);
            $pageText = "{$i} ／ {$this->totalPages}";
            $textWidth = $this->pdf->GetStringWidth($pageText);
            $x = self::PAGE_WIDTH - self::MARGIN_RIGHT - $textWidth;
            $y = self::MARGIN_TOP + 5;
            $this->pdf->SetXY($x, $y);
            $this->pdf->Cell($textWidth, 5, $pageText, 0, 0, 'R');
        }
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

    private function departmentPageKey(WmsInventoryCountItem $countItem): string
    {
        return $countItem->item?->item_category2?->id !== null
            ? (string) $countItem->item->item_category2->id
            : 'no_department';
    }

    private function departmentName(WmsInventoryCountItem $countItem): string
    {
        $category = $countItem->item?->item_category2;

        if ($category === null) {
            return '部門なし';
        }

        $code = trim((string) ($category->code ?? ''));
        $name = trim((string) ($category->name ?? ''));

        if ($code !== '' && $name !== '') {
            return "{$code} {$name}";
        }

        if ($name !== '') {
            return $name;
        }

        return $code !== '' ? $code : '部門なし';
    }
}
