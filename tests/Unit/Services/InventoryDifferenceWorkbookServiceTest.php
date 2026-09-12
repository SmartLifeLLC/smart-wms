<?php

namespace Tests\Unit\Services;

use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use App\Services\InventoryCount\InventoryDifferenceWorkbookService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

class InventoryDifferenceWorkbookServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['sakemaru'];

    public function test_difference_workbook_exports_diff_and_uncounted_sheets(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-20 10:00:00'));

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 987654321,
            'warehouse_code' => '987654321',
            'warehouse_name' => '差異データテスト倉庫',
            'count_date' => '2026-08-19',
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'current_count_round' => 3,
            'first_count_confirmed_at' => now()->subHour(),
            'second_count_confirmed_at' => now(),
            'ending_stock_taken_at' => now(),
        ]);

        $targetItemId = $this->createItemInMajorCategory(1001);
        $matchedItemId = $this->createItemInMajorCategory(1002);
        $zeroSystemItemId = $this->createItemInMajorCategory(1003);
        $excludedCategoryItemId = $this->createItemInMajorCategory(9999);
        $ownedSetItemId = $this->createItemInMajorCategory(1001, true);
        $nonManagedItemId = $this->createItemInMajorCategory(1001, false, false, 40);

        $this->createItemPrice($targetItemId, '2026-08-18', 20);
        $this->createItemPrice($targetItemId, '2026-08-19', 24);
        $this->createItemPrice($targetItemId, '2026-08-19', 25);
        $this->createItemPrice($targetItemId, '2026-08-19', 999, false);
        $this->createItemPrice($targetItemId, '2026-08-20', 500);
        $expectedCostPrice = 500;
        $expectedNonManagedCostPrice = Schema::connection('sakemaru')->hasColumn('item_categories', 'cost_rate') ? 80 : 0;
        $expectedNonManagedStockAmount = 13 * $expectedNonManagedCostPrice;
        $expectedMajorStockAmount = (40 * $expectedCostPrice) + $expectedNonManagedStockAmount;
        $this->createItemPrice($nonManagedItemId, '2026-08-20', 999, true, 200);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => random_int(900000000, 999999999),
            'item_id' => $targetItemId,
            'item_code' => 'WB001',
            'item_name' => '差異データ対象商品',
            'location_id' => 1,
            'location_code1' => 'A',
            'location_code2' => '01',
            'location_code3' => '01',
            'location_no' => 'A0-01-01',
            'system_quantity' => 10,
            'ending_system_quantity' => 12,
            'first_count_quantity' => 7,
            'second_count_quantity' => 11,
            'final_count_quantity' => 5,
            'first_count_confirmed_system_quantity' => 10,
            'first_count_confirmed_difference_quantity' => -3,
            'first_count_confirmed_difference_amount' => -30,
            'second_count_confirmed_system_quantity' => 12,
            'second_count_confirmed_difference_quantity' => -1,
            'second_count_confirmed_difference_amount' => -10,
            'cost_price' => 10,
            'difference_quantity' => -1,
            'input_count' => 2,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => random_int(900000000, 999999999),
            'item_id' => $matchedItemId,
            'item_code' => 'WB002',
            'item_name' => '差異なし入力済み商品',
            'location_id' => 2,
            'location_code1' => 'A',
            'location_code2' => '01',
            'location_code3' => '02',
            'location_no' => 'A0-01-02',
            'system_quantity' => 12,
            'ending_system_quantity' => 12,
            'first_count_quantity' => 12,
            'second_count_quantity' => 12,
            'final_count_quantity' => 12,
            'first_count_confirmed_system_quantity' => 12,
            'first_count_confirmed_difference_quantity' => 0,
            'first_count_confirmed_difference_amount' => 0,
            'second_count_confirmed_system_quantity' => 12,
            'second_count_confirmed_difference_quantity' => 0,
            'second_count_confirmed_difference_amount' => 0,
            'cost_price' => 10,
            'input_count' => 3,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => random_int(900000000, 999999999),
            'item_id' => $zeroSystemItemId,
            'item_code' => 'WB003',
            'item_name' => '理論ゼロ差異ゼロ未入力商品',
            'location_id' => 3,
            'location_code1' => 'A',
            'location_code2' => '01',
            'location_code3' => '03',
            'location_no' => 'A0-01-03',
            'system_quantity' => 0,
            'ending_system_quantity' => 0,
            'cost_price' => 10,
            'input_count' => 0,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => random_int(900000000, 999999999),
            'item_id' => $excludedCategoryItemId,
            'item_code' => 'WB004',
            'item_name' => '未棚対象外大分類商品',
            'location_id' => 4,
            'location_code1' => 'A',
            'location_code2' => '01',
            'location_code3' => '04',
            'location_no' => 'A0-01-04',
            'system_quantity' => 5,
            'ending_system_quantity' => 5,
            'cost_price' => 10,
            'input_count' => 0,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => random_int(900000000, 999999999),
            'item_id' => $targetItemId,
            'item_code' => 'WB005',
            'item_name' => '終了理論未取得商品',
            'location_id' => 5,
            'location_code1' => 'A',
            'location_code2' => '01',
            'location_code3' => '05',
            'location_no' => 'A0-01-05',
            'system_quantity' => 10,
            'ending_system_quantity' => null,
            'first_count_quantity' => 1,
            'first_count_confirmed_system_quantity' => 10,
            'first_count_confirmed_difference_quantity' => -9,
            'first_count_confirmed_difference_amount' => -90,
            'cost_price' => 10,
            'input_count' => 1,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => random_int(900000000, 999999999),
            'item_id' => $targetItemId,
            'item_code' => 'WB006',
            'item_name' => '3回目未確定差異商品',
            'location_id' => 6,
            'location_code1' => 'A',
            'location_code2' => '01',
            'location_code3' => '06',
            'location_no' => 'A0-01-06',
            'system_quantity' => 12,
            'ending_system_quantity' => 12,
            'first_count_quantity' => 12,
            'second_count_quantity' => 12,
            'final_count_quantity' => 5,
            'first_count_confirmed_system_quantity' => 12,
            'first_count_confirmed_difference_quantity' => 0,
            'first_count_confirmed_difference_amount' => 0,
            'second_count_confirmed_system_quantity' => 12,
            'second_count_confirmed_difference_quantity' => 0,
            'second_count_confirmed_difference_amount' => 0,
            'cost_price' => 10,
            'input_count' => 3,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => random_int(900000000, 999999999),
            'item_id' => $ownedSetItemId,
            'item_code' => 'WBOWN001',
            'item_name' => '自社セット対象外商品',
            'system_quantity' => 10,
            'ending_system_quantity' => 8,
            'first_count_quantity' => 10,
            'first_count_confirmed_system_quantity' => 8,
            'first_count_confirmed_difference_quantity' => 2,
            'first_count_confirmed_difference_amount' => 20,
            'cost_price' => 10,
            'difference_quantity' => 2,
            'input_count' => 1,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => random_int(900000000, 999999999),
            'item_id' => $nonManagedItemId,
            'item_code' => 'WB007',
            'item_name' => '非管理品販売価格評価商品',
            'location_id' => 7,
            'location_code1' => 'A',
            'location_code2' => '01',
            'location_code3' => '07',
            'location_no' => 'A0-01-07',
            'system_quantity' => 13,
            'ending_system_quantity' => 13,
            'first_count_quantity' => 13,
            'second_count_quantity' => 13,
            'first_count_confirmed_system_quantity' => 13,
            'first_count_confirmed_difference_quantity' => 0,
            'first_count_confirmed_difference_amount' => 0,
            'second_count_confirmed_system_quantity' => 13,
            'second_count_confirmed_difference_quantity' => 0,
            'second_count_confirmed_difference_amount' => 0,
            'cost_price' => 999,
            'input_count' => 2,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => random_int(900000000, 999999999),
            'item_id' => $targetItemId,
            'item_code' => 'WB008',
            'item_name' => '未棚差異金額対象商品',
            'location_id' => 8,
            'location_code1' => 'A',
            'location_code2' => '01',
            'location_code3' => '08',
            'location_no' => 'A0-01-08',
            'system_quantity' => 6,
            'ending_system_quantity' => 6,
            'cost_price' => 10,
            'input_count' => 0,
        ]);

        $workbook = $this->loadWorkbook((new InventoryDifferenceWorkbookService)->generate($inventoryCount));

        $this->assertSame(['部門別', '部門別(絶対値)', '社長用', '集計', '差異', '未棚', '１回目', '２回目', '３回目'], $workbook->getSheetNames());

        $departmentSheet = $workbook->getSheetByName('部門別');
        $this->assertSame('26.8月実施　在庫差異状況一覧＜+　-＞', $departmentSheet->getCell('B1')->getValue());
        $this->assertSame('調査(棚卸直後)', $departmentSheet->getCell('E2')->getValue());
        $this->assertSame('調査(数えミス調査後)', $departmentSheet->getCell('G2')->getValue());
        $this->assertSame('8/20終了時点', $departmentSheet->getCell('N2')->getValue());
        $this->assertSame('ｱｲﾃﾑ数', $departmentSheet->getCell('K2')->getValue());
        $this->assertSheetHasMerge($departmentSheet, 'E2:F2');
        $this->assertSheetHasMerge($departmentSheet, 'G2:H2');
        $this->assertSheetHasMerge($departmentSheet, 'I2:J2');
        $this->assertSheetHasMerge($departmentSheet, 'K2:M2');
        $this->assertEqualsWithDelta(24.99, $departmentSheet->getColumnDimension('D')->getWidth(), 0.01);
        $this->assertEqualsWithDelta(19.49, $departmentSheet->getColumnDimension('I')->getWidth(), 0.01);
        $this->assertEqualsWithDelta(19.49, $departmentSheet->getColumnDimension('J')->getWidth(), 0.01);
        $this->assertSame('１：酒類', $departmentSheet->getCell('C4')->getValue());
        $this->assertEquals($expectedMajorStockAmount, $departmentSheet->getCell('D4')->getValue());
        $this->assertEquals(-18 * $expectedCostPrice, $departmentSheet->getCell('E4')->getValue());
        $this->assertEqualsWithDelta((-18 * $expectedCostPrice) / $expectedMajorStockAmount, $departmentSheet->getCell('F4')->getValue(), 0.0000001);
        $this->assertEquals(-17 * $expectedCostPrice, $departmentSheet->getCell('G4')->getValue());
        $this->assertSame(3, $departmentSheet->getCell('K4')->getValue());
        $this->assertSame(4, $departmentSheet->getCell('L4')->getValue());
        $this->assertEqualsWithDelta(3 / 4, $departmentSheet->getCell('M4')->getValue(), 0.0000001);
        $this->assertEquals(-17 * $expectedCostPrice, $departmentSheet->getCell('N4')->getValue());
        $this->assertSame('合計', $departmentSheet->getCell('C8')->getValue());
        $this->assertSame(6, $departmentSheet->getCell('L8')->getValue());
        $this->assertEquals($expectedMajorStockAmount, $departmentSheet->getCell('D8')->getValue());

        $absoluteDepartmentSheet = $workbook->getSheetByName('部門別(絶対値)');
        $this->assertSame('26.8月実施　在庫差異状況一覧＜絶対値＞', $absoluteDepartmentSheet->getCell('B1')->getValue());
        $this->assertEquals(18 * $expectedCostPrice, $absoluteDepartmentSheet->getCell('E4')->getValue());
        $this->assertEquals(17 * $expectedCostPrice, $absoluteDepartmentSheet->getCell('G4')->getValue());
        $this->assertEquals(17 * $expectedCostPrice, $absoluteDepartmentSheet->getCell('N4')->getValue());

        $executiveSheet = $workbook->getSheetByName('社長用');
        $this->assertSame('26.8月実施　在庫差異状況一覧', $executiveSheet->getCell('A1')->getValue());
        $this->assertSame('8/20終了時点', $executiveSheet->getCell('E2')->getValue());
        $this->assertSame('プラスマイナス差異', $executiveSheet->getCell('E3')->getValue());
        $this->assertSame('絶対値差異', $executiveSheet->getCell('G3')->getValue());
        $this->assertSame('CP在庫金額', $executiveSheet->getCell('D4')->getValue());
        $this->assertSheetHasMerge($executiveSheet, 'E2:H2');
        $this->assertSheetHasMerge($executiveSheet, 'E3:F3');
        $this->assertSheetHasMerge($executiveSheet, 'G3:H3');
        $this->assertSame('１：酒類', $executiveSheet->getCell('C5')->getValue());
        $this->assertEquals($expectedMajorStockAmount, $executiveSheet->getCell('D5')->getValue());
        $this->assertEquals(-17 * $expectedCostPrice, $executiveSheet->getCell('E5')->getValue());
        $this->assertEquals(17 * $expectedCostPrice, $executiveSheet->getCell('G5')->getValue());
        $this->assertSame('合計', $executiveSheet->getCell('C9')->getValue());
        $this->assertEquals($expectedMajorStockAmount, $executiveSheet->getCell('D9')->getValue());
        $this->assertEquals(-17 * $expectedCostPrice, $executiveSheet->getCell('E9')->getValue());
        $this->assertEquals(17 * $expectedCostPrice, $executiveSheet->getCell('G9')->getValue());

        $summaryRows = $this->rowsByColumn($workbook->getSheetByName('集計'), '区分');
        $this->assertArrayHasKey('全体', $summaryRows);
        $this->assertArrayHasKey('差異あり', $summaryRows);
        $this->assertArrayHasKey('未棚', $summaryRows);
        $this->assertEquals((40 * $expectedCostPrice) + $expectedNonManagedStockAmount, $summaryRows['全体']['CP在庫金額']);
        $this->assertEquals(28 * $expectedCostPrice, $summaryRows['差異あり']['CP在庫金額']);
        $this->assertEquals(16 * $expectedCostPrice, $summaryRows['未棚']['CP在庫金額']);
        $this->assertEquals(-18 * $expectedCostPrice, $summaryRows['全体']['1回目±差異金額']);
        $this->assertEquals(18 * $expectedCostPrice, $summaryRows['全体']['1回目絶対差異金額']);
        $this->assertEquals(-17 * $expectedCostPrice, $summaryRows['全体']['2回目±差異金額']);
        $this->assertEquals(17 * $expectedCostPrice, $summaryRows['全体']['2回目絶対差異金額']);

        $diffHeaders = $this->sheetHeaders($workbook->getSheetByName('差異'));
        $this->assertSame('棚卸しNo', $diffHeaders[0]);
        $this->assertNotContains('差異回', $diffHeaders);
        $this->assertNotContains('最大絶対差異', $diffHeaders);
        $this->assertNotContains('グループ', $diffHeaders);
        $this->assertNotContains('ロケ', $diffHeaders);
        $this->assertNotContains('ロットNO', $diffHeaders);
        $this->assertNotContains('賞味期限', $diffHeaders);
        $this->assertContains('大分類CD', $diffHeaders);
        $this->assertContains('大分類名', $diffHeaders);
        $this->assertContains('原価', $diffHeaders);
        $this->assertContains('CP在庫金額', $diffHeaders);
        $this->assertContains('1回目±差異金額', $diffHeaders);
        $this->assertContains('1回目絶対差異金額', $diffHeaders);

        $diffRows = $this->rowsByItemCode($workbook->getSheetByName('差異'));
        $this->assertArrayHasKey('WB001', $diffRows);
        $this->assertArrayHasKey('WB005', $diffRows);
        $this->assertArrayHasKey('WB008', $diffRows);
        $this->assertArrayNotHasKey('WB002', $diffRows);
        $this->assertArrayNotHasKey('WB006', $diffRows);
        $this->assertArrayNotHasKey('WB007', $diffRows);
        $this->assertArrayNotHasKey('WBOWN001', $diffRows);

        $this->assertSame(12, $diffRows['WB001']['理論在庫']);
        $this->assertSame('1001', $diffRows['WB001']['大分類CD']);
        $this->assertSame('差異データ大分類1001', $diffRows['WB001']['大分類名']);
        $this->assertEquals($expectedCostPrice, $diffRows['WB001']['原価']);
        $this->assertEquals(12 * $expectedCostPrice, $diffRows['WB001']['CP在庫金額']);
        $this->assertSame(7, $diffRows['WB001']['1回目数量']);
        $this->assertSame(-3, $diffRows['WB001']['1回目±差異']);
        $this->assertSame(3, $diffRows['WB001']['1回目絶対差異']);
        $this->assertEquals(-3 * $expectedCostPrice, $diffRows['WB001']['1回目±差異金額']);
        $this->assertEquals(3 * $expectedCostPrice, $diffRows['WB001']['1回目絶対差異金額']);
        $this->assertSame(11, $diffRows['WB001']['2回目数量']);
        $this->assertSame(-1, $diffRows['WB001']['2回目±差異']);
        $this->assertSame(1, $diffRows['WB001']['2回目絶対差異']);
        $this->assertEquals(-1 * $expectedCostPrice, $diffRows['WB001']['2回目±差異金額']);
        $this->assertEquals($expectedCostPrice, $diffRows['WB001']['2回目絶対差異金額']);
        $this->assertNull($diffRows['WB001']['3回目数量']);
        $this->assertNull($diffRows['WB001']['3回目±差異']);
        $this->assertNull($diffRows['WB001']['3回目±差異金額']);
        $this->assertSame(10, $diffRows['WB005']['理論在庫']);
        $this->assertEquals(10 * $expectedCostPrice, $diffRows['WB005']['CP在庫金額']);
        $this->assertSame(1, $diffRows['WB005']['1回目数量']);
        $this->assertSame(-9, $diffRows['WB005']['1回目±差異']);
        $this->assertEquals(-9 * $expectedCostPrice, $diffRows['WB005']['1回目±差異金額']);
        $this->assertSame(0, $diffRows['WB005']['2回目数量']);
        $this->assertSame(-10, $diffRows['WB005']['2回目±差異']);
        $this->assertEquals(-10 * $expectedCostPrice, $diffRows['WB005']['2回目±差異金額']);
        $this->assertSame(0, $diffRows['WB008']['1回目数量']);
        $this->assertSame(-6, $diffRows['WB008']['1回目±差異']);
        $this->assertEquals(-6 * $expectedCostPrice, $diffRows['WB008']['1回目±差異金額']);
        $this->assertSame(0, $diffRows['WB008']['2回目数量']);
        $this->assertSame(-6, $diffRows['WB008']['2回目±差異']);
        $this->assertEquals(-6 * $expectedCostPrice, $diffRows['WB008']['2回目±差異金額']);

        $uncountedHeaders = $this->sheetHeaders($workbook->getSheetByName('未棚'));
        $this->assertSame('未入力回', $uncountedHeaders[0]);
        $this->assertNotContains('グループ', $uncountedHeaders);
        $this->assertNotContains('ロケ', $uncountedHeaders);
        $this->assertNotContains('ロットNO', $uncountedHeaders);
        $this->assertNotContains('賞味期限', $uncountedHeaders);
        $this->assertContains('大分類CD', $uncountedHeaders);
        $this->assertContains('大分類名', $uncountedHeaders);
        $this->assertContains('原価', $uncountedHeaders);
        $this->assertContains('CP在庫金額', $uncountedHeaders);

        $uncountedRows = $this->rowsByItemCode($workbook->getSheetByName('未棚'));
        $this->assertArrayNotHasKey('WB001', $uncountedRows);
        $this->assertArrayNotHasKey('WB002', $uncountedRows);
        $this->assertArrayNotHasKey('WB003', $uncountedRows);
        $this->assertArrayNotHasKey('WB004', $uncountedRows);
        $this->assertArrayHasKey('WB005', $uncountedRows);
        $this->assertArrayHasKey('WB008', $uncountedRows);
        $this->assertArrayNotHasKey('WB006', $uncountedRows);
        $this->assertArrayNotHasKey('WB007', $uncountedRows);
        $this->assertArrayNotHasKey('WBOWN001', $uncountedRows);
        $this->assertSame('2回目', $uncountedRows['WB005']['未入力回']);
        $this->assertSame('1001', $uncountedRows['WB005']['大分類CD']);
        $this->assertSame('差異データ大分類1001', $uncountedRows['WB005']['大分類名']);
        $this->assertEquals($expectedCostPrice, $uncountedRows['WB005']['原価']);
        $this->assertEquals(10 * $expectedCostPrice, $uncountedRows['WB005']['CP在庫金額']);
        $this->assertSame(0, $uncountedRows['WB005']['2回目数量']);
        $this->assertSame(-10, $uncountedRows['WB005']['2回目±差異']);
        $this->assertEquals(-10 * $expectedCostPrice, $uncountedRows['WB005']['2回目±差異金額']);
        $this->assertSame('2回目', $uncountedRows['WB008']['未入力回']);
        $this->assertSame(0, $uncountedRows['WB008']['2回目数量']);
        $this->assertSame(-6, $uncountedRows['WB008']['2回目±差異']);
        $this->assertEquals(-6 * $expectedCostPrice, $uncountedRows['WB008']['2回目±差異金額']);

        $firstTopRows = $this->rowsByColumn($workbook->getSheetByName('１回目'), '単品ＣＤ');
        $this->assertSame(['店舗ＣＤ', '単品ＣＤ', '表示正式名称', '差異数', '絶対値差異', '＋-差異'], $this->sheetHeaders($workbook->getSheetByName('１回目')));
        $this->assertSame('WB005', array_key_first($firstTopRows));
        $this->assertSame(-9, $firstTopRows['WB005']['差異数']);
        $this->assertEquals(9 * $expectedCostPrice, $firstTopRows['WB005']['絶対値差異']);
        $this->assertEquals(-9 * $expectedCostPrice, $firstTopRows['WB005']['＋-差異']);
        $this->assertSame(-6, $firstTopRows['WB008']['差異数']);
        $this->assertEquals(6 * $expectedCostPrice, $firstTopRows['WB008']['絶対値差異']);
        $this->assertEquals(-6 * $expectedCostPrice, $firstTopRows['WB008']['＋-差異']);
        $this->assertArrayNotHasKey('WB007', $firstTopRows);

        $secondTopRows = $this->rowsByColumn($workbook->getSheetByName('２回目'), '単品ＣＤ');
        $this->assertSame('WB005', array_key_first($secondTopRows));
        $this->assertSame(-10, $secondTopRows['WB005']['差異数']);
        $this->assertSame(-6, $secondTopRows['WB008']['差異数']);
        $this->assertArrayHasKey('WB001', $secondTopRows);

        $thirdTopRows = $this->rowsByColumn($workbook->getSheetByName('３回目'), '単品ＣＤ');
        $this->assertSame([], $thirdTopRows);
    }

    public function test_difference_workbook_exports_selected_unconfirmed_round(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-20 10:00:00'));

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 987654323,
            'warehouse_code' => '987654323',
            'warehouse_name' => '未確定回差異データテスト倉庫',
            'count_date' => '2026-08-19',
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'current_count_round' => 2,
            'first_count_confirmed_at' => now()->subHour(),
            'ending_stock_taken_at' => now(),
        ]);

        $itemId = $this->createItemInMajorCategory(1001);
        $this->createItemPrice($itemId, '2026-08-20', 10);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => random_int(900000000, 999999999),
            'item_id' => $itemId,
            'item_code' => 'WBSEL001',
            'item_name' => '未確定2回目入力あり商品',
            'system_quantity' => 10,
            'ending_system_quantity' => 10,
            'first_count_quantity' => 7,
            'second_count_quantity' => 8,
            'first_count_confirmed_system_quantity' => 10,
            'first_count_confirmed_difference_quantity' => -3,
            'first_count_confirmed_difference_amount' => -30,
            'cost_price' => 10,
            'input_count' => 2,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => random_int(900000000, 999999999),
            'item_id' => $itemId,
            'item_code' => 'WBSEL002',
            'item_name' => '未確定2回目未棚商品',
            'system_quantity' => 5,
            'ending_system_quantity' => 5,
            'first_count_quantity' => 5,
            'second_count_quantity' => null,
            'first_count_confirmed_system_quantity' => 5,
            'first_count_confirmed_difference_quantity' => 0,
            'first_count_confirmed_difference_amount' => 0,
            'cost_price' => 10,
            'input_count' => 1,
        ]);

        $secondRoundWorkbook = $this->loadWorkbook((new InventoryDifferenceWorkbookService)->generate($inventoryCount, 2));
        $secondRoundDiffRows = $this->rowsByItemCode($secondRoundWorkbook->getSheetByName('差異'));

        $this->assertArrayHasKey('WBSEL001', $secondRoundDiffRows);
        $this->assertArrayHasKey('WBSEL002', $secondRoundDiffRows);
        $this->assertSame(-3, $secondRoundDiffRows['WBSEL001']['1回目±差異']);
        $this->assertSame(8, $secondRoundDiffRows['WBSEL001']['2回目数量']);
        $this->assertSame(-2, $secondRoundDiffRows['WBSEL001']['2回目±差異']);
        $this->assertSame(0, $secondRoundDiffRows['WBSEL002']['2回目数量']);
        $this->assertSame(-5, $secondRoundDiffRows['WBSEL002']['2回目±差異']);
        $this->assertNull($secondRoundDiffRows['WBSEL001']['3回目±差異']);

        $uncountedRows = $this->rowsByItemCode($secondRoundWorkbook->getSheetByName('未棚'));
        $this->assertArrayHasKey('WBSEL002', $uncountedRows);
        $this->assertSame('2回目', $uncountedRows['WBSEL002']['未入力回']);

        $secondTopRows = $this->rowsByColumn($secondRoundWorkbook->getSheetByName('２回目'), '単品ＣＤ');
        $this->assertArrayHasKey('WBSEL001', $secondTopRows);
        $this->assertArrayHasKey('WBSEL002', $secondTopRows);

        $firstRoundWorkbook = $this->loadWorkbook((new InventoryDifferenceWorkbookService)->generate($inventoryCount, 1));
        $firstRoundDiffRows = $this->rowsByItemCode($firstRoundWorkbook->getSheetByName('差異'));

        $this->assertArrayHasKey('WBSEL001', $firstRoundDiffRows);
        $this->assertArrayNotHasKey('WBSEL002', $firstRoundDiffRows);
        $this->assertNull($firstRoundDiffRows['WBSEL001']['2回目±差異']);
    }

    public function test_difference_workbook_exports_empty_sheets(): void
    {
        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 987654322,
            'warehouse_code' => '987654322',
            'warehouse_name' => '空差異データテスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'current_count_round' => 1,
        ]);

        $workbook = $this->loadWorkbook((new InventoryDifferenceWorkbookService)->generate($inventoryCount));

        $this->assertSame(['部門別', '部門別(絶対値)', '社長用', '集計', '差異', '未棚', '１回目', '２回目', '３回目'], $workbook->getSheetNames());
        $this->assertSame(8, $workbook->getSheetByName('部門別')->getHighestRow());
        $this->assertSame(8, $workbook->getSheetByName('部門別(絶対値)')->getHighestRow());
        $this->assertSame(9, $workbook->getSheetByName('社長用')->getHighestRow());
        $this->assertSame(4, $workbook->getSheetByName('集計')->getHighestRow());
        $this->assertSame(1, $workbook->getSheetByName('差異')->getHighestRow());
        $this->assertSame(1, $workbook->getSheetByName('未棚')->getHighestRow());
        $this->assertSame(1, $workbook->getSheetByName('１回目')->getHighestRow());
        $this->assertSame(1, $workbook->getSheetByName('２回目')->getHighestRow());
        $this->assertSame(1, $workbook->getSheetByName('３回目')->getHighestRow());
    }

    private function loadWorkbook(string $content): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'wms-inventory-diff-xlsx-');

        try {
            file_put_contents($path, $content);

            return IOFactory::load($path);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function sheetHeaders(?Worksheet $sheet): array
    {
        $this->assertNotNull($sheet);

        $rows = $sheet->toArray(null, true, false, false);

        return array_values(array_filter($rows[0] ?? [], fn ($value): bool => $value !== null && $value !== ''));
    }

    private function assertSheetHasMerge(Worksheet $sheet, string $range): void
    {
        $this->assertArrayHasKey($range, $sheet->getMergeCells());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rowsByItemCode(?Worksheet $sheet): array
    {
        return $this->rowsByColumn($sheet, '商品CD');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rowsByColumn(?Worksheet $sheet, string $keyColumn): array
    {
        $this->assertNotNull($sheet);

        $rawRows = $sheet->toArray(null, true, false, true);
        $headers = array_shift($rawRows);
        $rows = [];

        foreach ($rawRows as $rawRow) {
            if (! collect($rawRow)->filter(fn ($value): bool => $value !== null && $value !== '')->isNotEmpty()) {
                continue;
            }

            $row = [];
            foreach ($headers as $column => $label) {
                $row[$label] = $rawRow[$column] ?? null;
            }

            $rows[(string) $row[$keyColumn]] = $row;
        }

        return $rows;
    }

    private function createItemInMajorCategory(
        int $majorCategoryCode,
        bool $ownedSet = false,
        bool $managedStock = true,
        ?float $majorCategoryCostRate = null,
    ): int {
        $majorCategoryData = [
            'client_id' => 1,
            'name' => '差異データ大分類'.$majorCategoryCode,
            'code' => $majorCategoryCode,
            'depth' => 1,
            'creator_id' => 1,
            'last_updater_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::connection('sakemaru')->hasColumn('item_categories', 'cost_rate')) {
            $majorCategoryData['cost_rate'] = $majorCategoryCostRate;
        }

        $majorCategoryId = DB::connection('sakemaru')->table('item_categories')->insertGetId($majorCategoryData);

        $middleCategoryId = DB::connection('sakemaru')->table('item_categories')->insertGetId([
            'client_id' => 1,
            'name' => '差異データ中分類'.$majorCategoryCode,
            'parent_id' => $majorCategoryId,
            'code' => random_int(100000, 999999),
            'depth' => 2,
            'creator_id' => 1,
            'last_updater_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemSetId = $ownedSet ? $this->createOwnedItemSet() : null;

        $itemData = [
            'name_main' => '差異データ対象商品'.Str::upper(Str::random(8)),
            'code' => random_int(800000000, 899999999),
            'type' => 'NOT_ALCOHOL',
            'manufacturer_id' => 0,
            'volume' => 1,
            'capacity_case' => 1,
            'creator_id' => 1,
            'packaging' => '1',
            'nickname' => '差異データ対象',
            'client_id' => 1,
            'item_set_id' => $itemSetId,
            'item_category1_id' => $majorCategoryId,
            'item_category2_id' => $middleCategoryId,
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

        return DB::connection('sakemaru')->table('items')->insertGetId($itemData);
    }

    private function createOwnedItemSet(): int
    {
        return (int) DB::connection('sakemaru')->table('item_sets')->insertGetId([
            'description' => '棚卸対象外自社セット',
            'set_type' => 'OWNED',
            'is_active' => true,
            'client_id' => 1,
            'creator_id' => 1,
            'last_updater_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createItemPrice(int $itemId, string $startDate, float $costUnitPrice, bool $isActive = true, float $saleUnitPrice = 0): int
    {
        return (int) DB::connection('sakemaru')->table('item_prices')->insertGetId([
            'item_id' => $itemId,
            'start_date' => $startDate,
            'producer_unit_price' => 0,
            'producer_case_price' => 0,
            'producer_crate_price' => 0,
            'cost_unit_price' => $costUnitPrice,
            'wholesale_unit_price' => 0,
            'sale_unit_price' => $saleUnitPrice,
            'sub_unit_price' => 0,
            'retail_unit_price' => 0,
            'tax_exempt_unit_price' => 0,
            'type' => 'EXEMPT',
            'client_id' => 1,
            'creator_id' => 0,
            'is_active' => $isActive,
            'created_at' => now(),
            'updated_at' => now(),
            'is_created_from_data_transfer' => false,
            'last_updater_id' => 0,
        ]);
    }

    private function createReportTrade(
        string $tradeCategory,
        int $itemId,
        int $warehouseId,
        string $processDate,
        float $amount,
    ): void {
        $tradeId = (int) DB::connection('sakemaru')->table('trades')->insertGetId([
            'client_id' => 1,
            'creator_id' => 1,
            'last_updater_id' => 1,
            'trade_category' => $tradeCategory,
            'uuid' => (string) Str::uuid(),
            'serial_id' => random_int(900000000, 999999999),
            'subtotal' => $amount,
            'total' => $amount,
            'process_date' => $processDate,
            'rebate_date' => $processDate,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($tradeCategory === 'PURCHASE') {
            DB::connection('sakemaru')->table('purchases')->insert([
                'trade_id' => $tradeId,
                'client_id' => 1,
                'supplier_id' => 1,
                'warehouse_id' => $warehouseId,
                'delivered_date' => $processDate,
                'account_date' => $processDate,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } elseif ($tradeCategory === 'EARNING') {
            DB::connection('sakemaru')->table('earnings')->insert([
                'trade_id' => $tradeId,
                'client_id' => 1,
                'buyer_id' => 1,
                'warehouse_id' => $warehouseId,
                'delivered_date' => $processDate,
                'account_date' => $processDate,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::connection('sakemaru')->table('trade_items')->insert([
            'client_id' => 1,
            'trade_id' => $tradeId,
            'item_id' => $itemId,
            'item_name' => '非管理品テスト商品',
            'stock_allocation_id' => 0,
            'order_quantity_type' => 'PIECE',
            'quantity' => 1,
            'quantity_type' => 'PIECE',
            'capacity_case' => 1,
            'capacity_carton' => 1,
            'price' => $amount,
            'price_category' => 'OTHER',
            'amount' => $amount,
            'tax_excluded_amount' => $amount,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
