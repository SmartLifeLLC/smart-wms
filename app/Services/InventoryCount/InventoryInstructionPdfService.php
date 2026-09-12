<?php

namespace App\Services\InventoryCount;

use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use Illuminate\Support\Facades\Schema;
use TCPDF;

/**
 * JANブックPDF描画サービス
 *
 * TCPDF座標描画のみ使用（HTML禁止）
 * PickingListPdfService と同じパターン
 *
 * レイアウト: A4縦 / 棚番順 / 棚番先頭2桁改ページ / 3行ブロック
 */
class InventoryInstructionPdfService
{
    // フォントサイズ（pt）
    private const FONT_SIZE_TITLE = 18;

    private const FONT_SIZE_HEADER = 9;

    private const FONT_SIZE_NORMAL = 8;

    private const FONT_SIZE_SMALL = 7;

    private const FONT_SIZE_COL_HEADER = 7;

    private const FONT_SIZE_PRODUCT = 9;

    private const FONT_SIZE_PRODUCT_CODE = 10;

    private const FONT_SIZE_SHELF = 10;

    private const FONT_SIZE_JAN = 6;

    // 行高さ（mm）
    private const LINE_HEIGHT = 5;

    // ブロック内の行高さ
    private const BLOCK_ROW_HEIGHT = 5.5;

    // 罫線幅（mm）
    private const LINE_WIDTH = 0.2;

    // マージン（mm）
    private const MARGIN_LEFT = 10;

    private const MARGIN_TOP = 8;

    private const MARGIN_RIGHT = 10;

    private const MARGIN_BOTTOM = 12;

    // A4縦
    private const PAGE_WIDTH = 210;

    private const PAGE_HEIGHT = 297;

    private const CONTENT_WIDTH = 190; // 210 - 10 - 10

    // 各列幅（mm）
    // Row1: item_code | shelf_no | packaging | (blank)
    // Row2: item_name | (long) | (blank) | (blank) | jan_code
    // Row3: (empty) | (blank) | (blank) | (blank)
    //
    // 列配分（左→右）:
    // col1: 商品コード/商品名 = 80mm
    // col2: 棚番 = 30mm
    // col3: 規格 = 35mm
    // col4: 予備 = 0mm
    // col5: JANコード = 45mm（左右交互配置用に広めに確保）
    private const COL_W1 = 80;  // item_code / item_name / (blank)

    private const COL_W2 = 30;  // shelf_no

    private const COL_W3 = 35;  // packaging

    private const COL_W4 = 0;  // reserved

    private const COL_W5 = 45;  // barcode (3 rows)

    private const BARCODE_WIDTH = 42;

    private const BARCODE_HEIGHT = 10.5;

    private TCPDF $pdf;

    private float $currentY;

    private int $totalPages = 0;

    private int $renderedItemCount = 0;

    /**
     * JANブックPDF生成
     */
    public function generate(WmsInventoryCount $inventoryCount, bool $excludeZeroTheory = false): string
    {
        $items = $this->queryItems($inventoryCount, $excludeZeroTheory);
        $janCodes = (new InventoryJanCodeResolver)->forItems($items);

        $this->initPdf();
        $this->renderedItemCount = 0;

        $header = $this->buildHeader($inventoryCount);
        $currentShelfPrefix = null;
        $isFirstPage = true;

        foreach ($items as $item) {
            $shelfPrefix = $this->shelfPagePrefix($item);

            // 棚番先頭2桁変更 → 改ページ
            if ($currentShelfPrefix !== null && $currentShelfPrefix !== $shelfPrefix) {
                $this->addNewPage($header, $shelfPrefix);
                $isFirstPage = false;
            } elseif ($isFirstPage && $currentShelfPrefix === null) {
                // 最初のページ
                $this->addNewPage($header, $shelfPrefix);
                $isFirstPage = false;
            }

            $currentShelfPrefix = $shelfPrefix;

            // ブロック高さ = 3行分
            $blockHeight = self::BLOCK_ROW_HEIGHT * 3;

            // 改ページチェック
            if ($this->currentY + $blockHeight > self::PAGE_HEIGHT - self::MARGIN_BOTTOM) {
                $this->addNewPage($header, $currentShelfPrefix);
            }

            $this->renderItemBlock($item, $janCodes[(int) $item->item_id] ?? '');
        }

        // ページが無い場合（items空）
        if ($this->pdf->getNumPages() === 0) {
            $this->addNewPage($header, null);
            $this->pdf->SetFont('kozgopromedium', '', 12);
            $this->pdf->SetXY(self::MARGIN_LEFT, $this->currentY);
            $this->pdf->Cell(self::CONTENT_WIDTH, 10, '対象データなし', 0, 0, 'C');
        }

        // ページ番号を全ページに描画
        $this->totalPages = $this->pdf->getNumPages();
        $this->renderPageNumbers();

        return $this->pdf->Output('', 'S');
    }

