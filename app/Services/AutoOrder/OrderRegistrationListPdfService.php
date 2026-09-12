<?php

namespace App\Services\AutoOrder;

use App\Enums\QuantityType;
use Carbon\Carbon;
use TCPDF;

class OrderRegistrationListPdfService
{
    private const PAGE_WIDTH = 210;

    private const PAGE_HEIGHT = 297;

    private const MARGIN_LEFT = 10;

    private const MARGIN_TOP = 10;

    private const MARGIN_RIGHT = 10;

    private const MARGIN_BOTTOM = 12;

    private const CONTENT_WIDTH = 190;

    private const HEADER_ROW_HEIGHT = 8;

    private const MIN_ROW_HEIGHT = 8;

    private const LINE_WIDTH = 0.2;

    private const FONT_FAMILY = 'kozgopromedium';

    private const FONT_SIZE_TITLE = 15;

    private const FONT_SIZE_META = 8.5;

    private const FONT_SIZE_HEADER = 7.2;

    private const FONT_SIZE_BODY = 7.0;

    private const FONT_SIZE_BODY_SMALL = 6.5;

    private const COL_WIDTHS = [
        'ordering_code' => 24,
        'contractor_name' => 28,
        'contractor_code' => 17,
        'item_code' => 17,
        'item_name' => 41,
        'packaging' => 16,
        'case_qty' => 12,
        'piece_qty' => 12,
        'expected_arrival_date' => 23,
    ];

    private TCPDF $pdf;

    private float $currentY = 0;

    private int $totalPages = 0;

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array{warehouse_label?: string, contractor_filter_label?: string}  $context
     */
    public function generate(array $lines, array $context = []): string
    {
        $this->initPdf();
        $this->addPageWithHeader($context, count($lines));

        if ($lines === []) {
            $this->pdf->SetFont(self::FONT_FAMILY, '', 11);
            $this->pdf->SetXY(self::MARGIN_LEFT, $this->currentY + 12);
            $this->pdf->Cell(self::CONTENT_WIDTH, 8, '登録リストは空です', 0, 0, 'C');
        }

        foreach (array_values($lines) as $line) {
            $row = $this->lineToRow($line);
            $rowHeight = $this->rowHeight($row);

            if ($this->currentY + $rowHeight > self::PAGE_HEIGHT - self::MARGIN_BOTTOM) {
                $this->addPageWithHeader($context, count($lines));
            }

            $this->renderRow($row, $rowHeight);
        }

        $this->totalPages = $this->pdf->getNumPages();
        $this->renderPageNumbers();

        return $this->pdf->Output('', 'S');
    }

    private function initPdf(): void
    {
        $this->pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $this->pdf->SetCreator('Smart WMS');
        $this->pdf->SetAuthor('Smart WMS');
        $this->pdf->SetTitle('発注登録リスト');
        $this->pdf->SetMargins(self::MARGIN_LEFT, self::MARGIN_TOP, self::MARGIN_RIGHT);
        $this->pdf->SetAutoPageBreak(false);
        $this->pdf->setPrintHeader(false);
        $this->pdf->setPrintFooter(false);
        $this->pdf->SetFont(self::FONT_FAMILY, '', self::FONT_SIZE_BODY);
        $this->pdf->SetLineWidth(self::LINE_WIDTH);
    }

    /**
     * @param  array{warehouse_label?: string, contractor_filter_label?: string}  $context
     */
    private function addPageWithHeader(array $context, int $lineCount): void
    {
        $this->pdf->AddPage();
        $this->currentY = self::MARGIN_TOP;

        $this->pdf->SetFont(self::FONT_FAMILY, 'B', self::FONT_SIZE_TITLE);
        $this->pdf->SetXY(self::MARGIN_LEFT, $this->currentY);
        $this->pdf->Cell(80, 7, '発注登録リスト', 0, 0, 'L');

        $this->pdf->SetFont(self::FONT_FAMILY, '', self::FONT_SIZE_META);
        $generatedAt = now()->format('Y/m/d H:i:s');
        $this->pdf->SetXY(self::PAGE_WIDTH - self::MARGIN_RIGHT - 64, $this->currentY + 0.5);
        $this->pdf->Cell(64, 5, '出力日時 '.$generatedAt, 0, 0, 'R');

        $this->currentY += 8;
        $this->pdf->SetFont(self::FONT_FAMILY, '', self::FONT_SIZE_META);
        $warehouseLabel = trim((string) ($context['warehouse_label'] ?? ''));
        $contractorFilterLabel = trim((string) ($context['contractor_filter_label'] ?? ''));
        $metaParts = [
            '倉庫 '.$this->dashIfBlank($warehouseLabel),
            '明細 '.number_format($lineCount).'件',
        ];
        if ($contractorFilterLabel !== '') {
            $metaParts[] = '発注先 '.$contractorFilterLabel;
        }

        $this->pdf->SetXY(self::MARGIN_LEFT, $this->currentY);
        $this->pdf->Cell(self::CONTENT_WIDTH, 5, implode(' / ', $metaParts), 0, 0, 'L');

        $this->currentY += 8;
        $this->renderColumnHeaders();
    }

