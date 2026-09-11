<?php

namespace Tests\Unit\Services;

use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use App\Services\InventoryCount\InventoryDiffListWorkbookService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

class InventoryDiffListWorkbookServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['sakemaru'];

    public function test_diff_list_workbook_uses_pdf_targets_and_splits_sheets_by_major_category(): void
    {
        if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_count_items', 'ending_system_quantity')) {
            $this->markTestSkipped('wms_inventory_count_items.ending_system_quantity is not available.');
        }

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => '差分Excelテスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'current_count_round' => 1,
        ]);

        $alcoholItemId = $this->createItemInCategories(1001, '差分Excel酒類', 2010, '差分Excel和酒');
        $foodItemId = $this->createItemInCategories(1002, '差分Excel飲料食品', 1010, '差分Excel飲料');
        $excludedItemId = $this->createItemInCategories(9999, '差分Excel対象外', 9010, '差分Excel対象外中分類');

        $this->createCountItem($inventoryCount, $alcoholItemId, 'DLW020', '差分Excel酒類入力あり', 'B2-01-01', 10, 8, 5, 1);
        $this->createCountItem($inventoryCount, $foodItemId, 'DLW010', '差分Excel飲料未入力', 'A1-01-01', 7, 7, null, 0);
        $this->createCountItem($inventoryCount, $foodItemId, 'DLW011', '差分Excel差異なし', 'A1-01-02', 7, 7, 7, 1);
        $this->createCountItem($inventoryCount, $excludedItemId, 'DLW999', '差分Excel対象外分類', 'C1-01-01', 10, 10, 1, 1);

        $workbook = $this->loadWorkbook((new InventoryDiffListWorkbookService)->generate($inventoryCount, 1));
        $sheetNames = $workbook->getSheetNames();

        $this->assertContains('1001 差分Excel酒類', $sheetNames);
        $this->assertContains('1002 差分Excel飲料食品', $sheetNames);
        $this->assertNotContains('9999 差分Excel対象外', $sheetNames);

        $alcoholSheet = $workbook->getSheetByName('1001 差分Excel酒類');
        $foodSheet = $workbook->getSheetByName('1002 差分Excel飲料食品');

        $this->assertInstanceOf(Worksheet::class, $alcoholSheet);
        $this->assertInstanceOf(Worksheet::class, $foodSheet);
        $this->assertSame($this->expectedHeaders(), $alcoholSheet->rangeToArray('A1:S1')[0]);

        $alcoholRows = $this->rowsByItemCode($alcoholSheet);
        $foodRows = $this->rowsByItemCode($foodSheet);

        $this->assertEqualsCanonicalizing(['DLW020'], array_keys($alcoholRows));
        $this->assertEqualsCanonicalizing(['DLW010'], array_keys($foodRows));
        $this->assertSame('B2', $alcoholRows['DLW020']['棚番']);
        $this->assertSame('B2-01-01', $alcoholRows['DLW020']['ロケ']);
        $this->assertSame('1001', $alcoholRows['DLW020']['部門CD']);
        $this->assertSame('差分Excel酒類', $alcoholRows['DLW020']['部門名']);
        $this->assertSame('2010', $alcoholRows['DLW020']['中分類CD']);
        $this->assertSame('差分Excel和酒', $alcoholRows['DLW020']['中分類名']);
        $this->assertSame(8, (int) $alcoholRows['DLW020']['終了理論']);
        $this->assertSame(5, (int) $alcoholRows['DLW020']['実数量']);
        $this->assertSame(-3, (int) $alcoholRows['DLW020']['終了差異']);
        $this->assertSame(7, (int) $foodRows['DLW010']['終了理論']);
        $this->assertSame(0, (int) $foodRows['DLW010']['実数量']);
        $this->assertSame(-7, (int) $foodRows['DLW010']['終了差異']);
    }

    /**
     * @return array<int, string>
     */
    private function expectedHeaders(): array
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

    private function createItemInCategories(
        int $majorCategoryCode,
        string $majorCategoryName,
        int $middleCategoryCode,
        string $middleCategoryName,
    ): int {
        $majorCategoryId = DB::connection('sakemaru')->table('item_categories')->insertGetId([
            'client_id' => 1,
            'name' => $majorCategoryName,
            'code' => $majorCategoryCode,
            'depth' => 1,
            'creator_id' => 1,
            'last_updater_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $middleCategoryId = DB::connection('sakemaru')->table('item_categories')->insertGetId([
            'client_id' => 1,
            'name' => $middleCategoryName,
            'parent_id' => $majorCategoryId,
            'code' => $middleCategoryCode,
            'depth' => 2,
            'creator_id' => 1,
            'last_updater_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemData = [
            'name_main' => '差分Excel対象商品'.Str::upper(Str::random(8)),
            'code' => random_int(800000000, 899999999),
            'type' => 'NOT_ALCOHOL',
            'manufacturer_id' => 0,
            'volume' => 1,
            'capacity_case' => 1,
            'creator_id' => 1,
            'packaging' => '1',
            'nickname' => '差分Excel対象',
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
            $itemData['is_managed_stock'] = true;
        }

        return (int) DB::connection('sakemaru')->table('items')->insertGetId($itemData);
    }

    private function createCountItem(
        WmsInventoryCount $inventoryCount,
        int $itemId,
        string $itemCode,
        string $itemName,
        string $locationNo,
        int $systemQuantity,
        int $endingSystemQuantity,
        ?int $firstCountQuantity,
        int $inputCount,
    ): WmsInventoryCountItem {
        return WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => random_int(900000000, 999999999),
            'item_id' => $itemId,
            'item_code' => $itemCode,
            'item_name' => $itemName,
            'location_id' => random_int(100000, 999999),
            'location_code1' => substr($locationNo, 0, 1),
            'location_code2' => substr($locationNo, 1, 1),
            'location_code3' => substr($locationNo, 3),
            'location_no' => $locationNo,
            'lot_no' => 'LOT-'.$itemCode,
            'expiration_date' => now()->addMonth()->toDateString(),
            'system_quantity' => $systemQuantity,
            'ending_system_quantity' => $endingSystemQuantity,
            'first_count_quantity' => $firstCountQuantity,
            'cost_price' => 10,
            'input_count' => $inputCount,
        ]);
    }

    private function loadWorkbook(string $content): Spreadsheet
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'wms-diff-list-test-');
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
    private function rowsByItemCode(Worksheet $sheet): array
    {
        $rows = [];
        $headers = $sheet->rangeToArray('A1:S1')[0];

        for ($rowIndex = 2; $rowIndex <= $sheet->getHighestDataRow(); $rowIndex++) {
            $rowValues = $sheet->rangeToArray("A{$rowIndex}:S{$rowIndex}")[0];
            $row = array_combine($headers, $rowValues);
            $itemCode = (string) ($row['アイテムコード'] ?? '');

            if ($itemCode !== '') {
                $rows[$itemCode] = $row;
            }
        }

        return $rows;
    }
}