    // ========================================
    // データ取得
    // ========================================

    /**
     * @return \Illuminate\Database\Eloquent\Collection<WmsInventoryCountItem>
     */
    private function queryItems(WmsInventoryCount $inventoryCount, bool $excludeZeroTheory = false): \Illuminate\Database\Eloquent\Collection
    {
        $query = WmsInventoryCountItem::where('inventory_count_id', $inventoryCount->id)
            ->withoutOwnedSetItems()
            ->with(['item']);

        if ($excludeZeroTheory) {
            $query->whereRaw($this->systemQuantityExpression().' != 0');
        }

        return $query
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
    }

    private function systemQuantityExpression(): string
    {
        if (Schema::connection('sakemaru')->hasColumn('wms_inventory_count_items', 'ending_system_quantity')) {
            return 'COALESCE(ending_system_quantity, system_quantity)';
        }

        return 'system_quantity';
    }

    private function buildHeader(WmsInventoryCount $inventoryCount): array
    {
        return [
            'count_date' => $inventoryCount->count_date?->format('Y/m/d') ?? '',
            'warehouse_code' => $inventoryCount->warehouse_code ?? '',
            'warehouse_name' => $inventoryCount->warehouse_name ?? '',
        ];
    }

    // ========================================
    // PDF初期化
    // ========================================

