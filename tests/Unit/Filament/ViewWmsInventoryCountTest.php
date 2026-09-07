<?php

namespace Tests\Unit\Filament;

use App\Filament\Resources\WmsInventoryCount\Pages\ViewWmsInventoryCount;
use App\Filament\Resources\WmsInventoryCount\Pages\ViewWmsInventoryCountLogs;
use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use App\Models\WmsInventoryCountItemLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ViewWmsInventoryCountTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['sakemaru'];

    public function test_tab_visibility_uses_original_values_until_inline_save(): void
    {
        $blade = file_get_contents(resource_path('views/filament/resources/wms-inventory-count/pages/view-wms-inventory-count.blade.php'));

        $this->assertStringContainsString('row.originalEndDiff', $blade);
        $this->assertStringContainsString('row.originalUncounted', $blade);
        $this->assertStringContainsString("this.activeTab === 'unmanaged'", $blade);
        $this->assertStringContainsString('row.unmanagedStock', $blade);
        $this->assertStringContainsString('get originalEndDiff()', $blade);
        $this->assertStringNotContainsString("this.activeTab === 'diff' && !(row.endDiff", $blade);
        $this->assertStringNotContainsString("this.activeTab === 'uncounted'", $blade);
    }

    public function test_inline_count_inputs_allow_negative_quantities(): void
    {
        $blade = file_get_contents(resource_path('views/filament/resources/wms-inventory-count/pages/view-wms-inventory-count.blade.php'));

        $this->assertStringContainsString("value === '-'", $blade);
        $this->assertStringContainsString("replace(/[^0-9-]/g,'')", $blade);
        $this->assertStringContainsString("['e','E','+','.']", $blade);
        $this->assertStringNotContainsString("['e','E','+','-','.']", $blade);
    }

    public function test_diff_pdf_action_uses_active_count_round(): void
    {
        $page = file_get_contents(app_path('Filament/Resources/WmsInventoryCount/Pages/ViewWmsInventoryCount.php'));

        $this->assertStringContainsString('->generate($record, $this->activeCountRound)', $page);
        $this->assertStringContainsString("\$filename = '棚卸差分確認_'.\$this->activeRoundLabel().'_'.", $page);
    }

    public function test_uncounted_pdf_action_uses_active_count_round(): void
    {
        $page = file_get_contents(app_path('Filament/Resources/WmsInventoryCount/Pages/ViewWmsInventoryCount.php'));

        $this->assertStringContainsString('->generateUncounted($record, $this->activeCountRound)', $page);
        $this->assertStringContainsString("\$filename = '棚卸未カウント_'.\$this->activeRoundLabel().'_'.", $page);
    }

    public function test_difference_workbook_action_is_placed_after_uncounted_pdf(): void
    {
        $page = file_get_contents(app_path('Filament/Resources/WmsInventoryCount/Pages/ViewWmsInventoryCount.php'));

        $uncountedPosition = strpos($page, "Action::make('downloadUncountedListPdf')");
        $workbookPosition = strpos($page, "Action::make('downloadDifferenceWorkbook')");

        $this->assertNotFalse($uncountedPosition);
        $this->assertNotFalse($workbookPosition);
        $this->assertGreaterThan($uncountedPosition, $workbookPosition);
        $this->assertStringContainsString('->label(\'差異データ\')', $page);
        $this->assertStringContainsString("Select::make('target_round')", $page);
        $this->assertStringContainsString('InventoryDifferenceWorkbookService)->generate($record, $targetRound)', $page);
        $this->assertStringContainsString('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $page);

        $blade = file_get_contents(resource_path('views/filament/resources/wms-inventory-count/pages/view-wms-inventory-count.blade.php'));
        $bladeUncountedPosition = strpos($blade, "\$this->getAction('downloadUncountedListPdf')");
        $bladeWorkbookPosition = strpos($blade, "\$this->getAction('downloadDifferenceWorkbook')");

        $this->assertNotFalse($bladeUncountedPosition);
        $this->assertNotFalse($bladeWorkbookPosition);
        $this->assertGreaterThan($bladeUncountedPosition, $bladeWorkbookPosition);
        $this->assertStringContainsString('<span>差異再計算</span>', $blade);
    }

    public function test_entered_list_workbook_action_is_available_in_details_and_fill_uncounted_zero_is_hidden(): void
    {
        $page = file_get_contents(app_path('Filament/Resources/WmsInventoryCount/Pages/ViewWmsInventoryCount.php'));

        $enteredWorkbookPosition = strpos($page, "Action::make('downloadEnteredListWorkbook')");

        $this->assertNotFalse($enteredWorkbookPosition);
        $this->assertStringContainsString('->visible(false)', substr($page, strpos($page, "Action::make('fillUncountedWithZero')"), 400));
        $this->assertStringContainsString('->label(\'入力済Excel\')', $page);
        $this->assertStringContainsString("Select::make('target_round')", $page);
        $this->assertStringContainsString('InventoryEnteredListWorkbookService)->generate($record, $targetRound)', $page);
        $this->assertStringContainsString("\$filename = '棚卸入力済リスト_'.\$this->roundLabel(\$targetRound).'_'.", $page);

        $blade = file_get_contents(resource_path('views/filament/resources/wms-inventory-count/pages/view-wms-inventory-count.blade.php'));
        $bladeEnteredWorkbookPosition = strpos($blade, "\$this->getAction('downloadEnteredListWorkbook')");

        $this->assertNotFalse($bladeEnteredWorkbookPosition);
        $this->assertStringNotContainsString("\$this->getAction('fillUncountedWithZero')", $blade);
    }

    public function test_fill_uncounted_with_zero_logs_auto_zero_device(): void
    {
        if (! Schema::connection('sakemaru')->hasColumn('items', 'is_managed_stock')) {
            $this->markTestSkipped('items.is_managed_stock is not available.');
        }

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => '未0ログテスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'current_count_round' => 1,
        ]);

        $item = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999407,
            'item_code' => 'ZEROLOG001',
            'item_name' => '未0ログ対象',
            'system_quantity' => 10,
            'ending_system_quantity' => 8,
            'cost_price' => 10,
        ]);
        $unmanagedItemId = $this->createItemInMajorCategory(1001, false);
        $unmanagedItem = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $unmanagedItemId,
            'item_code' => 'ZEROLOG002',
            'item_name' => '未0対象外',
            'system_quantity' => 10,
            'ending_system_quantity' => 8,
            'cost_price' => 10,
        ]);

        $page = new ViewWmsInventoryCount;
        $page->record = $inventoryCount;
        $page->activeCountRound = 1;

        $page->fillActiveRoundUncountedWithZero();

        $item->refresh();
        $log = WmsInventoryCountItemLog::where('inventory_count_item_id', $item->id)->first();

        $this->assertSame(0, $item->first_count_quantity);
        $this->assertSame(WmsInventoryCountItemLog::DEVICE_WEB_AUTO_ZERO, $log?->device_id);
        $this->assertSame('未0', $log?->actor_name);
        $this->assertNull($unmanagedItem->refresh()->first_count_quantity);
    }

    public function test_second_round_difference_refresh_action_is_available_in_details(): void
    {
        $page = file_get_contents(app_path('Filament/Resources/WmsInventoryCount/Pages/ViewWmsInventoryCount.php'));

        $this->assertStringContainsString("Action::make('refreshSecondRoundConfirmedDifferences')", $page);
        $this->assertStringContainsString('refreshSecondRoundConfirmedDifferences($record)', $page);
        $this->assertStringContainsString('->label(\'2回目差異再計算\')', $page);

        $blade = file_get_contents(resource_path('views/filament/resources/wms-inventory-count/pages/view-wms-inventory-count.blade.php'));
        $theoryRefreshPosition = strpos($blade, "\$this->getAction('refreshDailySnapshotStock')");
        $differenceRefreshPosition = strpos($blade, "\$this->getAction('refreshSecondRoundConfirmedDifferences')");

        $this->assertNotFalse($theoryRefreshPosition);
        $this->assertNotFalse($differenceRefreshPosition);
        $this->assertGreaterThan($theoryRefreshPosition, $differenceRefreshPosition);
    }

    public function test_inline_save_accepts_negative_count_quantity(): void
    {
        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => '負数入力テスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'current_count_round' => 1,
        ]);

        $item = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999601,
            'item_code' => 'NEG001',
            'item_name' => '負数入力対象',
            'system_quantity' => 10,
            'ending_system_quantity' => 10,
            'cost_price' => 10,
        ]);

        $page = new ViewWmsInventoryCount;
        $page->record = $inventoryCount;
        $page->activeCountRound = 1;

        $page->saveInlineChanges([
            $item->id => ['first' => -3],
        ]);

        $item->refresh();

        $this->assertSame(-3, $item->first_count_quantity);
        $this->assertSame(-13, $item->difference_quantity);
        $this->assertSame(1, $item->input_count);
    }

    public function test_difference_tabs_compare_active_round_with_ending_system_quantity(): void
    {
        if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_count_items', 'ending_system_quantity')) {
            $this->markTestSkipped('wms_inventory_count_items.ending_system_quantity is not available.');
        }

        foreach ([
            'first_count_confirmed_system_quantity',
            'first_count_confirmed_difference_quantity',
            'first_count_confirmed_difference_amount',
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
            'warehouse_name' => '終了差異タブテスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'current_count_round' => 1,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999401,
            'item_code' => 'TAB001',
            'item_name' => '終了差異あり',
            'system_quantity' => 5,
            'ending_system_quantity' => 3,
            'first_count_quantity' => 5,
            'difference_quantity' => 0,
            'cost_price' => 10,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999402,
            'item_code' => 'TAB002',
            'item_name' => '開始差異のみ',
            'system_quantity' => 5,
            'ending_system_quantity' => 7,
            'first_count_quantity' => 7,
            'difference_quantity' => 2,
            'cost_price' => 20,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999403,
            'item_code' => 'TAB003',
            'item_name' => '終了理論なし',
            'system_quantity' => 5,
            'ending_system_quantity' => null,
            'first_count_quantity' => 10,
            'difference_quantity' => 5,
            'cost_price' => 30,
        ]);

        $targetCategoryItemId = $this->createItemInMajorCategory(1001);
        $uncountedTarget = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $targetCategoryItemId,
            'item_code' => 'TAB004',
            'item_name' => '未カウント差異扱い',
            'system_quantity' => 5,
            'ending_system_quantity' => 5,
            'cost_price' => 40,
        ]);

        $unmanagedItemId = $this->createItemInMajorCategory(1001, false);
        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $unmanagedItemId,
            'item_code' => 'TAB005',
            'item_name' => '在庫管理対象外',
            'system_quantity' => 5,
            'ending_system_quantity' => 5,
            'first_count_quantity' => 0,
            'cost_price' => 50,
        ]);

        $page = new ViewWmsInventoryCount;
        $page->record = $inventoryCount;
        $page->activeCountRound = 1;

        $this->assertSame(3, $page->countForTab('diff'));
        $this->assertSame(1, $page->countForTab('matched'));
        $this->assertSame(1, $page->countForTab('unmanaged'));
        $this->assertSame(-5, $page->roundDifferenceForDisplay($uncountedTarget->refresh(), 1));
    }

    public function test_active_round_difference_recalculation_uses_ending_system_quantity_without_touching_confirmed_snapshot(): void
    {
        foreach ([
            'ending_system_quantity',
            'first_count_confirmed_system_quantity',
            'first_count_confirmed_difference_quantity',
            'first_count_confirmed_difference_amount',
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
            'warehouse_name' => '現在回差異再計算テスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'current_count_round' => 1,
        ]);

        $item = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999406,
            'item_code' => 'RECALC001',
            'item_name' => '現在回差異再計算',
            'system_quantity' => 10,
            'ending_system_quantity' => 8,
            'first_count_quantity' => 7,
            'difference_quantity' => 99,
            'difference_amount' => 990,
            'first_count_confirmed_system_quantity' => 10,
            'first_count_confirmed_difference_quantity' => -3,
            'first_count_confirmed_difference_amount' => -30,
            'cost_price' => 10,
        ]);

        $page = new ViewWmsInventoryCount;
        $page->record = $inventoryCount;
        $page->activeCountRound = 1;

        $page->calculateActiveRoundDifferences();

        $item->refresh();

        $this->assertSame(-1, $item->difference_quantity);
        $this->assertSame('-10.00', $item->difference_amount);
        $this->assertSame(10, $item->first_count_confirmed_system_quantity);
        $this->assertSame(-3, $item->first_count_confirmed_difference_quantity);
        $this->assertSame('-30.00', $item->first_count_confirmed_difference_amount);
    }

    public function test_first_round_confirmation_copies_counted_items_to_second_round(): void
    {
        if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_count_items', 'ending_system_quantity')) {
            $this->markTestSkipped('wms_inventory_count_items.ending_system_quantity is not available.');
        }

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => '1回目確定テスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'current_count_round' => 1,
        ]);
        $targetCategoryItemId = $this->createItemInMajorCategory(1001);

        $matched = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $targetCategoryItemId,
            'item_code' => 'ROUND001',
            'item_name' => '終了理論一致',
            'system_quantity' => 10,
            'ending_system_quantity' => 8,
            'first_count_quantity' => 8,
            'difference_quantity' => 99,
            'difference_amount' => 990,
            'cost_price' => 10,
        ]);

        $different = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $targetCategoryItemId,
            'item_code' => 'ROUND002',
            'item_name' => '終了理論差異あり',
            'system_quantity' => 10,
            'ending_system_quantity' => 8,
            'first_count_quantity' => 7,
            'difference_quantity' => 99,
            'difference_amount' => 990,
            'cost_price' => 10,
        ]);

        $uncounted = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $targetCategoryItemId,
            'item_code' => 'ROUND003',
            'item_name' => '未入力',
            'system_quantity' => 10,
            'ending_system_quantity' => 8,
            'cost_price' => 10,
        ]);

        $page = new ViewWmsInventoryCount;
        $page->record = $inventoryCount;
        $page->activeCountRound = 1;

        $page->confirmRound(1);

        $this->assertSame(2, $inventoryCount->refresh()->current_count_round);
        $this->assertSame(2, $page->activeCountRound);
        $this->assertNotNull($inventoryCount->first_count_confirmed_at);
        $this->assertSame(8, $matched->refresh()->second_count_quantity);
        $this->assertSame(8, $matched->first_count_confirmed_system_quantity);
        $this->assertSame(0, $matched->first_count_confirmed_difference_quantity);
        $this->assertSame('0.00', $matched->first_count_confirmed_difference_amount);
        $this->assertSame(99, $matched->difference_quantity);
        $this->assertSame(7, $different->refresh()->second_count_quantity);
        $this->assertSame(8, $different->first_count_confirmed_system_quantity);
        $this->assertSame(-1, $different->first_count_confirmed_difference_quantity);
        $this->assertSame('-10.00', $different->first_count_confirmed_difference_amount);
        $this->assertSame(99, $different->difference_quantity);
        $this->assertNull($uncounted->refresh()->second_count_quantity);
        $this->assertNull($uncounted->first_count_confirmed_system_quantity);
        $this->assertNull($uncounted->first_count_confirmed_difference_quantity);
        $this->assertSame(1, $page->countForTab('matched'));
        $this->assertSame(2, $page->countForTab('diff'));
        $this->assertSame(0, $page->countForTab('unmanaged'));

        $different->update(['ending_system_quantity' => 12]);
        $page->setActiveCountRound(1);

        $this->assertSame(1, $page->activeCountRound);
        $this->assertSame(2, $page->countForTab('diff'));
        $this->assertSame(1, $page->countForTab('matched'));
        $this->assertSame(-1, $page->roundDifferenceForDisplay($different->refresh(), 1));

        $confirmedAt = $inventoryCount->refresh()->first_count_confirmed_at;
        $page->confirmRound(1);

        $this->assertEquals($confirmedAt, $inventoryCount->refresh()->first_count_confirmed_at);
        $this->assertSame(-1, $different->refresh()->first_count_confirmed_difference_quantity);
    }

    public function test_second_round_confirmation_adopts_first_quantity_when_second_is_blank(): void
    {
        foreach ([
            'ending_system_quantity',
            'second_count_confirmed_system_quantity',
            'second_count_confirmed_difference_quantity',
            'second_count_confirmed_difference_amount',
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
            'warehouse_name' => '2回目確定テスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'current_count_round' => 2,
            'first_count_confirmed_at' => now(),
        ]);
        $targetCategoryItemId = $this->createItemInMajorCategory(1001);

        $fallbackMatched = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $targetCategoryItemId,
            'item_code' => 'ROUND005',
            'item_name' => '2回目未入力1回目一致',
            'system_quantity' => 10,
            'ending_system_quantity' => 8,
            'first_count_quantity' => 8,
            'cost_price' => 10,
        ]);

        $fallbackDifferent = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $targetCategoryItemId,
            'item_code' => 'ROUND006',
            'item_name' => '2回目未入力1回目差異',
            'system_quantity' => 10,
            'ending_system_quantity' => 8,
            'first_count_quantity' => 7,
            'cost_price' => 10,
        ]);

        $secondDifferent = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $targetCategoryItemId,
            'item_code' => 'ROUND007',
            'item_name' => '2回目入力差異',
            'system_quantity' => 10,
            'ending_system_quantity' => 8,
            'first_count_quantity' => 7,
            'second_count_quantity' => 6,
            'cost_price' => 20,
        ]);

        $uncounted = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $targetCategoryItemId,
            'item_code' => 'ROUND008',
            'item_name' => '2回目未入力1回目なし',
            'system_quantity' => 10,
            'ending_system_quantity' => 8,
            'cost_price' => 10,
        ]);

        $page = new ViewWmsInventoryCount;
        $page->record = $inventoryCount;
        $page->activeCountRound = 2;

        $page->confirmRound(2);

        $this->assertSame(3, $inventoryCount->refresh()->current_count_round);
        $this->assertNotNull($inventoryCount->second_count_confirmed_at);
        $this->assertSame(8, $fallbackMatched->refresh()->second_count_confirmed_system_quantity);
        $this->assertSame(8, $fallbackMatched->second_count_quantity);
        $this->assertSame(0, $fallbackMatched->second_count_confirmed_difference_quantity);
        $this->assertSame('0.00', $fallbackMatched->second_count_confirmed_difference_amount);
        $this->assertSame(8, $fallbackMatched->final_count_quantity);
        $this->assertSame(8, $fallbackDifferent->refresh()->second_count_confirmed_system_quantity);
        $this->assertSame(7, $fallbackDifferent->second_count_quantity);
        $this->assertSame(-1, $fallbackDifferent->second_count_confirmed_difference_quantity);
        $this->assertSame('-10.00', $fallbackDifferent->second_count_confirmed_difference_amount);
        $this->assertSame(7, $fallbackDifferent->final_count_quantity);
        $this->assertSame(8, $secondDifferent->refresh()->second_count_confirmed_system_quantity);
        $this->assertSame(-2, $secondDifferent->second_count_confirmed_difference_quantity);
        $this->assertSame('-40.00', $secondDifferent->second_count_confirmed_difference_amount);
        $this->assertSame(6, $secondDifferent->final_count_quantity);
        $this->assertNull($uncounted->refresh()->second_count_confirmed_difference_quantity);

        $page->setActiveCountRound(2);

        $this->assertSame(3, $page->countForTab('diff'));
        $this->assertSame(1, $page->countForTab('matched'));
        $this->assertSame(0, $page->countForTab('unmanaged'));
        $this->assertSame(-1, $page->roundDifferenceForDisplay($fallbackDifferent->refresh(), 2));
        $this->assertSame(-8, $page->roundDifferenceForDisplay($uncounted->refresh(), 2));
    }

    public function test_reopen_final_round_clears_final_confirmed_difference_snapshot(): void
    {
        foreach ([
            'final_count_confirmed_system_quantity',
            'final_count_confirmed_difference_quantity',
            'final_count_confirmed_difference_amount',
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
            'warehouse_name' => '3回目再開テスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_CHECKED,
            'current_count_round' => 3,
            'final_count_confirmed_at' => now(),
        ]);

        $item = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999504,
            'item_code' => 'ROUND004',
            'item_name' => '3回目再開対象',
            'system_quantity' => 10,
            'ending_system_quantity' => 8,
            'final_count_quantity' => 7,
            'final_count_confirmed_system_quantity' => 8,
            'final_count_confirmed_difference_quantity' => -1,
            'final_count_confirmed_difference_amount' => -10,
            'cost_price' => 10,
        ]);

        $page = new ViewWmsInventoryCount;
        $page->record = $inventoryCount;
        $page->activeCountRound = 3;

        $page->reopenFinalRound();

        $this->assertSame(WmsInventoryCount::STATUS_COUNTING, $inventoryCount->refresh()->status);
        $this->assertNull($inventoryCount->final_count_confirmed_at);
        $this->assertNull($item->refresh()->final_count_confirmed_system_quantity);
        $this->assertNull($item->final_count_confirmed_difference_quantity);
        $this->assertNull($item->final_count_confirmed_difference_amount);
    }

    public function test_owned_set_items_are_excluded_from_inventory_count_rows(): void
    {
        if (! Schema::connection('sakemaru')->hasTable('item_sets')
            || ! Schema::connection('sakemaru')->hasColumn('items', 'item_set_id')
        ) {
            $this->markTestSkipped('item set tables are not available.');
        }

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => '自社セット除外テスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'current_count_round' => 1,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999901,
            'item_code' => 'VISIBLE001',
            'item_name' => '通常棚卸対象',
            'system_quantity' => 5,
            'ending_system_quantity' => 3,
            'first_count_quantity' => 5,
            'cost_price' => 10,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $this->createOwnedSetItem(),
            'item_code' => 'OWNEDSET001',
            'item_name' => '自社セット対象外',
            'system_quantity' => 5,
            'ending_system_quantity' => 3,
            'first_count_quantity' => 5,
            'cost_price' => 10,
        ]);

        $page = new ViewWmsInventoryCount;
        $page->record = $inventoryCount;
        $page->activeCountRound = 1;

        $this->assertSame(1, $page->totalCount());
        $this->assertSame(1, $page->countForTab('diff'));
        $this->assertSame(['VISIBLE001'], collect($page->rows()->items())->pluck('item_code')->all());
    }

    public function test_owned_set_items_are_excluded_from_inventory_count_logs(): void
    {
        if (! Schema::connection('sakemaru')->hasTable('item_sets')
            || ! Schema::connection('sakemaru')->hasColumn('items', 'item_set_id')
        ) {
            $this->markTestSkipped('item set tables are not available.');
        }

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => '自社セットログ除外テスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'current_count_round' => 1,
        ]);

        $visibleItem = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999902,
            'item_code' => 'VISIBLELOG',
            'item_name' => '通常ログ対象',
            'system_quantity' => 5,
            'ending_system_quantity' => 3,
            'first_count_quantity' => 4,
            'cost_price' => 10,
        ]);

        $ownedItem = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $this->createOwnedSetItem(),
            'item_code' => 'OWNEDLOG',
            'item_name' => '自社セットログ対象外',
            'system_quantity' => 5,
            'ending_system_quantity' => 3,
            'first_count_quantity' => 4,
            'cost_price' => 10,
        ]);

        WmsInventoryCountItemLog::create([
            'inventory_count_item_id' => $visibleItem->id,
            'device_id' => 'WEB',
            'user_id' => null,
            'count_round' => 1,
            'old_quantity' => null,
            'new_quantity' => 4,
            'request_uuid' => (string) Str::uuid(),
            'created_at' => now(),
        ]);

        WmsInventoryCountItemLog::create([
            'inventory_count_item_id' => $ownedItem->id,
            'device_id' => 'WEB',
            'user_id' => null,
            'count_round' => 1,
            'old_quantity' => null,
            'new_quantity' => 4,
            'request_uuid' => (string) Str::uuid(),
            'created_at' => now(),
        ]);

        $page = new ViewWmsInventoryCountLogs;
        $page->record = $inventoryCount;

        $this->assertSame(1, $page->totalLogCount());
        $this->assertSame([$visibleItem->id], collect($page->logs()->items())->pluck('inventory_count_item_id')->all());
    }

    public function test_inventory_count_logs_can_be_downloaded_as_filtered_excel(): void
    {
        $blade = file_get_contents(resource_path('views/filament/resources/wms-inventory-count/pages/view-wms-inventory-count-logs.blade.php'));

        $this->assertStringContainsString('wire:click="downloadExcel"', $blade);
        $this->assertStringContainsString('Excelダウンロード', $blade);

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'CSV-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 10,
            'warehouse_code' => '10',
            'warehouse_name' => 'ログCSVテスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'current_count_round' => 1,
        ]);

        $targetItem = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999903,
            'item_code' => 'CSV001',
            'item_name' => 'CSV出力対象商品',
            'system_quantity' => 5,
            'ending_system_quantity' => 3,
            'first_count_quantity' => 4,
            'cost_price' => 10,
        ]);

        $excludedItem = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999904,
            'item_code' => 'CSV999',
            'item_name' => 'CSVフィルタ対象外商品',
            'system_quantity' => 5,
            'ending_system_quantity' => 3,
            'first_count_quantity' => 4,
            'cost_price' => 10,
        ]);

        WmsInventoryCountItemLog::create([
            'inventory_count_item_id' => $targetItem->id,
            'device_id' => 'WEB',
            'user_id' => null,
            'count_round' => 1,
            'old_quantity' => 1,
            'new_quantity' => 4,
            'request_uuid' => (string) Str::uuid(),
            'created_at' => '2026-09-07 10:20:30',
        ]);

        WmsInventoryCountItemLog::create([
            'inventory_count_item_id' => $excludedItem->id,
            'device_id' => 'WEB',
            'user_id' => null,
            'count_round' => 1,
            'old_quantity' => 1,
            'new_quantity' => 4,
            'request_uuid' => (string) Str::uuid(),
            'created_at' => '2026-09-07 10:21:30',
        ]);

        $page = new ViewWmsInventoryCountLogs;
        $page->record = $inventoryCount;
        $page->itemCodeFilter = 'CSV001';

        $response = $page->downloadExcel();

        ob_start();
        $response->sendContent();
        $content = (string) ob_get_clean();

        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));

        $tempPath = tempnam(sys_get_temp_dir(), 'inventory-count-logs-test-');
        file_put_contents($tempPath, $content);

        try {
            $sheet = IOFactory::load($tempPath)->getActiveSheet();

            $this->assertSame('作業ログ', $sheet->getTitle());
            $this->assertSame(['入力時刻', '商品名', '商品コード', '倉庫コード', '入力数量', '作業者'], $sheet->rangeToArray('A1:F1')[0]);
            $this->assertSame('2026/09/07 10:20:30', $sheet->getCell('A2')->getValue());
            $this->assertSame('CSV出力対象商品', $sheet->getCell('B2')->getValue());
            $this->assertSame('CSV001', $sheet->getCell('C2')->getValue());
            $this->assertSame('10', $sheet->getCell('D2')->getValue());
            $this->assertSame(4.0, (float) $sheet->getCell('E2')->getValue());
            $this->assertSame('WEB', $sheet->getCell('F2')->getValue());
            $this->assertSame('', (string) $sheet->getCell('A3')->getValue());
        } finally {
            if (is_file($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    private function createItemInMajorCategory(int $majorCategoryCode, bool $managedStock = true): int
    {
        $majorCategoryId = DB::connection('sakemaru')->table('item_categories')->insertGetId([
            'client_id' => 1,
            'name' => '未カウント対象大分類'.$majorCategoryCode,
            'code' => $majorCategoryCode,
            'depth' => 1,
            'creator_id' => 1,
            'last_updater_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemData = [
            'name_main' => '未カウント対象'.Str::upper(Str::random(8)),
            'code' => random_int(800000000, 899999999),
            'type' => 'NOT_ALCOHOL',
            'manufacturer_id' => 0,
            'volume' => 1,
            'capacity_case' => 1,
            'creator_id' => 1,
            'packaging' => '1',
            'nickname' => '未カウント対象',
            'client_id' => 1,
            'item_set_id' => null,
            'item_category1_id' => $majorCategoryId,
            'item_category2_id' => 0,
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

    private function createOwnedSetItem(): int
    {
        $itemSetId = DB::connection('sakemaru')->table('item_sets')->insertGetId([
            'description' => '棚卸対象外自社セット',
            'set_type' => 'OWNED',
            'is_active' => true,
            'client_id' => 1,
            'creator_id' => 1,
            'last_updater_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::connection('sakemaru')->table('items')->insertGetId([
            'name_main' => '自社セット対象外'.Str::upper(Str::random(8)),
            'code' => random_int(800000000, 899999999),
            'type' => 'NOT_ALCOHOL',
            'manufacturer_id' => 0,
            'volume' => 1,
            'capacity_case' => 1,
            'creator_id' => 1,
            'packaging' => '1',
            'nickname' => '自社セット対象外',
            'client_id' => 1,
            'item_set_id' => $itemSetId,
            'item_category1_id' => 0,
            'item_category2_id' => 0,
            'container_type_id' => 0,
            'manufacture_type_id' => 0,
            'storage_type_id' => 0,
            'measurement_unit_weight' => 0,
            'measurement_case_weight' => 0,
            'order_rank' => 'ORDER_MANUAL',
            'last_updater_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