    private function renderColumnHeaders(): void
    {
        $headers = [
            'ordering_code' => '発注コード',
            'contractor_name' => '発注先',
            'contractor_code' => '発注先CD',
            'item_code' => '商品CD',
            'item_name' => '商品名',
            'packaging' => '規格',
            'case_qty' => 'ケース',
            'piece_qty' => 'バラ',
            'expected_arrival_date' => '入荷予定日',
        ];

        $x = self::MARGIN_LEFT;
        $y = $this->currentY;

        $this->pdf->SetFont(self::FONT_FAMILY, 'B', self::FONT_SIZE_HEADER);
        $this->pdf->SetFillColor(238, 242, 247);
        $this->pdf->SetTextColor(30, 41, 59);

        foreach ($headers as $key => $label) {
            $width = self::COL_WIDTHS[$key];
            $this->pdf->Rect($x, $y, $width, self::HEADER_ROW_HEIGHT, 'DF');
            $this->pdf->MultiCell($width - 1, self::HEADER_ROW_HEIGHT, $label, 0, 'C', false, 0, $x + 0.5, $y, true, 0, false, true, self::HEADER_ROW_HEIGHT, 'M');
            $x += $width;
        }

        $this->pdf->SetTextColor(0, 0, 0);
        $this->currentY += self::HEADER_ROW_HEIGHT;
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, string>
     */
    private function lineToRow(array $line): array
    {
        $quantityType = QuantityType::tryFrom((string) ($line['quantity_type'] ?? '')) ?? QuantityType::PIECE;
        $orderQuantity = max(0, (int) ($line['order_quantity'] ?? 0));

        return [
            'ordering_code' => $this->dashIfBlank($line['ordering_code'] ?? $line['search_code'] ?? null),
            'contractor_name' => $this->dashIfBlank($line['contractor_name'] ?? null),
            'contractor_code' => $this->dashIfBlank($line['contractor_code'] ?? null),
            'item_code' => $this->dashIfBlank($line['item_code'] ?? null),
            'item_name' => $this->dashIfBlank($line['item_name'] ?? null),
            'packaging' => $this->dashIfBlank($line['item_packaging'] ?? null),
            'case_qty' => $quantityType === QuantityType::CASE && $orderQuantity > 0 ? (string) $orderQuantity : '',
            'piece_qty' => $quantityType === QuantityType::PIECE && $orderQuantity > 0 ? (string) $orderQuantity : '',
            'expected_arrival_date' => $this->formatDate($line['expected_arrival_date'] ?? null),
        ];
    }

    /**
     * @param  array<string, string>  $row
     */
    private function rowHeight(array $row): float
    {
        $height = self::MIN_ROW_HEIGHT;

        foreach ($row as $key => $value) {
            $width = self::COL_WIDTHS[$key] - 1.5;
            $fontSize = in_array($key, ['contractor_name', 'item_name'], true)
                ? self::FONT_SIZE_BODY_SMALL
                : self::FONT_SIZE_BODY;

            $this->pdf->SetFont(self::FONT_FAMILY, '', $fontSize);
            $height = max($height, $this->pdf->getStringHeight($width, $value) + 2.5);
        }

        return min(22, $height);
    }

    /**
     * @param  array<string, string>  $row
     */
    private function renderRow(array $row, float $rowHeight): void
    {
        $x = self::MARGIN_LEFT;
        $y = $this->currentY;

        $aligns = [
            'ordering_code' => 'C',
            'contractor_name' => 'L',
            'contractor_code' => 'C',
            'item_code' => 'C',
            'item_name' => 'L',
            'packaging' => 'C',
            'case_qty' => 'R',
            'piece_qty' => 'R',
            'expected_arrival_date' => 'C',
        ];

        foreach ($row as $key => $value) {
            $width = self::COL_WIDTHS[$key];
            $fontSize = in_array($key, ['contractor_name', 'item_name'], true)
                ? self::FONT_SIZE_BODY_SMALL
                : self::FONT_SIZE_BODY;

            $this->renderTextCell($x, $y, $width, $rowHeight, $value, $aligns[$key], $fontSize);
            $x += $width;
        }

        $this->currentY += $rowHeight;
    }

    private function renderTextCell(
        float $x,
        float $y,
        float $width,
        float $height,
        string $text,
        string $align,
        float $fontSize
    ): void {
        $this->pdf->Rect($x, $y, $width, $height);
        $this->pdf->SetFont(self::FONT_FAMILY, '', $fontSize);
        $this->pdf->SetXY($x + 0.7, $y + 0.6);
        $this->pdf->MultiCell($width - 1.4, $height - 1.2, $text, 0, $align, false, 0, $x + 0.7, $y + 0.6, true, 0, false, true, $height - 1.2, 'M');
    }

    private function renderPageNumbers(): void
    {
        for ($page = 1; $page <= $this->totalPages; $page++) {
            $this->pdf->setPage($page);
            $this->pdf->SetFont(self::FONT_FAMILY, '', 8);
            $this->pdf->SetXY(self::PAGE_WIDTH - self::MARGIN_RIGHT - 30, self::PAGE_HEIGHT - 9);
            $this->pdf->Cell(30, 5, "{$page} / {$this->totalPages}", 0, 0, 'R');
        }
    }

    private function formatDate(mixed $date): string
    {
        if (! filled($date)) {
            return '-';
        }

        try {
            return Carbon::parse((string) $date)->format('Y/m/d');
        } catch (\Throwable) {
            return $this->dashIfBlank($date);
        }
    }

    private function dashIfBlank(mixed $value): string
    {
        $value = trim((string) $value);

        return $value === '' ? '-' : $value;
    }
}