    private function initPdf(): void
    {
        $this->pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $this->pdf->SetCreator('Smart WMS');
        $this->pdf->SetAuthor('Smart WMS');
        $this->pdf->SetTitle('JANブック');
        $this->pdf->SetMargins(self::MARGIN_LEFT, self::MARGIN_TOP, self::MARGIN_RIGHT);
        $this->pdf->SetAutoPageBreak(false);
        $this->pdf->setPrintHeader(false);
        $this->pdf->setPrintFooter(false);
        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_NORMAL);
    }

    // ========================================
    // ページ追加
    // ========================================

    private function addNewPage(array $header, ?string $shelfPrefix): void
    {
        $this->pdf->AddPage();
        $this->currentY = self::MARGIN_TOP;
        $this->renderPageHeader($header, $shelfPrefix);
        $this->renderColumnHeaders();
    }

    // ========================================
    // ヘッダー描画
    // ========================================

    private function renderPageHeader(array $header, ?string $shelfPrefix): void
    {
        $leftX = self::MARGIN_LEFT;
        $contentW = self::CONTENT_WIDTH;

        // Row 1: 棚番グループ（左）/ 棚卸日・倉庫名（中央）/ 印刷日時（右）
        $this->pdf->SetFont('kozgopromedium', 'B', self::FONT_SIZE_TITLE);
        $this->pdf->SetXY($leftX, $this->currentY);
        $this->pdf->Cell(52, 10, '棚番：'.($shelfPrefix ?? ''), 0, 0, 'L');

        // 棚卸日（タイトル右横）
        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_HEADER);
        $this->pdf->SetXY($leftX + 54, $this->currentY + 2);
        $this->pdf->Cell(38, 5, '棚卸日 '.$header['count_date'], 0, 0, 'L');

        $this->pdf->SetXY($leftX + 94, $this->currentY + 2);
        $this->pdf->Cell(80, 5, $this->truncateText($header['warehouse_name'], 78), 0, 0, 'L');

        // 印刷日時（右）
        $printTimestamp = now()->format('Y/m/d H:i:s');
        $this->pdf->SetXY($leftX + $contentW - 50, $this->currentY);
        $this->pdf->Cell(50, 5, $printTimestamp, 0, 0, 'R');

        $this->currentY += 12;
    }

    private function renderColumnHeaders(): void
    {
        $x = self::MARGIN_LEFT;
        $y = $this->currentY;
        $rowH = self::BLOCK_ROW_HEIGHT;

        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_COL_HEADER);
        $this->pdf->SetLineWidth(self::LINE_WIDTH);

        // Row1 headers
        $this->pdf->SetXY($x, $y);
        $this->pdf->Cell(self::COL_W1, $rowH, '商品コード', 0, 0, 'L');

        $this->pdf->SetXY($x + self::COL_W1, $y);
        $this->pdf->Cell(self::COL_W2, $rowH, '棚番', 0, 0, 'L');

        $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2, $y);
        $this->pdf->Cell(self::COL_W3, $rowH, '規格', 0, 0, 'L');

        // Row2 headers
        $y2 = $y + $rowH;
        $this->pdf->SetXY($x, $y2);
        $this->pdf->Cell(self::COL_W1, $rowH, '商品名', 0, 0, 'L');

        $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2 + self::COL_W3 + self::COL_W4, $y2);
        $this->pdf->Cell(self::COL_W5, $rowH, 'JANコード', 0, 0, 'L');

        // Row3 headers
        $y3 = $y + $rowH * 2;
        $this->pdf->SetXY($x, $y3);
        $this->pdf->Cell(self::COL_W1, $rowH, '', 0, 0, 'L');

        $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2, $y3);
        $this->pdf->Cell(self::COL_W3, $rowH, '', 0, 0, 'L');

        $this->currentY = $y3 + $rowH;
    }

    // ========================================
    // アイテムブロック描画（3行1セット）
    // ========================================

    private function renderItemBlock(WmsInventoryCountItem $countItem, string $janCode): void
    {
        $x = self::MARGIN_LEFT;
        $y = $this->currentY;
        $rowH = self::BLOCK_ROW_HEIGHT;

        // 区切り線（ブロック上端）— 点線
        $this->pdf->SetLineWidth(self::LINE_WIDTH);
        $this->pdf->SetLineStyle(['dash' => '2,1']);
        $this->pdf->Line($x, $y, $x + self::CONTENT_WIDTH, $y);
        $this->pdf->SetLineStyle(['dash' => '']);

        // データ準備
        $item = $countItem->item;
        $spec = (string) ($item?->packaging ?? '');

        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_NORMAL);

        // === Row 1: item_code | shelf_no | packaging | (blank) ===
        $y1 = $y;
        $shelfNo = $this->shelfCode($countItem);

        // item_code（太字）
        $this->pdf->SetFont('kozgopromedium', 'B', self::FONT_SIZE_PRODUCT_CODE);
        $this->pdf->SetXY($x, $y1);
        $this->pdf->Cell(self::COL_W1, $rowH, $countItem->item_code ?? '', 0, 0, 'L');

        // shelf_no（太字）
        $this->pdf->SetFont('kozgopromedium', 'B', self::FONT_SIZE_SHELF);
        $this->pdf->SetXY($x + self::COL_W1, $y1);
        $this->pdf->Cell(self::COL_W2, $rowH, $shelfNo, 0, 0, 'L');

        // packaging
        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_NORMAL);
        $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2, $y1);
        $this->pdf->Cell(self::COL_W3, $rowH, $this->truncateText($spec, self::COL_W3 - 2), 0, 0, 'L');

        // === Row 2: item_name | (long) | (blank) | (blank) | jan_code ===
        $y2 = $y + $rowH;

        // item_name
        $this->pdf->SetFont('kozgopromedium', 'B', self::FONT_SIZE_PRODUCT);
        $this->pdf->SetXY($x, $y2);
        $this->pdf->Cell(self::COL_W1 + self::COL_W2 + self::COL_W3 - 2, $rowH, (string) ($countItem->item_name ?? ''), 0, 0, 'L');

        // === Row 3: (empty) | (blank) | (blank) | (blank) ===
        $y3 = $y + $rowH * 2;

        // === JANコード（Row1-3の右端、左右交互）===
        $barcodeAreaX = $x + self::COL_W1 + self::COL_W2 + self::COL_W3 + self::COL_W4;
        $barcodeW = min(self::BARCODE_WIDTH, self::COL_W5 - 3);
        $barcodeX = $barcodeAreaX + ((self::COL_W5 - $barcodeW) / 2);
        $barcodeH = self::BARCODE_HEIGHT;
        $barcodeTextH = 3;
        $barcodeTextGap = 1;
        $barcodeY = $y1 + (($rowH * 3) - ($barcodeH + $barcodeTextGap + $barcodeTextH)) / 2;
        $barcode = $janCode;

        if ($barcode !== '') {
            $this->pdf->write1DBarcode(
                $barcode,
                'C128',
                $barcodeX,
                $barcodeY,
                $barcodeW,
                $barcodeH,
                0.45,
                ['position' => '', 'border' => false, 'padding' => 0, 'fgcolor' => [0, 0, 0], 'bgcolor' => false, 'text' => false, 'font' => 'kozgopromedium', 'fontsize' => 0, 'stretchtext' => 0],
                'N'
            );

            $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_JAN);
            $this->pdf->SetXY($barcodeX, $barcodeY + $barcodeH + $barcodeTextGap);
            $this->pdf->Cell($barcodeW, $barcodeTextH, $barcode, 0, 0, 'C');
        }

        $this->renderedItemCount++;
        $this->currentY = $y3 + $rowH;
    }

    // ========================================
    // ページ番号描画
    // ========================================

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

    // ========================================
    // ユーティリティ
    // ========================================

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

    private function shelfCode(WmsInventoryCountItem $countItem): string
    {
        $code = \App\Models\Sakemaru\Location::formatCode(
            $countItem->location_code1,
            $countItem->location_code2,
            $countItem->location_code3,
            ''
        );

        return $code !== '' ? $code : (string) ($countItem->location_no ?? '');
    }

    private function shelfPagePrefix(WmsInventoryCountItem $countItem): string
    {
        $shelfCode = $this->shelfCode($countItem);

        return mb_substr($shelfCode, 0, 2);
    }
}
