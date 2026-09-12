<?php

namespace Tests\Unit\Services;

use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use App\Models\WmsInventoryCountItemLog;
use App\Services\InventoryCount\InventoryEnteredListWorkbookService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class InventoryEnteredListWorkbookServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['sakemaru'];

    public function test_entered_list_exports_only_real_input_logs_for_selected_round(): void
    {
        if (! Schema::connection('sakemaru')->hasColumn('items', 'is_managed_stock')) {
            $this->markTestSkipped('items.is_managed_stock is not available.');
        }

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => '入力済Excelテスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'current_count_round' => 1,
        ]);

        $targetItemId = $this->createItemInMajorCategory(1001);
        $manualZeroItemId = $this->createItemInMajorCategory(1002);
        $excludedCategoryItemId = $this->createItemInMajorCategory(9999);
        $unmanagedItemId = $this->createItemInMajorCategory(1001, false);

        $entered = $this->createCountItem($inventoryCount, $targetItemId, 'ENT001', '実入力あり', 10, 9, 8, 1);
        $manualZero = $this->createCountItem($inventoryCount, $manualZeroItemId, 'ENTZERO', '手入力ゼロ', 3, 3, 0, 1);
        $roundTwoOnly = $this->createCountItem($inventoryCount, $targetItemId, 'ENTR2', '2回目のみ入力', 10, 7, null, 1, 4);
        $autoZero = $this->createCountItem($inventoryCount, $targetItemId, 'AUTOZERO', '未0自動入力', 5, 5, 0, 1);
        $uncounted = $this->createCountItem($inventoryCount, $targetItemId, 'NOINPUT', '未入力', 5, 5, null, 0);
        $unmanaged = $this->createCountItem($inventoryCount, $unmanagedItemId, 'UNMANAGED', '在庫管理対象外', 5, 5, 2, 1);
        $excludedCategory = $this->createCountItem($inventoryCount, $excludedCategoryItemId, 'OUTCAT', '対象外大分類', 5, 5, 2, 1);

        $this->createLog($entered, 1, WmsInventoryCountItemLog::DEVICE_WEB, 8);
        $this->createLog($manualZero, 1, 'HANDY-001', 0);
        $this->createLog($roundTwoOnly, 2, WmsInventoryCountItemLog::DEVICE_WEB, 4);
        $this->createLog($autoZero, 1, WmsInventoryCountItemLog::DEVICE_WEB_AUTO_ZERO, 0);
        $this->createLog($unmanaged, 1, WmsInventoryCountItemLog::DEVICE_WEB, 2);
        $this->createLog($excludedCategory, 1, WmsInventoryCountItemLog::DEVICE_WEB, 2);

        $workbook = $this->loadWorkbook((new InventoryEnteredListWorkbookService)->generate($inventoryCount, 1));
        $sheet = $workbook->getActiveSheet();

        $this->assertSame('入力済', $sheet->getTitle());
        $this->assertSame([
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
        ], $sheet->rangeToArray('A1:M1')[0]);

        $rows = $this->rowsByItemCode($sheet);

        $this->assertEqualsCanonicalizing(['ENT001', 'ENTZERO'], array_keys($rows));
        $this->assertSame(9, (int) $rows['ENT001']['終了理論']);
        $this->assertSame(8, (int) $rows['ENT001']['実数量']);
        $this->assertSame(-1, (int) $rows['ENT001']['終了差異']);
        $this->assertEquals(10.00, $rows['ENT001']['バラ原価']);
        $this->assertEquals(90.00, $rows['ENT001']['理論合計']);
        $this->assertEquals(80.00, $rows['ENT001']['実績合計']);
        $this->assertEquals(-10.00, $rows['ENT001']['理論と実績差分合計']);
        $this->assertSame(0, (int) $rows['ENTZERO']['実数量']);
        $this->assertSame(-3, (int) $rows['ENTZERO']['終了差異']);
        $this->assertArrayNotHasKey($roundTwoOnly->item_code, $rows);
        $this->assertArrayNotHasKey($autoZero->item_code, $rows);
        $this->assertArrayNotHasKey($uncounted->item_code, $rows);

        $roundTwoWorkbook = $this->loadWorkbook((new InventoryEnteredListWorkbookService)->generate($inventoryCount, 2));
        $roundTwoRows = $this->rowsByItemCode($roundTwoWorkbook->getActiveSheet());

        $this->assertEqualsCanonicalizing(['ENTR2'], array_keys($roundTwoRows));
        $this->assertSame(7, (int) $roundTwoRows['ENTR2']['終了理論']);
        $this->assertSame(4, (int) $roundTwoRows['ENTR2']['実数量']);
        $this->assertSame(-3, (int) $roundTwoRows['ENTR2']['終了差異']);
        $this->assertEquals(-30.00, $roundTwoRows['ENTR2']['理論と実績差分合計']);
    }

    private function createItemInMajorCategory(int $majorCategoryCode, bool $managedStock = true): int
    {
        $majorCategoryId = DB::connection('sakemaru')->table('item_categories')->insertGetId([
            'client_id' => 1,
            'name' => '入力済Excel大分類'.$majorCategoryCode,
            'code' => $majorCategoryCode,
            'depth' => 1,
            'creator_id' => 1,
            'last_updater_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $middleCategoryId = DB::connection('sakemaru')->table('item_categories')->insertGetId([
            'client_id' => 1,
            'name' => '入力済Excel中分類'.$majorCategoryCode,
            'parent_id' => $majorCategoryId,
            'code' => random_int(100000, 999999),
            'depth' => 2,
            'creator_id' => 1,
            'last_updater_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemData = [
            'name_main' => '入力済Excel対象商品'.Str::upper(Str::random(8)),
            'code' => random_int(800000000, 899999999),
            'type' => 'NOT_ALCOHOL',
            'manufacturer_id' => 0,
            'volume' => 1,
            'capacity_case' => 1,
            'creator_id' => 1,
            'packaging' => '1',
            'nickname' => '入力済Excel対象',
            'client_id' => 1,
            'item_set_id' => null,
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

        return (int) DB::connection('sakemaru')->table('items')->insertGetId($itemData);
    }

    private function createCountItem(
        WmsInventoryCount $inventoryCount,
        int $itemId,
        string $itemCode,
        string $itemName,
        int $systemQuantity,
        int $endingSystemQuantity,
        ?int $firstCountQuantity,
        int $inputCount,
        ?int $secondCountQuantity = null,
        ?int $finalCountQuantity = null,
        float $costPrice = 10,
    ): WmsInventoryCountItem {
        return WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => random_int(900000000, 999999999),
            'item_id' => $itemId,
            'item_code' => $itemCode,
            'item_name' => $itemName,
            'location_id' => random_int(100000, 999999),
            'location_code1' => 'A',
            'location_code2' => '01',
            'location_code3' => $itemCode,
            'location_no' => 'A0-'.$itemCode,
            'system_quantity' => $systemQuantity,
            'ending_system_quantity' => $endingSystemQuantity,
            'first_count_quantity' => $firstCountQuantity,
            'second_count_quantity' => $secondCountQuantity,
            'final_count_quantity' => $finalCountQuantity,
            'cost_price' => $costPrice,
            'input_count' => $inputCount,
        ]);
    }

    private function createLog(WmsInventoryCountItem $item, int $round, ?string $deviceId, int $newQuantity): void
    {
        WmsInventoryCountItemLog::create([
            'inventory_count_item_id' => $item->id,
            'device_id' => $deviceId,
            'user_id' => null,
            'count_round' => $round,
            'old_quantity' => null,
            'new_quantity' => $newQuantity,
            'request_uuid' => (string) Str::uuid(),
            'created_at' => now(),
        ]);
    }

    private function loadWorkbook(string $content): Spreadsheet
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'wms-entered-test-');
        file_put_contents($tempPath, $content);

        try {
            return IOFactory::load($tempPath);
        } finally {
            if (is_file($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rowsByItemCode($sheet): array
    {
        $rows = [];
        $headers = $sheet->rangeToArray('A1:M1')[0];

        for ($rowIndex = 2; $rowIndex <= $sheet->getHighestDataRow(); $rowIndex++) {
            $rowValues = $sheet->rangeToArray("A{$rowIndex}:M{$rowIndex}")[0];
            $row = array_combine($headers, $rowValues);
            $itemCode = (string) ($row['アイテムコード'] ?? '');

            if ($itemCode !== '') {
                $rows[$itemCode] = $row;
            }
        }

        return $rows;
    }
}
