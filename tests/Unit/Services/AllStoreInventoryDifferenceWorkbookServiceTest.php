<?php

namespace Tests\Unit\Services;

use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use App\Services\InventoryCount\AllStoreInventoryDifferenceWorkbookService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

class AllStoreInventoryDifferenceWorkbookServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['sakemaru'];

    public function test_all_store_difference_workbook_exports_store_matrix_and_category_rankings(): void
    {
        foreach ([
            ['wms_inventory_count_items', 'ending_system_quantity'],
            ['wms_inventory_count_items', 'second_count_confirmed_system_quantity'],
            ['wms_inventory_count_items', 'second_count_confirmed_difference_quantity'],
        ] as [$table, $column]) {
            if (! Schema::connection('sakemaru')->hasColumn($table, $column)) {
                $this->markTestSkipped("{$table}.{$column} is not available.");
            }
        }

        $this->travelTo(CarbonImmutable::parse('2026-08-23 10:00:00'));

        $count01 = $this->createInventoryCount('01', '本店');
        $count10 = $this->createInventoryCount('10', '敦賀店');
        $supplierId = $this->createSupplier(8007, '直送テスト');
        $contractorId = $this->createContractor(1202, '国分中部テスト', $supplierId);
        $sakeItemId = $this->createItem(111001, '全店差異 和酒テスト', 1001, 2011, true, $supplierId);
        $beerItemId = $this->createItem(143025, '全店差異 ビールテスト', 1001, 2014);
        $otherAlcoholItemId = $this->createItem(131047, '全店差異 その他酒類テスト', 1001, 2013);
        $excludedCategoryItemId = $this->createItem(910001, '全店差異 対象外分類テスト', 9999, 999901);
        $unmanagedItemId = $this->createItem(150002, '全店差異 在庫管理対象外テスト', 1002, 2021, false);
        $this->createItemContractor($sakeItemId, $contractorId, $supplierId);

        $this->createItemPrice($sakeItemId, 100);
        $this->createItemPrice($beerItemId, 50);
        $this->createItemPrice($otherAlcoholItemId, 10);
        $this->createItemPrice($excludedCategoryItemId, 888);
        $this->createItemPrice($unmanagedItemId, 999);

        $this->createCountItem($count01, $sakeItemId, '111001', '全店差異 和酒テスト', 10, 13, 3);
        $this->createCountItem($count10, $sakeItemId, '111001', '全店差異 和酒テスト', 10, 7, -3);
        $this->createCountItem($count10, $beerItemId, '143025', '全店差異 ビールテスト', 4, null, null);
        $this->createCountItem($count10, $otherAlcoholItemId, '131047', '全店差異 その他酒類テスト', 2, 1, -1);
        $this->createCountItem($count10, $excludedCategoryItemId, '910001', '全店差異 対象外分類テスト', 9, 0, -9);
        $this->createCountItem($count10, $unmanagedItemId, '150002', '全店差異 在庫管理対象外テスト', 8, 0, -8);

        $workbook = $this->loadWorkbook((new AllStoreInventoryDifferenceWorkbookService)->generate(collect([$count10, $count01])));

        $this->assertSame([
            '最新',
            '11・12和酒',
            '14ビール',
            '15ワイン',
            '2・6食品飲料',
            '3ギフト',
            '作業用シート',
        ], $workbook->getSheetNames());

        $latestRows = $this->rowsByItemCode($workbook->getSheetByName('最新'));
        $this->assertArrayHasKey('111001', $latestRows);
        $this->assertArrayHasKey('143025', $latestRows);
        $this->assertArrayHasKey('131047', $latestRows);
        $this->assertArrayNotHasKey('910001', $latestRows);
        $this->assertArrayNotHasKey('150002', $latestRows);
        $this->assertSame('1202', $latestRows['111001']['主仕入先ＣＤ']);
        $this->assertSame('国分中部テスト', $latestRows['111001']['仕入先名']);
        $this->assertSame(3, $latestRows['111001']['01']);
        $this->assertSame(-3, $latestRows['111001']['10']);
        $this->assertSame('=SUM(E2:F2)', $workbook->getSheetByName('最新')->getCell('G2')->getValue());
        $this->assertSame('=ABS(E2)+ABS(F2)', $workbook->getSheetByName('最新')->getCell('H2')->getValue());
        $this->assertSame('=IF(AND(G2=0,H2>0),"要確認","")', $workbook->getSheetByName('最新')->getCell('I2')->getValue());
        $this->assertSame(-4, $latestRows['143025']['10']);

        $sakeRows = $this->rowsByStoreAndItem($workbook->getSheetByName('11・12和酒'));
        $this->assertSame('1202', $sakeRows['01:111001']['主仕入先ＣＤ']);
        $this->assertSame('国分中部テスト', $sakeRows['01:111001']['仕入先名']);
        $this->assertSame(3, $sakeRows['01:111001']['差異数']);
        $this->assertSame(300.0, $sakeRows['01:111001']['絶対値差異']);
        $this->assertSame(-300.0, $sakeRows['10:111001']['＋-差異']);

        $beerRows = $this->rowsByStoreAndItem($workbook->getSheetByName('14ビール'));
        $this->assertSame(-4, $beerRows['10:143025']['差異数']);
        $this->assertSame(200.0, $beerRows['10:143025']['絶対値差異']);

        $this->assertArrayNotHasKey('10:131047', $sakeRows);
        $this->assertArrayNotHasKey('10:150002', $beerRows);
    }

    public function test_all_store_difference_workbook_selected_round_uses_only_physical_input(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-23 10:00:00'));

        $count01 = $this->createInventoryCount('01', '本店');
        $count10 = $this->createInventoryCount('10', '敦賀店');
        $inputItemId = $this->createItem(111002, '全店差異 選択回入力テスト', 1001, 2011);
        $noInputItemId = $this->createItem(111003, '全店差異 選択回未入力テスト', 1001, 2011);
        $this->createItemPrice($inputItemId, 100);
        $this->createItemPrice($noInputItemId, 100);

        $this->createCountItem($count01, $inputItemId, '111002', '全店差異 選択回入力テスト', 10, 8, -99);
        $this->createCountItem($count10, $inputItemId, '111002', '全店差異 選択回入力テスト', 10, null, -99);
        $this->createCountItem($count10, $noInputItemId, '111003', '全店差異 選択回未入力テスト', 5, null, -5);

        $workbook = $this->loadWorkbook((new AllStoreInventoryDifferenceWorkbookService)->generate(collect([$count10, $count01]), 2));

        $latestRows = $this->rowsByItemCode($workbook->getSheetByName('最新'));
        $this->assertArrayHasKey('111002', $latestRows);
        $this->assertArrayNotHasKey('111003', $latestRows);
        $this->assertSame(-2, $latestRows['111002']['01']);
        $this->assertSame(0, $latestRows['111002']['10']);

        $sakeRows = $this->rowsByStoreAndItem($workbook->getSheetByName('11・12和酒'));
        $this->assertArrayHasKey('01:111002', $sakeRows);
        $this->assertArrayNotHasKey('10:111002', $sakeRows);
        $this->assertArrayNotHasKey('10:111003', $sakeRows);
        $this->assertSame(-2, $sakeRows['01:111002']['差異数']);
        $this->assertSame(200.0, $sakeRows['01:111002']['絶対値差異']);
        $this->assertSame(-200.0, $sakeRows['01:111002']['＋-差異']);
    }

    public function test_all_store_difference_workbook_exports_empty_sheets_when_no_differences(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-23 10:00:00'));

        $count01 = $this->createInventoryCount('01', '本店');
        $workbook = $this->loadWorkbook((new AllStoreInventoryDifferenceWorkbookService)->generate(collect([$count01])));

        $this->assertSame(['最新', '11・12和酒', '14ビール', '15ワイン', '2・6食品飲料', '3ギフト', '作業用シート'], $workbook->getSheetNames());
        $this->assertSame(1, $workbook->getSheetByName('最新')->getHighestRow());
        $this->assertSame(1, $workbook->getSheetByName('作業用シート')->getHighestRow());
    }

    private function createInventoryCount(string $warehouseCode, string $warehouseName): WmsInventoryCount
    {
        return WmsInventoryCount::create([
            'count_no' => 'AST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => random_int(1000000, 1999999),
            'warehouse_code' => $warehouseCode,
            'warehouse_name' => $warehouseName,
            'count_date' => '2026-08-17',
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'current_count_round' => 2,
            'first_count_confirmed_at' => now()->subHour(),
            'second_count_confirmed_at' => now(),
            'ending_stock_taken_at' => now(),
        ]);
    }

    private function createCountItem(
        WmsInventoryCount $inventoryCount,
        int $itemId,
        string $itemCode,
        string $itemName,
        int $systemQuantity,
        ?int $secondCountQuantity,
        ?int $confirmedDifference,
    ): WmsInventoryCountItem {
        return WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => random_int(900000000, 999999999),
            'item_id' => $itemId,
            'item_code' => $itemCode,
            'item_name' => $itemName,
            'system_quantity' => $systemQuantity,
            'ending_system_quantity' => $systemQuantity,
            'first_count_quantity' => $systemQuantity,
            'second_count_quantity' => $secondCountQuantity,
            'first_count_confirmed_system_quantity' => $systemQuantity,
            'first_count_confirmed_difference_quantity' => 0,
            'first_count_confirmed_difference_amount' => 0,
            'second_count_confirmed_system_quantity' => $systemQuantity,
            'second_count_confirmed_difference_quantity' => $confirmedDifference,
            'second_count_confirmed_difference_amount' => $confirmedDifference === null ? null : $confirmedDifference * 10,
            'input_count' => $secondCountQuantity === null ? 0 : 2,
        ]);
    }

    private function createSupplier(int $code, string $name): int
    {
        $partnerId = (int) DB::connection('sakemaru')->table('partners')->insertGetId([
            'client_id' => 1,
            'code' => $code,
            'name_main' => $name,
            'is_supplier' => true,
            'is_active' => true,
            'creator_id' => 1,
            'last_updater_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::connection('sakemaru')->table('suppliers')->insertGetId([
            'client_id' => 1,
            'partner_id' => $partnerId,
            'delivery_price_payer' => 'CLIENT',
            'payee_bank_type' => 'OTHER_BANK',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createContractor(int $code, string $name, int $supplierId): int
    {
        return (int) DB::connection('sakemaru')->table('contractors')->insertGetId([
            'client_id' => 1,
            'code' => $code,
            'name' => $name,
            'supplier_id' => $supplierId,
            'delivery_type' => 'DIRECT',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createItemContractor(int $itemId, int $contractorId, int $supplierId): int
    {
        return (int) DB::connection('sakemaru')->table('item_contractors')->insertGetId([
            'client_id' => 1,
            'item_id' => $itemId,
            'warehouse_id' => 1,
            'contractor_id' => $contractorId,
            'supplier_id' => $supplierId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createItem(
        int $itemCode,
        string $itemName,
        int $majorCategoryCode,
        int $middleCategoryCode,
        bool $managedStock = true,
        ?int $supplierId = null,
    ): int {
        $majorCategoryId = (int) DB::connection('sakemaru')->table('item_categories')->insertGetId([
            'client_id' => 1,
            'name' => '全店差異大分類'.$majorCategoryCode,
            'code' => $majorCategoryCode,
            'depth' => 1,
            'creator_id' => 1,
            'last_updater_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $middleCategoryId = (int) DB::connection('sakemaru')->table('item_categories')->insertGetId([
            'client_id' => 1,
            'name' => '全店差異中分類'.$middleCategoryCode,
            'parent_id' => $majorCategoryId,
            'code' => $middleCategoryCode,
            'depth' => 2,
            'creator_id' => 1,
            'last_updater_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemData = [
            'name_main' => $itemName,
            'code' => $itemCode,
            'type' => 'NOT_ALCOHOL',
            'manufacturer_id' => 0,
            'volume' => 1,
            'capacity_case' => 1,
            'creator_id' => 1,
            'packaging' => '1',
            'nickname' => $itemName,
            'client_id' => 1,
            'item_category1_id' => $majorCategoryId,
            'item_category2_id' => $middleCategoryId,
            'supplier_id' => $supplierId,
            'container_type_id' => 0,
            'manufacture_type_id' => 0,
            'storage_type_id' => 0,
            'measurement_unit_weight' => 0,
            'measurement_case_weight' => 0,
            'order_rank' => 'ORDER_MANUAL',
            'last_updater_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::connection('sakemaru')->hasColumn('items', 'is_managed_stock')) {
            $itemData['is_managed_stock'] = $managedStock;
        }

        return (int) DB::connection('sakemaru')->table('items')->insertGetId($itemData);
    }

    private function createItemPrice(int $itemId, float $costUnitPrice): int
    {
        return (int) DB::connection('sakemaru')->table('item_prices')->insertGetId([
            'item_id' => $itemId,
            'start_date' => '2026-08-23',
            'producer_unit_price' => 0,
            'producer_case_price' => 0,
            'producer_crate_price' => 0,
            'cost_unit_price' => $costUnitPrice,
            'wholesale_unit_price' => 0,
            'sale_unit_price' => 0,
            'sub_unit_price' => 0,
            'retail_unit_price' => 0,
            'tax_exempt_unit_price' => 0,
            'type' => 'EXEMPT',
            'client_id' => 1,
            'creator_id' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
            'is_created_from_data_transfer' => false,
            'last_updater_id' => 0,
        ]);
    }

    private function loadWorkbook(string $xlsxContent): Spreadsheet
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'all-store-diff-test-');
        file_put_contents($tempPath, $xlsxContent);

        try {
            return IOFactory::load($tempPath);
        } finally {
            @unlink($tempPath);
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rowsByItemCode(Worksheet $sheet): array
    {
        $headers = [];
        $highestColumnIndex = Coordinate::columnIndexFromString($sheet->getHighestColumn());
        foreach (range(1, $highestColumnIndex) as $columnIndex) {
            $column = Coordinate::stringFromColumnIndex($columnIndex);
            $headers[$column] = (string) $sheet->getCell($column.'1')->getValue();
        }

        $rows = [];
        for ($rowIndex = 2; $rowIndex <= $sheet->getHighestRow(); $rowIndex++) {
            $row = [];
            foreach ($headers as $column => $header) {
                if ($header === '') {
                    continue;
                }

                $row[$header] = $sheet->getCell($column.$rowIndex)->getValue();
            }

            $rows[(string) $row['単品ＣＤ']] = $row;
        }

        return $rows;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rowsByStoreAndItem(Worksheet $sheet): array
    {
        $rows = [];

        for ($rowIndex = 2; $rowIndex <= $sheet->getHighestRow(); $rowIndex++) {
            $row = [
                '店舗ＣＤ' => (string) $sheet->getCell("A{$rowIndex}")->getValue(),
                '単品ＣＤ' => (string) $sheet->getCell("B{$rowIndex}")->getValue(),
                '表示正式名称' => $sheet->getCell("C{$rowIndex}")->getValue(),
                '主仕入先ＣＤ' => (string) $sheet->getCell("D{$rowIndex}")->getValue(),
                '仕入先名' => $sheet->getCell("E{$rowIndex}")->getValue(),
                '差異数' => $sheet->getCell("F{$rowIndex}")->getValue(),
                '絶対値差異' => $sheet->getCell("G{$rowIndex}")->getValue(),
                '＋-差異' => $sheet->getCell("H{$rowIndex}")->getValue(),
            ];

            if ($row['店舗ＣＤ'] === '' || $row['単品ＣＤ'] === '') {
                continue;
            }

            $rows[$row['店舗ＣＤ'].':'.$row['単品ＣＤ']] = $row;
        }

        return $rows;
    }
}
