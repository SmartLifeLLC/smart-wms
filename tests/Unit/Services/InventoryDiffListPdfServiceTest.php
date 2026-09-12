<?php

namespace Tests\Unit\Services;

use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use App\Services\InventoryCount\InventoryDiffListPdfService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class InventoryDiffListPdfServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['sakemaru'];

    public function test_diff_list_includes_only_ending_differences(): void
    {
        if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_count_items', 'ending_system_quantity')) {
            $this->markTestSkipped('wms_inventory_count_items.ending_system_quantity is not available.');
        }

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => 'PDF差異テスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
        ]);

        $targetItemId = $this->createItemInMajorCategory(1001);
        $excludedCategoryItemId = $this->createItemInMajorCategory(9999);

        $endOnly = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $targetItemId,
            'item_code' => 'PDF001',
            'item_name' => '終了差異のみ',
            'system_quantity' => 5,
            'ending_system_quantity' => 3,
            'final_count_quantity' => 5,
            'cost_price' => 10,
        ]);

        $startOnly = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $targetItemId,
            'item_code' => 'PDF002',
            'item_name' => '開始差異のみ',
            'system_quantity' => 5,
            'ending_system_quantity' => 7,
            'final_count_quantity' => 7,
            'cost_price' => 20,
        ]);

        $matched = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $targetItemId,
            'item_code' => 'PDF003',
            'item_name' => '差異なし',
            'system_quantity' => 4,
            'ending_system_quantity' => 4,
            'final_count_quantity' => 4,
            'cost_price' => 30,
        ]);

        $noEndingStock = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $targetItemId,
            'item_code' => 'PDF004',
            'item_name' => '終了理論なし',
            'system_quantity' => 4,
            'ending_system_quantity' => null,
            'final_count_quantity' => 8,
            'cost_price' => 40,
        ]);

        $excludedCategory = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $excludedCategoryItemId,
            'item_code' => 'PDFCATEGORY001',
            'item_name' => '対象外大分類',
            'system_quantity' => 5,
            'ending_system_quantity' => 3,
            'final_count_quantity' => 5,
            'cost_price' => 10,
        ]);

        $ownedSetItemId = $this->createItemInMajorCategory(1001, true);
        $ownedSetItem = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $ownedSetItemId,
            'item_code' => 'PDFOWN001',
            'item_name' => '自社セット対象外',
            'system_quantity' => 5,
            'ending_system_quantity' => 3,
            'final_count_quantity' => 5,
            'cost_price' => 10,
        ]);

        $unmanagedItemId = $this->createItemInMajorCategory(1001, false, false);
        $unmanagedItem = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $unmanagedItemId,
            'item_code' => 'PDFUNMANAGED001',
            'item_name' => '在庫管理対象外',
            'system_quantity' => 5,
            'ending_system_quantity' => 3,
            'final_count_quantity' => 5,
            'cost_price' => 10,
        ]);

        $items = $this->diffListItems($inventoryCount);

        $this->assertSame([$endOnly->id, $noEndingStock->id], $items->pluck('id')->all());
        $this->assertFalse($items->contains('id', $startOnly->id));
        $this->assertFalse($items->contains('id', $matched->id));
        $this->assertFalse($items->contains('id', $excludedCategory->id));
        $this->assertFalse($items->contains('id', $ownedSetItem->id));
        $this->assertFalse($items->contains('id', $unmanagedItem->id));

        $endOnlyRow = $items->firstWhere('id', $endOnly->id);
        $noEndingStockRow = $items->firstWhere('id', $noEndingStock->id);

        $this->assertEquals(2.0, $endOnlyRow->getAttribute('pdf_end_difference_quantity'));
        $this->assertNull($endOnlyRow->getAttribute('pdf_start_difference_quantity'));
        $this->assertEquals(4.0, $noEndingStockRow->getAttribute('pdf_end_difference_quantity'));
    }

    public function test_diff_list_includes_uncounted_selected_round_as_zero_and_excludes_unmanaged_items(): void
    {
        if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_count_items', 'ending_system_quantity')) {
            $this->markTestSkipped('wms_inventory_count_items.ending_system_quantity is not available.');
        }

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => 'PDF未入力差異テスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'current_count_round' => 1,
        ]);

        $managedItemId = $this->createItemInMajorCategory(1001);
        $unmanagedItemId = $this->createItemInMajorCategory(1001, false, false);

        $uncounted = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $managedItemId,
            'item_code' => 'PDFUNC001',
            'item_name' => '差分PDF未入力0扱い',
            'system_quantity' => 5,
            'ending_system_quantity' => 5,
            'cost_price' => 10,
        ]);

        $uncountedWithFallbackSystem = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $managedItemId,
            'item_code' => 'PDFUNC000',
            'item_name' => '差分PDF未入力開始理論0扱い',
            'system_quantity' => 7,
            'ending_system_quantity' => null,
            'cost_price' => 10,
        ]);

        $unmanaged = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $unmanagedItemId,
            'item_code' => 'PDFUNC002',
            'item_name' => '差分PDF管理対象外',
            'system_quantity' => 5,
            'ending_system_quantity' => 5,
            'cost_price' => 10,
        ]);

        $items = $this->diffListItems($inventoryCount, 1);
        $row = $items->firstWhere('id', $uncounted->id);
        $fallbackRow = $items->firstWhere('id', $uncountedWithFallbackSystem->id);

        $this->assertSame([$uncountedWithFallbackSystem->id, $uncounted->id], $items->pluck('id')->all());
        $this->assertFalse($items->contains('id', $unmanaged->id));
        $this->assertSame(0, $row->getAttribute('pdf_actual_quantity'));
        $this->assertSame(5, (int) $row->getAttribute('pdf_system_quantity'));
        $this->assertEquals(-5.0, $row->getAttribute('pdf_end_difference_quantity'));
        $this->assertSame(0, $fallbackRow->getAttribute('pdf_actual_quantity'));
        $this->assertSame(7, (int) $fallbackRow->getAttribute('pdf_system_quantity'));
        $this->assertEquals(-7.0, $fallbackRow->getAttribute('pdf_end_difference_quantity'));
    }

    public function test_diff_list_for_second_round_adopts_first_quantity_when_second_is_blank(): void
    {
        if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_count_items', 'ending_system_quantity')) {
            $this->markTestSkipped('wms_inventory_count_items.ending_system_quantity is not available.');
        }

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => 'PDF回数別差異テスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'current_count_round' => 2,
        ]);

        $targetItemId = $this->createItemInMajorCategory(1001);

        $firstRoundOnly = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $targetItemId,
            'item_code' => 'ROUND-PDF-001',
            'item_name' => '1回目だけ差異',
            'system_quantity' => 10,
            'ending_system_quantity' => 10,
            'first_count_quantity' => 7,
            'cost_price' => 10,
        ]);

        $secondRoundDiff = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $targetItemId,
            'item_code' => 'ROUND-PDF-002',
            'item_name' => '2回目差異',
            'system_quantity' => 10,
            'ending_system_quantity' => 10,
            'first_count_quantity' => 10,
            'second_count_quantity' => 6,
            'cost_price' => 20,
        ]);

        $secondRoundMatched = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $targetItemId,
            'item_code' => 'ROUND-PDF-003',
            'item_name' => '2回目一致',
            'system_quantity' => 10,
            'ending_system_quantity' => 10,
            'first_count_quantity' => 6,
            'second_count_quantity' => 10,
            'cost_price' => 30,
        ]);

        $finalRoundOnly = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $targetItemId,
            'item_code' => 'ROUND-PDF-004',
            'item_name' => '3回目だけ差異',
            'system_quantity' => 10,
            'ending_system_quantity' => 10,
            'final_count_quantity' => 5,
            'cost_price' => 40,
        ]);

        $items = $this->diffListItems($inventoryCount, 2);

        $this->assertSame([$firstRoundOnly->id, $secondRoundDiff->id, $finalRoundOnly->id], $items->pluck('id')->all());
        $this->assertFalse($items->contains('id', $secondRoundMatched->id));

        $firstRoundOnlyRow = $items->firstWhere('id', $firstRoundOnly->id);
        $secondRoundDiffRow = $items->firstWhere('id', $secondRoundDiff->id);
        $finalRoundOnlyRow = $items->firstWhere('id', $finalRoundOnly->id);

        $this->assertSame(7, $firstRoundOnlyRow->getAttribute('pdf_actual_quantity'));
        $this->assertEquals(-3.0, $firstRoundOnlyRow->getAttribute('pdf_end_difference_quantity'));
        $this->assertSame(6, $secondRoundDiffRow->getAttribute('pdf_actual_quantity'));
        $this->assertEquals(-4.0, $secondRoundDiffRow->getAttribute('pdf_end_difference_quantity'));
        $this->assertSame(0, $finalRoundOnlyRow->getAttribute('pdf_actual_quantity'));
        $this->assertEquals(-10.0, $finalRoundOnlyRow->getAttribute('pdf_end_difference_quantity'));
    }

    public function test_diff_list_for_confirmed_round_uses_confirmed_difference_snapshot(): void
    {
        foreach ([
            'ending_system_quantity',
            'first_count_confirmed_system_quantity',
            'first_count_confirmed_difference_quantity',
        ] as $column) {
            if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_count_items', $column)) {
                $this->markTestSkipped("wms_inventory_count_items.{$column} is not available.");
            }
        }

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => 'PDF確定差分テスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'current_count_round' => 2,
            'first_count_confirmed_at' => now(),
        ]);

        $targetItemId = $this->createItemInMajorCategory(1001);

        $item = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $targetItemId,
            'item_code' => 'ROUND-PDF-005',
            'item_name' => '確定差分保持',
            'system_quantity' => 10,
            'ending_system_quantity' => 12,
            'first_count_quantity' => 7,
            'first_count_confirmed_system_quantity' => 10,
            'first_count_confirmed_difference_quantity' => -3,
            'first_count_confirmed_difference_amount' => -30,
            'cost_price' => 10,
        ]);

        $items = $this->diffListItems($inventoryCount, 1);
        $row = $items->firstWhere('id', $item->id);

        $this->assertNotNull($row);
        $this->assertSame(7, $row->getAttribute('pdf_actual_quantity'));
        $this->assertSame(10, (int) $row->getAttribute('pdf_system_quantity'));
        $this->assertEquals(-3.0, $row->getAttribute('pdf_end_difference_quantity'));
    }

    public function test_diff_list_for_confirmed_second_round_uses_fallback_snapshot_when_second_is_blank(): void
    {
        foreach ([
            'ending_system_quantity',
            'second_count_confirmed_system_quantity',
            'second_count_confirmed_difference_quantity',
        ] as $column) {
            if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_count_items', $column)) {
                $this->markTestSkipped("wms_inventory_count_items.{$column} is not available.");
            }
        }

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => 'PDF2回目確定差分テスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'current_count_round' => 3,
            'second_count_confirmed_at' => now(),
        ]);

        $targetItemId = $this->createItemInMajorCategory(1001);

        $item = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $targetItemId,
            'item_code' => 'ROUND-PDF-007',
            'item_name' => '2回目未入力確定差分保持',
            'system_quantity' => 10,
            'ending_system_quantity' => 12,
            'first_count_quantity' => 7,
            'second_count_confirmed_system_quantity' => 10,
            'second_count_confirmed_difference_quantity' => -3,
            'second_count_confirmed_difference_amount' => -30,
            'cost_price' => 10,
        ]);

        $items = $this->diffListItems($inventoryCount, 2);
        $row = $items->firstWhere('id', $item->id);

        $this->assertNotNull($row);
        $this->assertSame(7, $row->getAttribute('pdf_actual_quantity'));
        $this->assertSame(10, (int) $row->getAttribute('pdf_system_quantity'));
        $this->assertEquals(-3.0, $row->getAttribute('pdf_end_difference_quantity'));
    }

    public function test_diff_list_for_unconfirmed_round_ignores_stale_confirmed_difference_snapshot(): void
    {
        foreach ([
            'ending_system_quantity',
            'first_count_confirmed_system_quantity',
            'first_count_confirmed_difference_quantity',
        ] as $column) {
            if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_count_items', $column)) {
                $this->markTestSkipped("wms_inventory_count_items.{$column} is not available.");
            }
        }

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => 'PDF未確定差分テスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'current_count_round' => 1,
        ]);

        $targetItemId = $this->createItemInMajorCategory(1001);

        $item = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $targetItemId,
            'item_code' => 'ROUND-PDF-006',
            'item_name' => '未確定差分',
            'system_quantity' => 10,
            'ending_system_quantity' => 12,
            'first_count_quantity' => 7,
            'first_count_confirmed_system_quantity' => 10,
            'first_count_confirmed_difference_quantity' => -3,
            'first_count_confirmed_difference_amount' => -30,
            'cost_price' => 10,
        ]);

        $items = $this->diffListItems($inventoryCount, 1);
        $row = $items->firstWhere('id', $item->id);

        $this->assertNotNull($row);
        $this->assertSame(7, $row->getAttribute('pdf_actual_quantity'));
        $this->assertSame(12, (int) $row->getAttribute('pdf_system_quantity'));
        $this->assertEquals(-5.0, $row->getAttribute('pdf_end_difference_quantity'));
    }

    public function test_uncounted_list_for_selected_round_ignores_other_round_quantities(): void
    {
        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => 'PDF回数別未カウントテスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'current_count_round' => 2,
        ]);

        $itemId = $this->createItemInMajorCategory(1001);
        $stockId = random_int(900000000, 999999999);

        $firstRoundOnly = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => $stockId,
            'item_id' => $itemId,
            'item_code' => 'UNCROUND001',
            'item_name' => '1回目だけ入力済み',
            'system_quantity' => 10,
            'first_count_quantity' => 10,
            'cost_price' => 10,
        ]);

        $secondRoundCounted = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => $stockId + 1,
            'item_id' => $itemId,
            'item_code' => 'UNCROUND002',
            'item_name' => '2回目入力済み',
            'system_quantity' => 10,
            'second_count_quantity' => 10,
            'cost_price' => 20,
        ]);

        $finalRoundOnly = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => $stockId + 2,
            'item_id' => $itemId,
            'item_code' => 'UNCROUND003',
            'item_name' => '3回目だけ入力済み',
            'system_quantity' => 10,
            'final_count_quantity' => 10,
            'cost_price' => 30,
        ]);

        $neverCounted = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => $stockId + 3,
            'item_id' => $itemId,
            'item_code' => 'UNCROUND004',
            'item_name' => '未入力',
            'system_quantity' => 10,
            'cost_price' => 40,
        ]);

        $items = $this->uncountedListItems($inventoryCount, 2);

        $this->assertEqualsCanonicalizing(
            [$firstRoundOnly->id, $finalRoundOnly->id, $neverCounted->id],
            $items->pluck('id')->all(),
        );
        $this->assertFalse($items->contains('id', $secondRoundCounted->id));
    }

    public function test_diff_list_can_be_generated_for_draft_with_no_difference_rows(): void
    {
        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => 'PDF空データテスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_DRAFT,
        ]);

        $pdf = (new InventoryDiffListPdfService)->generate($inventoryCount);

        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_diff_pdf_omits_money_columns_and_prints_jan_code(): void
    {
        if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_count_items', 'ending_system_quantity')) {
            $this->markTestSkipped('wms_inventory_count_items.ending_system_quantity is not available.');
        }

        $pdftotext = trim((string) shell_exec('command -v pdftotext 2>/dev/null'));

        if ($pdftotext === '') {
            $this->markTestSkipped('pdftotext is not available.');
        }

        $itemId = $this->createItemInMajorCategory(1001);
        $janCode = '4999999999999';
        $productCode = 'PDFJAN'.Str::upper(Str::random(10));

        $quantityInformationId = DB::connection('sakemaru')->table('item_quantity_information')->insertGetId([
            'item_id' => $itemId,
            'product_code' => $productCode,
            'quantity_code' => '00',
            'dm_code' => '0',
            'own_code' => $janCode,
            'quantity' => 1,
            'creator_id' => 1,
            'last_updater_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $searchInformationData = [
            'client_id' => 1,
            'item_id' => $itemId,
            'code_type' => 'OTHER',
            'quantity_type' => 'PIECE',
            'item_quantity_information_id' => $quantityInformationId,
            'search_string' => $janCode,
            'creator_id' => 1,
            'last_updater_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::connection('sakemaru')->hasColumn('item_search_information', 'priority')) {
            $searchInformationData['priority'] = 1;
        }

        DB::connection('sakemaru')->table('item_search_information')->insert($searchInformationData);

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => 'PDFバーコードテスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $itemId,
            'item_code' => 'PDFJAN001',
            'item_name' => 'JAN表示差異',
            'system_quantity' => 5,
            'ending_system_quantity' => 3,
            'final_count_quantity' => 5,
            'cost_price' => 12345,
        ]);

        $text = $this->extractPdfText((new InventoryDiffListPdfService)->generate($inventoryCount), $pdftotext);

        $this->assertStringContainsString($janCode, $text);
        $this->assertStringContainsString('ロケ', $text);
        $this->assertStringNotContainsString('ロケーションNO', $text);
        $this->assertStringNotContainsString('仕入原価', $text);
        $this->assertStringNotContainsString('終了差額', $text);
        $this->assertStringNotContainsString('差異金額', $text);
    }

    public function test_pdf_item_name_is_split_after_item_code(): void
    {
        $service = new InventoryDiffListPdfService;

        $initPdf = new ReflectionMethod(InventoryDiffListPdfService::class, 'initPdf');
        $initPdf->setAccessible(true);
        $initPdf->invoke($service);

        $splitItemName = new ReflectionMethod(InventoryDiffListPdfService::class, 'splitItemNameForItemCell');
        $splitItemName->setAccessible(true);

        [$firstLine, $secondLine] = $splitItemName->invoke(
            $service,
            'LONGITEM001',
            '商品名商品名商品名商品名商品名商品名商品名商品名商品名商品名商品名商品名商品名',
        );

        $this->assertNotSame('', $firstLine);
        $this->assertNotSame('', $secondLine);
        $this->assertStringNotContainsString('LONGITEM001', $firstLine);
        $this->assertStringContainsString('商品名', $firstLine);
        $this->assertStringContainsString('商品名', $secondLine);
    }

    public function test_diff_and_uncounted_pdfs_print_middle_category_headers(): void
    {
        if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_count_items', 'ending_system_quantity')) {
            $this->markTestSkipped('wms_inventory_count_items.ending_system_quantity is not available.');
        }

        $pdftotext = trim((string) shell_exec('command -v pdftotext 2>/dev/null'));

        if ($pdftotext === '') {
            $this->markTestSkipped('pdftotext is not available.');
        }

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => '中分類ヘッダーテスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
        ]);

        $diffItemId = $this->createItemInMajorCategory(1001);
        $uncountedItemId = $this->createItemInMajorCategory(1002);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $diffItemId,
            'item_code' => 'CATDIFF001',
            'item_name' => '中分類差異対象',
            'location_code1' => 'Z',
            'location_code2' => '01',
            'location_code3' => '01',
            'location_no' => 'Z-01-01',
            'system_quantity' => 5,
            'ending_system_quantity' => 3,
            'final_count_quantity' => 5,
            'cost_price' => 10,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $uncountedItemId,
            'item_code' => 'CATUNC001',
            'item_name' => '中分類未カウント対象',
            'location_code1' => 'Z',
            'location_code2' => '01',
            'location_code3' => '02',
            'location_no' => 'Z-01-02',
            'system_quantity' => 5,
            'ending_system_quantity' => 5,
            'cost_price' => 20,
        ]);

        $diffText = $this->extractPdfText((new InventoryDiffListPdfService)->generate($inventoryCount), $pdftotext);
        $uncountedText = $this->extractPdfText((new InventoryDiffListPdfService)->generateUncounted($inventoryCount, 1), $pdftotext);

        $this->assertStringContainsString('中分類：未PDF中分類1001', $diffText);
        $this->assertStringContainsString('中分類：未PDF中分類1002', $uncountedText);
    }

    public function test_pdf_groups_warehouse_91_by_shelf_without_middle_category_grouping(): void
    {
        if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_count_items', 'ending_system_quantity')) {
            $this->markTestSkipped('wms_inventory_count_items.ending_system_quantity is not available.');
        }

        $pdftotext = trim((string) shell_exec('command -v pdftotext 2>/dev/null'));
        $pdfinfo = trim((string) shell_exec('command -v pdfinfo 2>/dev/null'));

        if ($pdftotext === '' || $pdfinfo === '') {
            $this->markTestSkipped('poppler tools are not available.');
        }

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 91,
            'warehouse_code' => '91',
            'warehouse_name' => '91棚番グループテスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
        ]);

        $firstCategoryItemId = $this->createItemInMajorCategory(1001);
        $secondCategoryItemId = $this->createItemInMajorCategory(1002);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $firstCategoryItemId,
            'item_code' => 'GROUP91001',
            'item_name' => '91棚番A0商品',
            'location_id' => 1,
            'location_code1' => 'A',
            'location_code2' => '01',
            'location_code3' => '01',
            'location_no' => 'A0-01-01',
            'system_quantity' => 5,
            'ending_system_quantity' => 5,
            'final_count_quantity' => 4,
            'cost_price' => 10,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $secondCategoryItemId,
            'item_code' => 'GROUP91002',
            'item_name' => '91棚番B0商品',
            'location_id' => 2,
            'location_code1' => 'B',
            'location_code2' => '01',
            'location_code3' => '01',
            'location_no' => 'B0-01-01',
            'system_quantity' => 5,
            'ending_system_quantity' => 5,
            'final_count_quantity' => 4,
            'cost_price' => 10,
        ]);

        $pdf = (new InventoryDiffListPdfService)->generate($inventoryCount);
        $text = $this->extractPdfText($pdf, $pdftotext);

        $this->assertSame(2, $this->pdfPageCount($pdf, $pdfinfo));
        $this->assertStringContainsString('棚番：A0', $text);
        $this->assertStringContainsString('棚番：B0', $text);
        $this->assertStringNotContainsString('中分類：未PDF中分類1001', $text);
        $this->assertStringNotContainsString('中分類：未PDF中分類1002', $text);
    }

    public function test_pdf_groups_non_91_warehouses_by_middle_category_without_shelf_grouping(): void
    {
        if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_count_items', 'ending_system_quantity')) {
            $this->markTestSkipped('wms_inventory_count_items.ending_system_quantity is not available.');
        }

        $pdftotext = trim((string) shell_exec('command -v pdftotext 2>/dev/null'));
        $pdfinfo = trim((string) shell_exec('command -v pdfinfo 2>/dev/null'));

        if ($pdftotext === '' || $pdfinfo === '') {
            $this->markTestSkipped('poppler tools are not available.');
        }

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => '中分類グループテスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
        ]);

        $firstCategoryItemId = $this->createItemInMajorCategory(1001);
        $secondCategoryItemId = $this->createItemInMajorCategory(1002);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $firstCategoryItemId,
            'item_code' => 'GROUPCAT001',
            'item_name' => '中分類A商品',
            'location_id' => 1,
            'location_code1' => 'A',
            'location_code2' => '01',
            'location_code3' => '01',
            'location_no' => 'A0-01-01',
            'system_quantity' => 5,
            'ending_system_quantity' => 5,
            'final_count_quantity' => 4,
            'cost_price' => 10,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $secondCategoryItemId,
            'item_code' => 'GROUPCAT002',
            'item_name' => '中分類B商品',
            'location_id' => 2,
            'location_code1' => 'B',
            'location_code2' => '01',
            'location_code3' => '01',
            'location_no' => 'B0-01-01',
            'system_quantity' => 5,
            'ending_system_quantity' => 5,
            'final_count_quantity' => 4,
            'cost_price' => 10,
        ]);

        $pdf = (new InventoryDiffListPdfService)->generate($inventoryCount);
        $text = $this->extractPdfText($pdf, $pdftotext);

        $this->assertSame(2, $this->pdfPageCount($pdf, $pdfinfo));
        $this->assertStringContainsString('中分類：未PDF中分類1001', $text);
        $this->assertStringContainsString('中分類：未PDF中分類1002', $text);
        $this->assertStringNotContainsString('棚番：A0', $text);
        $this->assertStringNotContainsString('棚番：B0', $text);
    }

    public function test_uncounted_list_filters_pdf_target_categories_and_zero_theory_zero_difference_items(): void
    {
        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => '未PDFゼロ理論テスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
        ]);

        $targetItemId = $this->createItemInMajorCategory(1001);
        $zeroSystemQuantityWithoutDifferenceItemId = $this->createItemInMajorCategory(1002);
        $zeroSystemQuantityWithDifferenceItemId = $this->createItemInMajorCategory(1006);
        $excludedItemId = $this->createItemInMajorCategory(9999);
        $countedItemId = $this->createItemInMajorCategory(1003);
        $ownedSetItemId = $this->createItemInMajorCategory(1001, true);
        $unmanagedItemId = $this->createItemInMajorCategory(1001, false, false);

        $target = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => random_int(900000000, 999999999),
            'item_id' => $targetItemId,
            'item_code' => 'UNC001',
            'item_name' => '未入力で理論あり',
            'system_quantity' => 3,
            'cost_price' => 10,
        ]);

        $zeroSystemQuantityWithoutDifference = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => $target->real_stock_id + 1,
            'item_id' => $zeroSystemQuantityWithoutDifferenceItemId,
            'item_code' => 'UNC002',
            'item_name' => '未入力で理論ゼロ差異なし',
            'system_quantity' => 0,
            'cost_price' => 20,
        ]);

        $zeroSystemQuantityWithDifference = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => $target->real_stock_id + 2,
            'item_id' => $zeroSystemQuantityWithDifferenceItemId,
            'item_code' => 'UNC003',
            'item_name' => '未入力で理論ゼロ差異あり',
            'system_quantity' => 0,
            'difference_quantity' => 1,
            'cost_price' => 30,
        ]);

        $excludedCategory = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => $target->real_stock_id + 3,
            'item_id' => $excludedItemId,
            'item_code' => 'UNC004',
            'item_name' => '対象外大分類',
            'system_quantity' => 5,
            'cost_price' => 40,
        ]);

        $counted = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => $target->real_stock_id + 4,
            'item_id' => $countedItemId,
            'item_code' => 'UNC005',
            'item_name' => '対象大分類だが入力済み',
            'system_quantity' => 0,
            'first_count_quantity' => 0,
            'cost_price' => 50,
        ]);

        $ownedSetItem = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => $target->real_stock_id + 5,
            'item_id' => $ownedSetItemId,
            'item_code' => 'UNC006',
            'item_name' => '自社セット未入力',
            'system_quantity' => 10,
            'cost_price' => 60,
        ]);

        $unmanagedItem = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => $target->real_stock_id + 6,
            'item_id' => $unmanagedItemId,
            'item_code' => 'UNC007',
            'item_name' => '在庫管理対象外未入力',
            'system_quantity' => 10,
            'cost_price' => 70,
        ]);

        $items = $this->uncountedListItems($inventoryCount, 1);

        $this->assertTrue($items->contains('id', $target->id));
        $this->assertTrue($items->contains('id', $zeroSystemQuantityWithDifference->id));
        $this->assertFalse($items->contains('id', $zeroSystemQuantityWithoutDifference->id));
        $this->assertFalse($items->contains('id', $excludedCategory->id));
        $this->assertFalse($items->contains('id', $counted->id));
        $this->assertFalse($items->contains('id', $ownedSetItem->id));
        $this->assertFalse($items->contains('id', $unmanagedItem->id));
    }

    public function test_multi_count_uncounted_list_excludes_items_counted_in_any_selected_count(): void
    {
        $firstInventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => '複数未PDFテスト倉庫',
            'count_date' => now()->subDay()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
        ]);

        $secondInventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => '複数未PDFテスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
        ]);

        $countedStockId = random_int(900000000, 999999999);
        $uncountedStockId = $countedStockId + 1;
        $zeroCountedStockId = $countedStockId + 2;
        $zeroSystemQuantityWithoutDifferenceStockId = $countedStockId + 3;
        $zeroSystemQuantityWithDifferenceStockId = $countedStockId + 4;
        $excludedCategoryStockId = $countedStockId + 5;
        $countedItemId = $this->createItemInMajorCategory(1001);
        $uncountedItemId = $this->createItemInMajorCategory(1002);
        $zeroCountedItemId = $this->createItemInMajorCategory(1003);
        $zeroSystemQuantityWithoutDifferenceItemId = $this->createItemInMajorCategory(1006);
        $zeroSystemQuantityWithDifferenceItemId = $this->createItemInMajorCategory(1001);
        $excludedItemId = $this->createItemInMajorCategory(9999);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $firstInventoryCount->id,
            'real_stock_id' => $countedStockId,
            'item_id' => $countedItemId,
            'item_code' => 'BULK001',
            'item_name' => '別日で入力済み',
            'system_quantity' => 10,
            'cost_price' => 10,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $secondInventoryCount->id,
            'real_stock_id' => $countedStockId,
            'item_id' => $countedItemId,
            'item_code' => 'BULK001',
            'item_name' => '別日で入力済み',
            'system_quantity' => 10,
            'first_count_quantity' => 8,
            'input_count' => 1,
            'cost_price' => 10,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $firstInventoryCount->id,
            'real_stock_id' => $uncountedStockId,
            'item_id' => $uncountedItemId,
            'item_code' => 'BULK002',
            'item_name' => '全日未入力',
            'system_quantity' => 5,
            'cost_price' => 20,
        ]);

        $latestUncounted = WmsInventoryCountItem::create([
            'inventory_count_id' => $secondInventoryCount->id,
            'real_stock_id' => $uncountedStockId,
            'item_id' => $uncountedItemId,
            'item_code' => 'BULK002',
            'item_name' => '全日未入力',
            'location_code1' => 'A',
            'location_no' => 'A-01-01',
            'system_quantity' => 5,
            'cost_price' => 20,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $firstInventoryCount->id,
            'real_stock_id' => $zeroCountedStockId,
            'item_id' => $zeroCountedItemId,
            'item_code' => 'BULK003',
            'item_name' => 'ゼロ入力済み',
            'system_quantity' => 1,
            'first_count_quantity' => 0,
            'input_count' => 1,
            'cost_price' => 30,
        ]);

        $zeroSystemQuantityWithoutDifference = WmsInventoryCountItem::create([
            'inventory_count_id' => $secondInventoryCount->id,
            'real_stock_id' => $zeroSystemQuantityWithoutDifferenceStockId,
            'item_id' => $zeroSystemQuantityWithoutDifferenceItemId,
            'item_code' => 'BULK004',
            'item_name' => '理論ゼロ差異なし未入力',
            'system_quantity' => 0,
            'cost_price' => 40,
        ]);

        $zeroSystemQuantityWithDifference = WmsInventoryCountItem::create([
            'inventory_count_id' => $secondInventoryCount->id,
            'real_stock_id' => $zeroSystemQuantityWithDifferenceStockId,
            'item_id' => $zeroSystemQuantityWithDifferenceItemId,
            'item_code' => 'BULK005',
            'item_name' => '理論ゼロ差異あり未入力',
            'system_quantity' => 0,
            'difference_quantity' => 2,
            'cost_price' => 50,
        ]);

        $excludedCategory = WmsInventoryCountItem::create([
            'inventory_count_id' => $secondInventoryCount->id,
            'real_stock_id' => $excludedCategoryStockId,
            'item_id' => $excludedItemId,
            'item_code' => 'BULK006',
            'item_name' => '対象外大分類',
            'system_quantity' => 10,
            'cost_price' => 60,
        ]);

        $items = $this->multiCountUncountedItems(collect([$firstInventoryCount, $secondInventoryCount]), 1);

        $this->assertCount(2, $items);
        $this->assertTrue($items->contains('id', $latestUncounted->id));
        $this->assertTrue($items->contains('id', $zeroSystemQuantityWithDifference->id));
        $this->assertFalse($items->contains('id', $zeroSystemQuantityWithoutDifference->id));
        $this->assertFalse($items->contains('id', $excludedCategory->id));
        $this->assertFalse($items->contains('real_stock_id', $countedStockId));
        $this->assertFalse($items->contains('real_stock_id', $zeroCountedStockId));

        $pdf = (new InventoryDiffListPdfService)->generateUncountedForCounts(collect([$firstInventoryCount, $secondInventoryCount]), 1);

        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_multi_count_uncounted_list_uses_selected_round_only(): void
    {
        $firstInventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => '複数回数別未PDFテスト倉庫',
            'count_date' => now()->subDay()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
        ]);

        $secondInventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => '複数回数別未PDFテスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
        ]);

        $itemId = $this->createItemInMajorCategory(1001);
        $countedInSecondStockId = random_int(900000000, 999999999);
        $uncountedInSecondStockId = $countedInSecondStockId + 1;

        WmsInventoryCountItem::create([
            'inventory_count_id' => $firstInventoryCount->id,
            'real_stock_id' => $countedInSecondStockId,
            'item_id' => $itemId,
            'item_code' => 'BULKROUND001',
            'item_name' => '複数選択2回目入力済み',
            'system_quantity' => 10,
            'first_count_quantity' => 10,
            'cost_price' => 10,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $secondInventoryCount->id,
            'real_stock_id' => $countedInSecondStockId,
            'item_id' => $itemId,
            'item_code' => 'BULKROUND001',
            'item_name' => '複数選択2回目入力済み',
            'system_quantity' => 10,
            'second_count_quantity' => 9,
            'cost_price' => 10,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $firstInventoryCount->id,
            'real_stock_id' => $uncountedInSecondStockId,
            'item_id' => $itemId,
            'item_code' => 'BULKROUND002',
            'item_name' => '複数選択2回目未入力',
            'system_quantity' => 10,
            'first_count_quantity' => 10,
            'cost_price' => 20,
        ]);

        $latestUncounted = WmsInventoryCountItem::create([
            'inventory_count_id' => $secondInventoryCount->id,
            'real_stock_id' => $uncountedInSecondStockId,
            'item_id' => $itemId,
            'item_code' => 'BULKROUND002',
            'item_name' => '複数選択2回目未入力',
            'system_quantity' => 10,
            'final_count_quantity' => 10,
            'cost_price' => 20,
        ]);

        $items = $this->multiCountUncountedItems(collect([$firstInventoryCount, $secondInventoryCount]), 2);

        $this->assertSame([$latestUncounted->id], $items->pluck('id')->all());
        $this->assertFalse($items->contains('real_stock_id', $countedInSecondStockId));
    }

    private function diffListItems(WmsInventoryCount $inventoryCount, ?int $round = null)
    {
        $service = new InventoryDiffListPdfService;

        if ($round !== null) {
            $property = new ReflectionProperty(InventoryDiffListPdfService::class, 'diffRound');
            $property->setAccessible(true);
            $property->setValue($service, $round);
        }

        $method = new ReflectionMethod(InventoryDiffListPdfService::class, 'queryItems');
        $method->setAccessible(true);

        return $method->invoke($service, $inventoryCount);
    }

    private function uncountedListItems(WmsInventoryCount $inventoryCount, int $round)
    {
        $service = new InventoryDiffListPdfService;

        $property = new ReflectionProperty(InventoryDiffListPdfService::class, 'uncountedRound');
        $property->setAccessible(true);
        $property->setValue($service, $round);

        $method = new ReflectionMethod(InventoryDiffListPdfService::class, 'queryItems');
        $method->setAccessible(true);

        return $method->invoke($service, $inventoryCount);
    }

    private function multiCountUncountedItems($inventoryCounts, int $round)
    {
        $method = new ReflectionMethod(InventoryDiffListPdfService::class, 'queryMultiCountUncountedItems');
        $method->setAccessible(true);

        return $method->invoke(new InventoryDiffListPdfService, $inventoryCounts, $round);
    }

    private function extractPdfText(string $pdf, string $pdftotext): string
    {
        $pdfPath = tempnam(sys_get_temp_dir(), 'wms-diff-pdf-');
        $textPath = tempnam(sys_get_temp_dir(), 'wms-diff-text-');

        try {
            file_put_contents($pdfPath, $pdf);

            $command = escapeshellarg($pdftotext).' -layout '.escapeshellarg($pdfPath).' '.escapeshellarg($textPath);
            exec($command, $output, $exitCode);

            $this->assertSame(0, $exitCode, implode("\n", $output));

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

    private function pdfPageCount(string $pdf, string $pdfinfo): int
    {
        $pdfPath = tempnam(sys_get_temp_dir(), 'wms-diff-pdf-');

        try {
            file_put_contents($pdfPath, $pdf);

            $command = escapeshellarg($pdfinfo).' '.escapeshellarg($pdfPath);
            exec($command, $output, $exitCode);

            $this->assertSame(0, $exitCode, implode("\n", $output));

            foreach ($output as $line) {
                if (preg_match('/^Pages:\s+(\d+)/', $line, $matches) === 1) {
                    return (int) $matches[1];
                }
            }

            $this->fail('Could not read PDF page count.');
        } finally {
            if (is_file($pdfPath)) {
                unlink($pdfPath);
            }
        }
    }

    private function createItemInMajorCategory(int $majorCategoryCode, bool $ownedSet = false, bool $managedStock = true): int
    {
        $majorCategoryId = DB::connection('sakemaru')->table('item_categories')->insertGetId([
            'client_id' => 1,
            'name' => '未PDF大分類'.$majorCategoryCode,
            'code' => $majorCategoryCode,
            'depth' => 1,
            'creator_id' => 1,
            'last_updater_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $middleCategoryId = DB::connection('sakemaru')->table('item_categories')->insertGetId([
            'client_id' => 1,
            'name' => '未PDF中分類'.$majorCategoryCode,
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
            'name_main' => '未PDF対象商品'.Str::upper(Str::random(8)),
            'code' => random_int(800000000, 899999999),
            'type' => 'NOT_ALCOHOL',
            'manufacturer_id' => 0,
            'volume' => 1,
            'capacity_case' => 1,
            'creator_id' => 1,
            'packaging' => '1',
            'nickname' => '未PDF対象',
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
}
