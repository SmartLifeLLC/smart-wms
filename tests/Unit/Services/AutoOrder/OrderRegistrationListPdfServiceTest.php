<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Enums\QuantityType;
use App\Services\AutoOrder\OrderRegistrationListPdfService;
use Tests\TestCase;

class OrderRegistrationListPdfServiceTest extends TestCase
{
    public function test_registration_list_pdf_can_be_generated(): void
    {
        $pdf = (new OrderRegistrationListPdfService)->generate($this->lines(), [
            'warehouse_label' => '[91]華むすびの蔵センター',
        ]);

        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_registration_list_pdf_contains_required_columns_and_values(): void
    {
        $pdftotext = trim((string) shell_exec('command -v pdftotext 2>/dev/null'));
        if ($pdftotext === '') {
            $this->markTestSkipped('pdftotext is not available.');
        }

        $text = $this->extractPdfText((new OrderRegistrationListPdfService)->generate($this->lines()), $pdftotext);

        $this->assertStringContainsString('発注コード', $text);
        $this->assertStringContainsString('発注先', $text);
        $this->assertStringContainsString('発注先CD', $text);
        $this->assertStringContainsString('商品CD', $text);
        $this->assertStringContainsString('商品名', $text);
        $this->assertStringContainsString('規格', $text);
        $this->assertStringContainsString('ケース', $text);
        $this->assertStringContainsString('バラ', $text);
        $this->assertStringContainsString('入荷予定日', $text);
        $this->assertStringContainsString('4902650047861', $text);
        $this->assertStringContainsString('カナカン(株)酒類', $text);
        $this->assertStringContainsString('111123', $text);
        $this->assertStringContainsString('300ml 12', $text);
        $this->assertStringContainsString('2026/08/24', $text);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function lines(): array
    {
        return [
            [
                'ordering_code' => '4902650047861',
                'contractor_code' => '1021',
                'contractor_name' => 'カナカン(株)酒類　福井営業所',
                'item_code' => '111123',
                'item_name' => '白鶴淡雪スパークリング３００ｍｌ',
                'item_packaging' => '300ml 12',
                'quantity_type' => QuantityType::CASE->value,
                'order_quantity' => 2,
                'expected_arrival_date' => '2026-08-24',
            ],
            [
                'search_code' => 'ORDER-PIECE',
                'contractor_code' => '1330',
                'contractor_name' => '三菱食品株式会社',
                'item_code' => '100132',
                'item_name' => '一番搾り紙コップ　４１５ＭＬ用　５０個入',
                'item_packaging' => '50個',
                'quantity_type' => QuantityType::PIECE->value,
                'order_quantity' => 6,
                'expected_arrival_date' => '2026-08-25',
            ],
        ];
    }

    private function extractPdfText(string $pdf, string $pdftotext): string
    {
        $pdfPath = tempnam(sys_get_temp_dir(), 'wms-order-registration-list-pdf-');
        $textPath = tempnam(sys_get_temp_dir(), 'wms-order-registration-list-text-');

        try {
            file_put_contents($pdfPath, $pdf);
            $command = escapeshellarg($pdftotext).' -layout '.escapeshellarg($pdfPath).' '.escapeshellarg($textPath);
            shell_exec($command);

            return (string) file_get_contents($textPath);
        } finally {
            if (is_file($pdfPath)) {
                unlink($pdfPath);
            }
            if (is_file($textPath)) {
                unlink($textPath);
            }
        }
    }
}
