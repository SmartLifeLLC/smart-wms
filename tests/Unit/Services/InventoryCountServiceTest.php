<?php

namespace Tests\Unit\Services;

use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use App\Services\InventoryCount\InventoryCountLedgerBalanceService;
use App\Services\InventoryCount\InventoryCountService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryCountServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['sakemaru'];

    public function test_ledger_quantity_scaling_uses_fixed_precision(): void
    {
        $service = new InventoryCountLedgerBalanceService;
        $toScaled = new \ReflectionMethod($service, 'quantityToScaled');
        $fromScaled = new \ReflectionMethod($service, 'scaledToQuantity');

        $total = $toScaled->invoke($service, '0.1')
            + $toScaled->invoke($service, '0.2')
            + $toScaled->invoke($service, '1.2344')
            - $toScaled->invoke($service, '1.2344');

        $this->assertSame(300, $total);
        $this->assertSame(-1235, $toScaled->invoke($service, '-1.2345'));
        $this->assertSame('0.300', number_format($fromScaled->invoke($service, $total), 3, '.', ''));
    }

    public function test_take_snapshot_uses_ledger_balance_for_starting_system_quantity(): void
    {
        if (! Schema::connection('sakemaru')->hasTable('stats_item_stock_opening_balances')) {
            $this->markTestSkipped('stats_item_stock_opening_balances table is not available.');
        }

        $items = $this->ledgerTestItems();
        if ($items->count() < 2) {
            $this->markTestSkipped('items table does not have enough ledger-testable rows.');
        }

        $clientId = (int) $items[0]->client_id;
        $warehouseId = 990127;
        $this->createOpeningBalance($clientId, $warehouseId, $items[0], 17);
        $this->createOpeningBalance($clientId, $warehouseId, $items[1], 8);

        $firstRealStockId = $this->createRealStock($items[0]->id, 99, $clientId, $warehouseId);
        $secondRealStockId = $this->createRealStock($items[0]->id, 44, $clientId, $warehouseId, 1);

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => $clientId,
            'warehouse_id' => $warehouseId,
            'warehouse_code' => (string) $warehouseId,
            'warehouse_name' => '開始理論在庫テスト倉庫',
            'count_date' => InventoryCountLedgerBalanceService::OPENING_DATE,
            'status' => WmsInventoryCount::STATUS_DRAFT,
        ]);

        $inserted = (new InventoryCountService)->takeSnapshot($inventoryCount);

        $inventoryCount->refresh();
        $stockRows = WmsInventoryCountItem::query()
            ->where('inventory_count_id', $inventoryCount->id)
            ->where('item_id', $items[0]->id)
            ->orderBy('real_stock_id')
            ->get();
        $missingLedgerRow = WmsInventoryCountItem::query()
            ->where('inventory_count_id', $inventoryCount->id)
            ->where('item_id', $items[1]->id)
            ->first();

        $this->assertSame(3, $inserted);
        $this->assertNotNull($inventoryCount->snapshot_taken_at);
        $this->assertNotNull($inventoryCount->ending_stock_taken_at);
        $this->assertCount(2, $stockRows);
        $this->assertSame($firstRealStockId, (int) $stockRows[0]->real_stock_id);
        $this->assertSame($secondRealStockId, (int) $stockRows[1]->real_stock_id);
        $this->assertSame(17, (int) $stockRows[0]->system_quantity);
        $this->assertSame(0, (int) $stockRows[1]->system_quantity);
        $this->assertSame(17, (int) $stockRows[0]->ending_system_quantity);
        $this->assertSame(0, (int) $stockRows[1]->ending_system_quantity);
        $this->assertSame(17, (int) $stockRows->sum('system_quantity'));
        $this->assertNotNull($missingLedgerRow);
        $this->assertNull($missingLedgerRow->real_stock_id);
        $this->assertSame(8, $missingLedgerRow->system_quantity);
        $this->assertSame(8, $missingLedgerRow->ending_system_quantity);
    }

    public function test_take_snapshot_includes_zero_real_stock_without_active_lot(): void
    {
        $items = $this->ledgerTestItems();
        if ($items->isEmpty()) {
            $this->markTestSkipped('items table does not have enough ledger-testable rows.');
        }

        $item = $items[0];
        $clientId = (int) $item->client_id;
        $warehouseId = 990130;
        $realStockId = $this->createRealStock($item->id, 0, $clientId, $warehouseId);

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => $clientId,
            'warehouse_id' => $warehouseId,
            'warehouse_code' => (string) $warehouseId,
            'warehouse_name' => 'ゼロ在庫行生成テスト倉庫',
            'count_date' => InventoryCountLedgerBalanceService::OPENING_DATE,
            'status' => WmsInventoryCount::STATUS_DRAFT,
        ]);

        $inserted = (new InventoryCountService)->takeSnapshot($inventoryCount);

        $countItem = WmsInventoryCountItem::query()
            ->where('inventory_count_id', $inventoryCount->id)
            ->where('real_stock_id', $realStockId)
            ->first();

        $this->assertSame(1, $inserted);
        $this->assertNotNull($countItem);
        $this->assertSame($item->id, $countItem->item_id);
        $this->assertSame(0, $countItem->system_quantity);
        $this->assertSame(0, $countItem->ending_system_quantity);
    }

    public function test_add_single_item_uses_ledger_balance_for_starting_system_quantity(): void
    {
        if (! Schema::connection('sakemaru')->hasTable('stats_item_stock_opening_balances')) {
            $this->markTestSkipped('stats_item_stock_opening_balances table is not available.');
        }

        $items = $this->ledgerTestItems();
        if ($items->count() < 2) {
            $this->markTestSkipped('items table does not have enough ledger-testable rows.');
        }

        $stockItem = $items[0];
        $ledgerOnlyItem = $items[1];
        $clientId = (int) $stockItem->client_id;
        $warehouseId = 990128;
        $this->createOpeningBalance($clientId, $warehouseId, $stockItem, 23);
        $this->createOpeningBalance($clientId, $warehouseId, $ledgerOnlyItem, 11);
        $realStockId = $this->createRealStock($stockItem->id, 2, $clientId, $warehouseId);

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => $clientId,
            'warehouse_id' => $warehouseId,
            'warehouse_code' => (string) $warehouseId,
            'warehouse_name' => '単品追加理論在庫テスト倉庫',
            'count_date' => InventoryCountLedgerBalanceService::OPENING_DATE,
            'status' => WmsInventoryCount::STATUS_COUNTING,
        ]);

        $service = new InventoryCountService;
        $result = $service->addSingleItemByCode($inventoryCount, (string) $stockItem->code);
        $ledgerOnlyResult = $service->addSingleItemByCode($inventoryCount, (string) $ledgerOnlyItem->code);

        $insertedItem = WmsInventoryCountItem::query()
            ->where('inventory_count_id', $inventoryCount->id)
            ->where('real_stock_id', $realStockId)
            ->first();
        $insertedLedgerOnlyItem = WmsInventoryCountItem::query()
            ->where('inventory_count_id', $inventoryCount->id)
            ->where('item_id', $ledgerOnlyItem->id)
            ->first();

        $this->assertSame(1, $result['inserted_count']);
        $this->assertSame(0, $result['existing_count']);
        $this->assertNotNull($insertedItem);
        $this->assertSame(23, $insertedItem->system_quantity);
        $this->assertSame(23, $insertedItem->ending_system_quantity);
        $this->assertSame(1, $ledgerOnlyResult['inserted_count']);
        $this->assertSame(0, $ledgerOnlyResult['existing_count']);
        $this->assertNotNull($insertedLedgerOnlyItem);
        $this->assertNull($insertedLedgerOnlyItem->real_stock_id);
        $this->assertSame(11, $insertedLedgerOnlyItem->system_quantity);
        $this->assertSame(11, $insertedLedgerOnlyItem->ending_system_quantity);
    }

    public function test_take_snapshot_excludes_owned_set_items(): void
    {
        if (! Schema::connection('sakemaru')->hasTable('stats_item_stock_opening_balances')) {
            $this->markTestSkipped('stats_item_stock_opening_balances table is not available.');
        }

        if (! Schema::connection('sakemaru')->hasTable('item_sets')
            || ! Schema::connection('sakemaru')->hasColumn('items', 'item_set_id')
        ) {
            $this->markTestSkipped('item set tables are not available.');
        }

        $items = $this->ledgerTestItems();
        if ($items->isEmpty()) {
            $this->markTestSkipped('items table does not have enough ledger-testable rows.');
        }

        $clientId = (int) $items[0]->client_id;
        $warehouseId = 990129;
        $ownedSetItemId = $this->createOwnedSetItem($clientId);

        $this->createOpeningBalance($clientId, $warehouseId, $items[0], 17);
        $this->createOpeningBalance($clientId, $warehouseId, (object) [
            'id' => $ownedSetItemId,
            'code' => 'OWNED-SET-TEST',
            'name' => '自社セット対象外',
        ], 9);

        $this->createRealStock($items[0]->id, 3, $clientId, $warehouseId);
        $this->createRealStock($ownedSetItemId, 9, $clientId, $warehouseId);

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => $clientId,
            'warehouse_id' => $warehouseId,
            'warehouse_code' => (string) $warehouseId,
            'warehouse_name' => '自社セット除外テスト倉庫',
            'count_date' => InventoryCountLedgerBalanceService::OPENING_DATE,
            'status' => WmsInventoryCount::STATUS_DRAFT,
        ]);

        (new InventoryCountService)->takeSnapshot($inventoryCount);

        $this->assertTrue(WmsInventoryCountItem::query()
            ->where('inventory_count_id', $inventoryCount->id)
            ->where('item_id', $items[0]->id)
            ->exists());
        $this->assertFalse(WmsInventoryCountItem::query()
            ->where('inventory_count_id', $inventoryCount->id)
            ->where('item_id', $ownedSetItemId)
            ->exists());
    }

    public function test_take_snapshot_excludes_unmanaged_stock_items(): void
    {
        if (! Schema::connection('sakemaru')->hasColumn('items', 'is_managed_stock')) {
            $this->markTestSkipped('items.is_managed_stock is not available.');
        }

        $items = $this->ledgerTestItems();
        if ($items->isEmpty()) {
            $this->markTestSkipped('items table does not have enough ledger-testable rows.');
        }

        $clientId = (int) $items[0]->client_id;
        $warehouseId = 990133;
        $unmanagedItemId = $this->createUnmanagedStockItem($clientId);

        $this->createRealStock($items[0]->id, 3, $clientId, $warehouseId);
        $this->createRealStock($unmanagedItemId, 9, $clientId, $warehouseId);

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => $clientId,
            'warehouse_id' => $warehouseId,
            'warehouse_code' => (string) $warehouseId,
            'warehouse_name' => '在庫管理対象外除外テスト倉庫',
            'count_date' => InventoryCountLedgerBalanceService::OPENING_DATE,
            'status' => WmsInventoryCount::STATUS_DRAFT,
        ]);

        (new InventoryCountService)->takeSnapshot($inventoryCount);

        $this->assertTrue(WmsInventoryCountItem::query()
            ->where('inventory_count_id', $inventoryCount->id)
            ->where('item_id', $items[0]->id)
            ->exists());
        $this->assertFalse(WmsInventoryCountItem::query()
            ->where('inventory_count_id', $inventoryCount->id)
            ->where('item_id', $unmanagedItemId)
            ->exists());
    }

    public function test_save_current_stock_only_marks_status_and_does_not_update_count_items(): void
    {
        $realStockId = $this->createRealStock(999001, 99);

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => '小浜店',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_DRAFT,
        ]);

        $item = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => $realStockId,
            'item_id' => 999001,
            'item_code' => '999001',
            'item_name' => 'テスト商品',
            'system_quantity' => 1,
            'final_count_quantity' => 3,
            'difference_quantity' => 2,
            'cost_price' => 10,
            'difference_amount' => 20,
        ]);

        (new InventoryCountService)->saveCurrentStock($inventoryCount);

        $inventoryCount->refresh();
        $item->refresh();

        $this->assertTrue($inventoryCount->isCurrentStockSaved());
        $this->assertSame(WmsInventoryCount::STATUS_CURRENT_STOCK_SAVED, $inventoryCount->display_status);
        $this->assertSame(1, $item->system_quantity);
        $this->assertSame(3, $item->final_count_quantity);
        $this->assertSame(2, $item->difference_quantity);
        $this->assertSame('20.00', $item->difference_amount);
    }

    public function test_resume_current_stock_saved_for_counting_only_clears_saved_flag(): void
    {
        $realStockId = $this->createRealStock(999003, 99);

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => '小浜店',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'started_at' => now()->subHour(),
            'current_stock_saved_at' => now(),
        ]);

        $item = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => $realStockId,
            'item_id' => 999003,
            'item_code' => '999003',
            'item_name' => 'テスト商品3',
            'system_quantity' => 1,
            'final_count_quantity' => 3,
            'difference_quantity' => 2,
            'cost_price' => 10,
            'difference_amount' => 20,
        ]);

        (new InventoryCountService)->resumeCurrentStockSavedForCounting($inventoryCount);

        $inventoryCount->refresh();
        $item->refresh();

        $this->assertSame(WmsInventoryCount::STATUS_COUNTING, $inventoryCount->status);
        $this->assertFalse($inventoryCount->isCurrentStockSaved());
        $this->assertTrue($inventoryCount->canRefreshSystemQuantities());
        $this->assertNull($inventoryCount->current_stock_saved_at);
        $this->assertSame(1, $item->system_quantity);
        $this->assertSame(3, $item->final_count_quantity);
        $this->assertSame(2, $item->difference_quantity);
        $this->assertSame('20.00', $item->difference_amount);
    }

    public function test_refresh_system_quantities_saves_ending_quantities_and_adds_new_rows_with_start_zero(): void
    {
        foreach ([
            'wms_inventory_counts' => 'ending_stock_taken_at',
            'wms_inventory_count_items' => 'ending_system_quantity',
        ] as $table => $column) {
            if (! Schema::connection('sakemaru')->hasColumn($table, $column)) {
                $this->markTestSkipped("{$table}.{$column} is not available.");
            }
        }

        $itemIds = DB::connection('sakemaru')
            ->table('items')
            ->orderBy('id')
            ->limit(2)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($itemIds->count() < 2) {
            $this->markTestSkipped('items table does not have enough rows.');
        }

        $warehouseId = 990022;
        $existingRealStockId = $this->createRealStock($itemIds[0], 9, 1, $warehouseId);
        $newRealStockId = $this->createRealStock($itemIds[1], 12, 1, $warehouseId);

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => $warehouseId,
            'warehouse_code' => (string) $warehouseId,
            'warehouse_name' => '終了時在庫テスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
        ]);

        $existingItem = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => $existingRealStockId,
            'item_id' => $itemIds[0],
            'item_code' => '999101',
            'item_name' => '既存明細商品',
            'system_quantity' => 1,
            'final_count_quantity' => 3,
            'difference_quantity' => 2,
            'cost_price' => 10,
            'difference_amount' => 20,
        ]);

        $result = (new InventoryCountService)->refreshSystemQuantities($inventoryCount);

        $inventoryCount->refresh();
        $existingItem->refresh();
        $insertedItem = WmsInventoryCountItem::query()
            ->where('inventory_count_id', $inventoryCount->id)
            ->where('real_stock_id', $newRealStockId)
            ->first();

        $this->assertSame(1, $result['updated_items']);
        $this->assertSame(1, $result['inserted_items']);
        $this->assertSame(0, $result['missing_real_stocks']);
        $this->assertNotNull($inventoryCount->ending_stock_taken_at);
        $this->assertSame(1, $existingItem->system_quantity);
        $this->assertSame(9, $existingItem->ending_system_quantity);
        $this->assertSame(3, $existingItem->final_count_quantity);
        $this->assertSame(2, $existingItem->difference_quantity);

        $this->assertNotNull($insertedItem);
        $this->assertSame(0, $insertedItem->system_quantity);
        $this->assertSame(12, $insertedItem->ending_system_quantity);
    }

    public function test_refresh_system_quantities_excludes_owned_set_items(): void
    {
        foreach ([
            'wms_inventory_counts' => 'ending_stock_taken_at',
            'wms_inventory_count_items' => 'ending_system_quantity',
        ] as $table => $column) {
            if (! Schema::connection('sakemaru')->hasColumn($table, $column)) {
                $this->markTestSkipped("{$table}.{$column} is not available.");
            }
        }

        if (! Schema::connection('sakemaru')->hasTable('item_sets')
            || ! Schema::connection('sakemaru')->hasColumn('items', 'item_set_id')
        ) {
            $this->markTestSkipped('item set tables are not available.');
        }

        $items = $this->ledgerTestItems();
        if ($items->isEmpty()) {
            $this->markTestSkipped('items table does not have enough ledger-testable rows.');
        }

        $clientId = (int) $items[0]->client_id;
        $warehouseId = 990023;
        $visibleRealStockId = $this->createRealStock((int) $items[0]->id, 9, $clientId, $warehouseId);
        $ownedExistingItemId = $this->createOwnedSetItem($clientId);
        $ownedExistingRealStockId = $this->createRealStock($ownedExistingItemId, 15, $clientId, $warehouseId);
        $ownedMissingItemId = $this->createOwnedSetItem($clientId);
        $ownedMissingRealStockId = $this->createRealStock($ownedMissingItemId, 21, $clientId, $warehouseId);

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => $clientId,
            'warehouse_id' => $warehouseId,
            'warehouse_code' => (string) $warehouseId,
            'warehouse_name' => '終了時在庫自社セット除外テスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
        ]);

        $visibleItem = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => $visibleRealStockId,
            'item_id' => (int) $items[0]->id,
            'item_code' => (string) $items[0]->code,
            'item_name' => (string) $items[0]->name,
            'system_quantity' => 1,
            'ending_system_quantity' => 2,
            'cost_price' => 10,
        ]);

        $ownedExistingItem = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => $ownedExistingRealStockId,
            'item_id' => $ownedExistingItemId,
            'item_code' => 'OWNED-EXISTING',
            'item_name' => '既存自社セット対象外',
            'system_quantity' => 1,
            'ending_system_quantity' => 2,
            'cost_price' => 10,
        ]);

        $result = (new InventoryCountService)->refreshSystemQuantities($inventoryCount);

        $visibleItem->refresh();
        $ownedExistingItem->refresh();

        $this->assertSame(1, $result['updated_items']);
        $this->assertSame(0, $result['inserted_items']);
        $this->assertSame(9, $visibleItem->ending_system_quantity);
        $this->assertSame(2, $ownedExistingItem->ending_system_quantity);
        $this->assertFalse(WmsInventoryCountItem::query()
            ->where('inventory_count_id', $inventoryCount->id)
            ->where('real_stock_id', $ownedMissingRealStockId)
            ->exists());
    }

    public function test_refresh_system_quantities_excludes_unmanaged_stock_items(): void
    {
        foreach ([
            'wms_inventory_counts' => 'ending_stock_taken_at',
            'wms_inventory_count_items' => 'ending_system_quantity',
        ] as $table => $column) {
            if (! Schema::connection('sakemaru')->hasColumn($table, $column)) {
                $this->markTestSkipped("{$table}.{$column} is not available.");
            }
        }

        if (! Schema::connection('sakemaru')->hasColumn('items', 'is_managed_stock')) {
            $this->markTestSkipped('items.is_managed_stock is not available.');
        }

        $items = $this->ledgerTestItems();
        if ($items->isEmpty()) {
            $this->markTestSkipped('items table does not have enough ledger-testable rows.');
        }

        $clientId = (int) $items[0]->client_id;
        $warehouseId = 990134;
        $visibleRealStockId = $this->createRealStock((int) $items[0]->id, 9, $clientId, $warehouseId);
        $unmanagedExistingItemId = $this->createUnmanagedStockItem($clientId);
        $unmanagedExistingRealStockId = $this->createRealStock($unmanagedExistingItemId, 15, $clientId, $warehouseId);
        $unmanagedMissingItemId = $this->createUnmanagedStockItem($clientId);
        $unmanagedMissingRealStockId = $this->createRealStock($unmanagedMissingItemId, 21, $clientId, $warehouseId);

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => $clientId,
            'warehouse_id' => $warehouseId,
            'warehouse_code' => (string) $warehouseId,
            'warehouse_name' => '終了時在庫管理対象外除外テスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
        ]);

        $visibleItem = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => $visibleRealStockId,
            'item_id' => (int) $items[0]->id,
            'item_code' => (string) $items[0]->code,
            'item_name' => (string) $items[0]->name,
            'system_quantity' => 1,
            'ending_system_quantity' => 2,
            'cost_price' => 10,
        ]);

        $unmanagedExistingItem = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => $unmanagedExistingRealStockId,
            'item_id' => $unmanagedExistingItemId,
            'item_code' => 'UNMANAGED-EXISTING',
            'item_name' => '既存在庫管理対象外',
            'system_quantity' => 1,
            'ending_system_quantity' => 2,
            'cost_price' => 10,
        ]);

        $result = (new InventoryCountService)->refreshSystemQuantities($inventoryCount);

        $visibleItem->refresh();
        $unmanagedExistingItem->refresh();

        $this->assertSame(1, $result['updated_items']);
        $this->assertSame(0, $result['inserted_items']);
        $this->assertSame(9, $visibleItem->ending_system_quantity);
        $this->assertSame(2, $unmanagedExistingItem->ending_system_quantity);
        $this->assertFalse(WmsInventoryCountItem::query()
            ->where('inventory_count_id', $inventoryCount->id)
            ->where('real_stock_id', $unmanagedMissingRealStockId)
            ->exists());
    }

    public function test_refresh_system_quantities_from_daily_snapshot_uses_latest_snapshot_on_or_before_selected_date(): void
    {
        if (! Schema::connection('sakemaru')->hasTable('real_stock_daily_snapshots')) {
            $this->markTestSkipped('real_stock_daily_snapshots table is not available.');
        }

        $realStockId = $this->createRealStock(999002, 99);
        $now = now();

        DB::connection('sakemaru')->table('real_stock_daily_snapshots')->insert([
            [
                'snapshot_date' => '2026-06-15',
                'snapshot_at' => '2026-06-15 02:00:00',
                'real_stock_id' => $realStockId,
                'warehouse_id' => 22,
                'warehouse_code' => '22',
                'stock_allocation_id' => 0,
                'item_id' => 999002,
                'item_code' => '999002',
                'current_quantity' => 7,
                'reserved_quantity' => 0,
                'available_quantity' => 7,
                'real_stock_updated_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'snapshot_date' => '2026-06-17',
                'snapshot_at' => '2026-06-17 02:00:00',
                'real_stock_id' => $realStockId + 1000000000,
                'warehouse_id' => 22,
                'warehouse_code' => '22',
                'stock_allocation_id' => 0,
                'item_id' => 999999,
                'item_code' => '999999',
                'current_quantity' => 123,
                'reserved_quantity' => 0,
                'available_quantity' => 123,
                'real_stock_updated_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => '小浜店',
            'count_date' => '2026-06-17',
            'status' => WmsInventoryCount::STATUS_COUNTING,
        ]);

        $item = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => $realStockId,
            'item_id' => 999002,
            'item_code' => '999002',
            'item_name' => 'テスト商品2',
            'system_quantity' => 1,
            'final_count_quantity' => 10,
            'difference_quantity' => 9,
            'cost_price' => 2,
            'difference_amount' => 18,
        ]);

        $result = (new InventoryCountService)->refreshSystemQuantitiesFromDailySnapshot($inventoryCount, '2026-06-17');

        $item->refresh();

        $this->assertSame('2026-06-17', $result['snapshot_date']);
        $this->assertSame(1, $result['updated_items']);
        $this->assertSame(1, $result['updated_differences']);
        $this->assertSame(0, $result['missing_snapshot_rows']);
        $this->assertSame(7, $item->system_quantity);
        $this->assertSame(10, $item->final_count_quantity);
        $this->assertSame(3, $item->difference_quantity);
        $this->assertSame('6.00', $item->difference_amount);
    }

    public function test_refresh_ending_system_quantities_from_ledger_updates_ending_only_and_adds_missing_items(): void
    {
        foreach ([
            'wms_inventory_counts' => 'ending_stock_taken_at',
            'wms_inventory_count_items' => 'ending_system_quantity',
        ] as $table => $column) {
            if (! Schema::connection('sakemaru')->hasColumn($table, $column)) {
                $this->markTestSkipped("{$table}.{$column} is not available.");
            }
        }

        foreach ([
            'wms_inventory_count_theory_update_runs',
            'wms_inventory_count_theory_update_rows',
        ] as $table) {
            if (! Schema::connection('sakemaru')->hasTable($table)) {
                $this->markTestSkipped("{$table} is not available.");
            }
        }

        if (! Schema::connection('sakemaru')->hasTable('stats_item_stock_opening_balances')) {
            $this->markTestSkipped('stats_item_stock_opening_balances table is not available.');
        }

        $items = $this->ledgerTestItems();
        if ($items->count() < 2) {
            $this->markTestSkipped('items table does not have enough ledger-testable rows.');
        }

        $clientId = (int) $items[0]->client_id;
        $warehouseId = 990126;
        $now = now();

        DB::connection('sakemaru')->table('stats_item_stock_opening_balances')->insert([
            [
                'client_id' => $clientId,
                'opening_date' => InventoryCountLedgerBalanceService::OPENING_DATE,
                'source_database' => 'phpunit',
                'warehouse_id' => $warehouseId,
                'warehouse_code' => (string) $warehouseId,
                'warehouse_name' => '棚卸理論在庫テスト倉庫',
                'item_id' => $items[0]->id,
                'item_code' => (string) $items[0]->code,
                'item_name' => (string) $items[0]->name,
                'stock_allocation_id' => 0,
                'opening_quantity' => 17,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'client_id' => $clientId,
                'opening_date' => InventoryCountLedgerBalanceService::OPENING_DATE,
                'source_database' => 'phpunit',
                'warehouse_id' => $warehouseId,
                'warehouse_code' => (string) $warehouseId,
                'warehouse_name' => '棚卸理論在庫テスト倉庫',
                'item_id' => $items[1]->id,
                'item_code' => (string) $items[1]->code,
                'item_name' => (string) $items[1]->name,
                'stock_allocation_id' => 0,
                'opening_quantity' => 8,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => $clientId,
            'warehouse_id' => $warehouseId,
            'warehouse_code' => (string) $warehouseId,
            'warehouse_name' => '棚卸理論在庫テスト倉庫',
            'count_date' => InventoryCountLedgerBalanceService::OPENING_DATE,
            'status' => WmsInventoryCount::STATUS_COUNTING,
        ]);

        $existingItem = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $items[0]->id,
            'item_code' => (string) $items[0]->code,
            'item_name' => (string) $items[0]->name,
            'system_quantity' => 5,
            'ending_system_quantity' => 2,
            'final_count_quantity' => 6,
            'difference_quantity' => 1,
            'cost_price' => 10,
            'difference_amount' => 10,
        ]);

        $result = (new InventoryCountService)->refreshEndingSystemQuantitiesFromLedger(
            $inventoryCount,
            InventoryCountLedgerBalanceService::OPENING_DATE,
        );

        $inventoryCount->refresh();
        $existingItem->refresh();
        $insertedItem = WmsInventoryCountItem::query()
            ->where('inventory_count_id', $inventoryCount->id)
            ->where('item_id', $items[1]->id)
            ->first();

        $this->assertSame(1, $result['updated_items']);
        $this->assertSame(1, $result['inserted_items']);
        $this->assertIsInt($result['backup_run_id']);
        $this->assertSame(1, $result['backed_up_existing_rows']);
        $this->assertSame(1, $result['backed_up_inserted_rows']);
        $this->assertNotNull($inventoryCount->ending_stock_taken_at);
        $this->assertSame(5, $existingItem->system_quantity);
        $this->assertSame(17, $existingItem->ending_system_quantity);
        $this->assertSame(6, $existingItem->final_count_quantity);
        $this->assertSame(1, $existingItem->difference_quantity);

        $this->assertNotNull($insertedItem);
        $this->assertSame(0, $insertedItem->system_quantity);
        $this->assertSame(8, $insertedItem->ending_system_quantity);

        $run = DB::connection('sakemaru')
            ->table('wms_inventory_count_theory_update_runs')
            ->where('id', $result['backup_run_id'])
            ->first();

        $this->assertNotNull($run);
        $this->assertSame('finished', $run->status);
        $this->assertSame($inventoryCount->id, (int) $run->inventory_count_id);
        $this->assertSame(1, (int) $run->updated_items);
        $this->assertSame(1, (int) $run->inserted_items);

        $existingBackup = DB::connection('sakemaru')
            ->table('wms_inventory_count_theory_update_rows')
            ->where('run_id', $result['backup_run_id'])
            ->where('inventory_count_item_id', $existingItem->id)
            ->first();

        $this->assertNotNull($existingBackup);
        $this->assertSame(1, (int) $existingBackup->was_existing);
        $this->assertSame('2.000', (string) $existingBackup->old_ending_system_quantity);
        $this->assertSame('17.000', (string) $existingBackup->new_ending_system_quantity);

        $insertedBackup = DB::connection('sakemaru')
            ->table('wms_inventory_count_theory_update_rows')
            ->where('run_id', $result['backup_run_id'])
            ->where('inventory_count_item_id', $insertedItem->id)
            ->first();

        $this->assertNotNull($insertedBackup);
        $this->assertSame(0, (int) $insertedBackup->was_existing);
        $this->assertNull($insertedBackup->old_ending_system_quantity);
        $this->assertSame('8.000', (string) $insertedBackup->new_ending_system_quantity);
    }

    public function test_ledger_balance_counts_only_delivered_transfer_inbound_by_delivered_date(): void
    {
        foreach ([
            'trades',
            'stock_transfers',
            'trade_items',
        ] as $table) {
            if (! Schema::connection('sakemaru')->hasTable($table)) {
                $this->markTestSkipped("{$table} is not available.");
            }
        }

        $items = $this->ledgerTestItems();
        if ($items->isEmpty()) {
            $this->markTestSkipped('items table does not have enough ledger-testable rows.');
        }

        $item = $items[0];
        $clientId = (int) $item->client_id;
        $warehouseId = 990135;
        $fromWarehouseId = 990136;
        $endDate = InventoryCountLedgerBalanceService::OPENING_DATE;

        $this->createStockTransferMovement($clientId, $item, $fromWarehouseId, $warehouseId, 5, $endDate, true, $endDate);
        $this->createStockTransferMovement($clientId, $item, $fromWarehouseId, $warehouseId, 7, $endDate, false, $endDate);
        $this->createStockTransferMovement($clientId, $item, $fromWarehouseId, $warehouseId, 11, $endDate, true, CarbonImmutable::parse($endDate)->addDay()->toDateString());

        $balances = (new InventoryCountLedgerBalanceService)->balancesByItem($clientId, $warehouseId, $endDate);

        $this->assertSame(5.0, $balances[(int) $item->id] ?? null);
    }

    public function test_ledger_balance_counts_transfer_outbound_by_picking_date_with_process_date_fallback(): void
    {
        foreach ([
            'trades',
            'stock_transfers',
            'trade_items',
        ] as $table) {
            if (! Schema::connection('sakemaru')->hasTable($table)) {
                $this->markTestSkipped("{$table} is not available.");
            }
        }

        $items = $this->ledgerTestItems();
        if ($items->isEmpty()) {
            $this->markTestSkipped('items table does not have enough ledger-testable rows.');
        }

        $item = $items[0];
        $clientId = (int) $item->client_id;
        $warehouseId = 990137;
        $toWarehouseId = 990138;
        $endDate = InventoryCountLedgerBalanceService::OPENING_DATE;
        $nextDate = CarbonImmutable::parse($endDate)->addDay()->toDateString();

        $this->createStockTransferMovement($clientId, $item, $warehouseId, $toWarehouseId, 5, $nextDate, true, $nextDate, $endDate);
        $this->createStockTransferMovement($clientId, $item, $warehouseId, $toWarehouseId, 7, $endDate, true, $endDate, $nextDate);
        $this->createStockTransferMovement($clientId, $item, $warehouseId, $toWarehouseId, 3, $nextDate, true, $endDate, null);

        $balances = (new InventoryCountLedgerBalanceService)->balancesByItem($clientId, $warehouseId, $endDate);

        $this->assertSame(-5.0, $balances[(int) $item->id] ?? null);
    }

    public function test_ledger_balance_counts_owned_set_components_from_lot_earnings(): void
    {
        foreach ([
            'trades',
            'earnings',
            'trade_items',
            'item_sets',
            'real_stock_lots',
            'real_stock_lot_earnings',
        ] as $table) {
            if (! Schema::connection('sakemaru')->hasTable($table)) {
                $this->markTestSkipped("{$table} is not available.");
            }
        }

        $items = $this->ledgerTestItems();
        if ($items->isEmpty()) {
            $this->markTestSkipped('items table does not have enough ledger-testable rows.');
        }

        $component = $items[0];
        $clientId = (int) $component->client_id;
        $warehouseId = 990139;
        $endDate = InventoryCountLedgerBalanceService::OPENING_DATE;

        $realStockId = $this->createRealStock((int) $component->id, 25, $clientId, $warehouseId);
        $realStockLotId = $this->createRealStockLot($realStockId, 25);
        $setParentId = $this->createOwnedSetItem($clientId);

        $this->createOwnedSetEarningAllocation($clientId, $warehouseId, $setParentId, $realStockLotId, 5, $endDate);

        $balances = (new InventoryCountLedgerBalanceService)->balancesByItem($clientId, $warehouseId, $endDate);

        $this->assertSame(-5.0, $balances[(int) $component->id] ?? null);
    }

    public function test_ledger_balance_prefers_owned_set_lot_earnings_over_current_recipe(): void
    {
        foreach ([
            'trades',
            'earnings',
            'trade_items',
            'item_sets',
            'item_set_details',
            'real_stock_lots',
            'real_stock_lot_earnings',
        ] as $table) {
            if (! Schema::connection('sakemaru')->hasTable($table)) {
                $this->markTestSkipped("{$table} is not available.");
            }
        }

        $items = $this->ledgerTestItems();
        if ($items->isEmpty()) {
            $this->markTestSkipped('items table does not have enough ledger-testable rows.');
        }

        $component = $items[0];
        $clientId = (int) $component->client_id;
        $warehouseId = 990140;
        $endDate = InventoryCountLedgerBalanceService::OPENING_DATE;

        $realStockId = $this->createRealStock((int) $component->id, 25, $clientId, $warehouseId);
        $realStockLotId = $this->createRealStockLot($realStockId, 25);
        $setParentId = $this->createOwnedSetItem($clientId);
        $setParent = DB::connection('sakemaru')->table('items')->where('id', $setParentId)->first();

        DB::connection('sakemaru')->table('item_set_details')->insert([
            'item_set_id' => $setParent->item_set_id,
            'item_id' => (int) $component->id,
            'quantity_case' => 0,
            'quantity_piece' => 99,
            'is_active' => true,
            'client_id' => $clientId,
            'creator_id' => 1,
            'last_updater_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createOwnedSetEarningAllocation($clientId, $warehouseId, $setParentId, $realStockLotId, 5, $endDate);

        $balances = (new InventoryCountLedgerBalanceService)->balancesByItem($clientId, $warehouseId, $endDate);

        $this->assertSame(-5.0, $balances[(int) $component->id] ?? null);
    }

    public function test_refresh_ending_system_quantities_from_ledger_excludes_owned_set_items(): void
    {
        foreach ([
            'wms_inventory_counts' => 'ending_stock_taken_at',
            'wms_inventory_count_items' => 'ending_system_quantity',
        ] as $table => $column) {
            if (! Schema::connection('sakemaru')->hasColumn($table, $column)) {
                $this->markTestSkipped("{$table}.{$column} is not available.");
            }
        }

        foreach ([
            'wms_inventory_count_theory_update_runs',
            'wms_inventory_count_theory_update_rows',
        ] as $table) {
            if (! Schema::connection('sakemaru')->hasTable($table)) {
                $this->markTestSkipped("{$table} is not available.");
            }
        }

        if (! Schema::connection('sakemaru')->hasTable('stats_item_stock_opening_balances')) {
            $this->markTestSkipped('stats_item_stock_opening_balances table is not available.');
        }

        if (! Schema::connection('sakemaru')->hasTable('item_sets')
            || ! Schema::connection('sakemaru')->hasColumn('items', 'item_set_id')
        ) {
            $this->markTestSkipped('item set tables are not available.');
        }

        $items = $this->ledgerTestItems();
        if ($items->isEmpty()) {
            $this->markTestSkipped('items table does not have enough ledger-testable rows.');
        }

        $clientId = (int) $items[0]->client_id;
        $warehouseId = 990132;
        $ownedItemId = $this->createOwnedSetItem($clientId);

        $this->createOpeningBalance($clientId, $warehouseId, $items[0], 17);
        $this->createOpeningBalance($clientId, $warehouseId, (object) [
            'id' => $ownedItemId,
            'code' => 'OWNED-LEDGER',
            'name' => '自社セット受払対象外',
        ], 9);

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => $clientId,
            'warehouse_id' => $warehouseId,
            'warehouse_code' => (string) $warehouseId,
            'warehouse_name' => '理論在庫自社セット除外テスト倉庫',
            'count_date' => InventoryCountLedgerBalanceService::OPENING_DATE,
            'status' => WmsInventoryCount::STATUS_COUNTING,
        ]);

        $visibleItem = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $items[0]->id,
            'item_code' => (string) $items[0]->code,
            'item_name' => (string) $items[0]->name,
            'system_quantity' => 5,
            'ending_system_quantity' => 2,
            'cost_price' => 10,
        ]);

        $ownedItem = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $ownedItemId,
            'item_code' => 'OWNED-LEDGER',
            'item_name' => '自社セット受払対象外',
            'system_quantity' => 5,
            'ending_system_quantity' => 2,
            'cost_price' => 10,
        ]);

        $result = (new InventoryCountService)->refreshEndingSystemQuantitiesFromLedger(
            $inventoryCount,
            InventoryCountLedgerBalanceService::OPENING_DATE,
        );

        $visibleItem->refresh();
        $ownedItem->refresh();

        $this->assertSame(1, $result['updated_items']);
        $this->assertSame(0, $result['inserted_items']);
        $this->assertSame(1, $result['skipped_items']);
        $this->assertSame(17, $visibleItem->ending_system_quantity);
        $this->assertSame(2, $ownedItem->ending_system_quantity);
    }

    public function test_refresh_second_round_confirmed_differences_uses_current_ending_theory_with_backup(): void
    {
        foreach ([
            ['wms_inventory_counts', 'ending_stock_taken_at'],
            ['wms_inventory_count_items', 'ending_system_quantity'],
            ['wms_inventory_count_items', 'second_count_confirmed_system_quantity'],
            ['wms_inventory_count_items', 'second_count_confirmed_difference_quantity'],
            ['wms_inventory_count_items', 'second_count_confirmed_difference_amount'],
        ] as [$table, $column]) {
            if (! Schema::connection('sakemaru')->hasColumn($table, $column)) {
                $this->markTestSkipped("{$table}.{$column} is not available.");
            }
        }

        foreach ([
            'wms_inventory_count_theory_update_runs',
            'wms_inventory_count_theory_update_rows',
        ] as $table) {
            if (! Schema::connection('sakemaru')->hasTable($table)) {
                $this->markTestSkipped("{$table} is not available.");
            }
        }

        if (! Schema::connection('sakemaru')->hasTable('item_sets')
            || ! Schema::connection('sakemaru')->hasColumn('items', 'item_set_id')
        ) {
            $this->markTestSkipped('item set tables are not available.');
        }

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 990130,
            'warehouse_code' => '990130',
            'warehouse_name' => '2回目差異再計算テスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'current_count_round' => 3,
            'ending_stock_taken_at' => now(),
            'first_count_confirmed_at' => now()->subHour(),
            'second_count_confirmed_at' => now(),
        ]);

        $fallbackFromFirst = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999701,
            'item_code' => 'ROUND201',
            'item_name' => '2回目未入力1回目採用',
            'system_quantity' => 10,
            'ending_system_quantity' => 3,
            'first_count_quantity' => 5,
            'final_count_quantity' => 9,
            'cost_price' => 10,
            'first_count_confirmed_difference_quantity' => 99,
            'second_count_confirmed_system_quantity' => 8,
            'second_count_confirmed_difference_quantity' => -3,
            'second_count_confirmed_difference_amount' => -30,
        ]);

        $uncounted = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999702,
            'item_code' => 'ROUND202',
            'item_name' => '未入力クリア対象',
            'system_quantity' => 10,
            'ending_system_quantity' => 4,
            'cost_price' => 10,
            'second_count_confirmed_system_quantity' => 8,
            'second_count_confirmed_difference_quantity' => -8,
            'second_count_confirmed_difference_amount' => -80,
        ]);

        $matched = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999703,
            'item_code' => 'ROUND203',
            'item_name' => '2回目一致',
            'system_quantity' => 10,
            'ending_system_quantity' => 7,
            'first_count_quantity' => 6,
            'second_count_quantity' => 7,
            'cost_price' => 20,
            'second_count_confirmed_system_quantity' => 8,
            'second_count_confirmed_difference_quantity' => -1,
            'second_count_confirmed_difference_amount' => -20,
        ]);

        $owned = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $this->createOwnedSetItem(1),
            'item_code' => 'OWNED-ROUND2',
            'item_name' => '自社セット対象外',
            'system_quantity' => 10,
            'ending_system_quantity' => 1,
            'first_count_quantity' => 1,
            'second_count_quantity' => 1,
            'cost_price' => 10,
            'second_count_confirmed_system_quantity' => 10,
            'second_count_confirmed_difference_quantity' => -9,
            'second_count_confirmed_difference_amount' => -90,
        ]);

        $result = (new InventoryCountService)->refreshSecondRoundConfirmedDifferences($inventoryCount);

        $fallbackFromFirst->refresh();
        $uncounted->refresh();
        $matched->refresh();
        $owned->refresh();

        $this->assertSame(3, $result['target_items']);
        $this->assertSame(2, $result['counted_items']);
        $this->assertSame(1, $result['uncounted_items']);
        $this->assertSame(1, $result['difference_items']);
        $this->assertSame(3, $result['updated_items']);
        $this->assertSame(3, $result['backed_up_rows']);
        $this->assertSame(3, $fallbackFromFirst->second_count_confirmed_system_quantity);
        $this->assertSame(2, $fallbackFromFirst->second_count_confirmed_difference_quantity);
        $this->assertSame('20.00', $fallbackFromFirst->second_count_confirmed_difference_amount);
        $this->assertNull($fallbackFromFirst->second_count_quantity);
        $this->assertSame(9, $fallbackFromFirst->final_count_quantity);
        $this->assertSame(99, $fallbackFromFirst->first_count_confirmed_difference_quantity);
        $this->assertNull($uncounted->second_count_confirmed_system_quantity);
        $this->assertNull($uncounted->second_count_confirmed_difference_quantity);
        $this->assertNull($uncounted->second_count_confirmed_difference_amount);
        $this->assertSame(7, $matched->second_count_confirmed_system_quantity);
        $this->assertSame(0, $matched->second_count_confirmed_difference_quantity);
        $this->assertSame('0.00', $matched->second_count_confirmed_difference_amount);
        $this->assertSame(10, $owned->second_count_confirmed_system_quantity);
        $this->assertSame(-9, $owned->second_count_confirmed_difference_quantity);

        $run = DB::connection('sakemaru')
            ->table('wms_inventory_count_theory_update_runs')
            ->where('id', $result['backup_run_id'])
            ->first();

        $this->assertNotNull($run);
        $this->assertSame('round2_diff_refresh', $run->update_type);
        $this->assertSame('finished', $run->status);
        $this->assertSame(3, (int) $run->calculated_item_count);
        $this->assertSame(3, (int) $run->updated_items);

        $backup = DB::connection('sakemaru')
            ->table('wms_inventory_count_theory_update_rows')
            ->where('run_id', $result['backup_run_id'])
            ->where('inventory_count_item_id', $fallbackFromFirst->id)
            ->first();

        $this->assertNotNull($backup);
        $oldValues = json_decode($backup->old_values, true);
        $this->assertEquals(-3, (float) $oldValues['second_count_confirmed_difference_quantity']);
    }

    public function test_refresh_second_round_confirmed_differences_rejects_final_confirmed_inventory_count(): void
    {
        foreach ([
            ['wms_inventory_counts', 'ending_stock_taken_at'],
            ['wms_inventory_count_items', 'ending_system_quantity'],
            ['wms_inventory_count_items', 'second_count_confirmed_system_quantity'],
            ['wms_inventory_count_items', 'second_count_confirmed_difference_quantity'],
            ['wms_inventory_count_items', 'second_count_confirmed_difference_amount'],
        ] as [$table, $column]) {
            if (! Schema::connection('sakemaru')->hasColumn($table, $column)) {
                $this->markTestSkipped("{$table}.{$column} is not available.");
            }
        }

        foreach ([
            'wms_inventory_count_theory_update_runs',
            'wms_inventory_count_theory_update_rows',
        ] as $table) {
            if (! Schema::connection('sakemaru')->hasTable($table)) {
                $this->markTestSkipped("{$table} is not available.");
            }
        }

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 990131,
            'warehouse_code' => '990131',
            'warehouse_name' => '2回目差異再計算不可テスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_CHECKED,
            'current_count_round' => 3,
            'second_count_confirmed_at' => now()->subHour(),
            'final_count_confirmed_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('3回目確定後は2回目確定差異を再計算できません。');

        (new InventoryCountService)->refreshSecondRoundConfirmedDifferences($inventoryCount);
    }

    public function test_post_count_movement_calculation_only_updates_counted_rows(): void
    {
        foreach ([
            'wms_inventory_counts' => 'stock_movement_from_at',
            'wms_inventory_count_items' => 'post_count_movement_quantity',
        ] as $table => $column) {
            if (! Schema::connection('sakemaru')->hasColumn($table, $column)) {
                $this->markTestSkipped("{$table}.{$column} is not available.");
            }
        }

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => '小浜店',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
        ]);

        $countedItem = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999005,
            'item_code' => '999005',
            'item_name' => '入力あり商品',
            'system_quantity' => 10,
            'final_count_quantity' => 10,
            'post_count_movement_quantity' => 99,
            'cost_price' => 1,
        ]);

        $uncountedItem = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999006,
            'item_code' => '999006',
            'item_name' => '未入力商品',
            'system_quantity' => 10,
            'post_count_movement_quantity' => 99,
            'cost_price' => 1,
        ]);

        $result = (new InventoryCountService)->calculatePostCountMovements($inventoryCount, now()->subDay()->format('Y-m-d H:i:s'));

        $countedItem->refresh();
        $uncountedItem->refresh();
        $inventoryCount->refresh();

        $this->assertSame(1, $result['counted_item_count']);
        $this->assertSame(0, $countedItem->post_count_movement_quantity);
        $this->assertNull($uncountedItem->post_count_movement_quantity);
        $this->assertNotNull($inventoryCount->stock_movement_from_at);
        $this->assertNotNull($inventoryCount->stock_movement_calculated_at);
    }

    public function test_confirm_is_currently_disabled(): void
    {
        $clientId = (int) DB::connection('sakemaru')->table('clients')->value('id');
        if ($clientId <= 0) {
            $this->markTestSkipped('clients table does not have testable rows.');
        }

        $realStockId = $this->createRealStock(999004, 99, $clientId);

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => $clientId,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => '小浜店',
            'count_date' => '2026-06-19',
            'stock_movement_from_at' => '2026-06-17 14:30:00',
            'status' => WmsInventoryCount::STATUS_CHECKED,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => $realStockId,
            'item_id' => 999004,
            'item_code' => '1999004',
            'item_name' => 'テスト商品4',
            'system_quantity' => 10,
            'post_count_movement_quantity' => -4,
            'final_count_quantity' => 12,
            'difference_quantity' => 2,
            'cost_price' => 5,
            'difference_amount' => 10,
        ]);

        try {
            (new InventoryCountService)->confirm($inventoryCount, 1);
            $this->fail('棚卸し確定は現在利用不可である必要があります。');
        } catch (\RuntimeException $e) {
            $this->assertSame(InventoryCountService::CONFIRM_DISABLED_MESSAGE, $e->getMessage());
        }

        $inventoryCount->refresh();

        $this->assertSame(WmsInventoryCount::STATUS_CHECKED, $inventoryCount->status);
        $this->assertNull($inventoryCount->confirmed_at);

        if (Schema::connection('sakemaru')->hasTable('inventory_adjustment_queue')) {
            $this->assertFalse(DB::connection('sakemaru')
                ->table('inventory_adjustment_queue')
                ->where('source_type', 'WMS_INVENTORY_COUNT')
                ->where('source_id', $inventoryCount->id)
                ->exists());
        }
    }

    public function test_calculate_differences_excludes_owned_set_items(): void
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
            'warehouse_name' => '自社セット差異除外テスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
        ]);

        $visibleItem = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999810,
            'item_code' => 'VISIBLE-DIFF',
            'item_name' => '通常差異対象',
            'system_quantity' => 5,
            'final_count_quantity' => 7,
            'difference_quantity' => null,
            'cost_price' => 10,
        ]);

        $ownedItem = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $this->createOwnedSetItem(1),
            'item_code' => 'OWNED-DIFF',
            'item_name' => '自社セット差異対象外',
            'system_quantity' => 5,
            'final_count_quantity' => 7,
            'difference_quantity' => 99,
            'difference_amount' => 990,
            'cost_price' => 10,
        ]);

        (new InventoryCountService)->calculateDifferences($inventoryCount);

        $this->assertSame(2, $visibleItem->refresh()->difference_quantity);
        $this->assertSame('20.00', $visibleItem->difference_amount);
        $this->assertSame(99, $ownedItem->refresh()->difference_quantity);
        $this->assertSame('990.00', $ownedItem->difference_amount);
    }

    public function test_inventory_adjustment_excluded_summary_excludes_owned_set_items(): void
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
            'warehouse_name' => '自社セット実棚除外テスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_CHECKED,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999811,
            'item_code' => '400001',
            'item_name' => '通常実棚除外対象',
            'system_quantity' => 5,
            'final_count_quantity' => 3,
            'difference_quantity' => -2,
            'difference_amount' => -20,
            'cost_price' => 10,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $this->createOwnedSetItem(1),
            'item_code' => '400002',
            'item_name' => '自社セット実棚対象外',
            'system_quantity' => 5,
            'final_count_quantity' => 10,
            'difference_quantity' => 5,
            'difference_amount' => 50,
            'cost_price' => 10,
        ]);

        $summary = (new InventoryCountService)->inventoryAdjustmentExcludedSummary($inventoryCount);

        $this->assertSame(1, $summary['detail_count']);
        $this->assertSame(1, $summary['item_count']);
        $this->assertSame(['400001'], collect($summary['items'])->pluck('item_code')->all());
    }

    private function createRealStock(int $itemId, int $currentQuantity, int $clientId = 1, int $warehouseId = 22, int $stockAllocationId = 0): int
    {
        return (int) DB::connection('sakemaru')
            ->table('real_stocks')
            ->insertGetId([
                'client_id' => $clientId,
                'warehouse_id' => $warehouseId,
                'stock_allocation_id' => $stockAllocationId,
                'item_id' => $itemId,
                'current_quantity' => $currentQuantity,
                'order_rank' => '',
                'reserved_quantity' => 0,
                'picking_quantity' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function createStockTransferMovement(
        int $clientId,
        object $item,
        int $fromWarehouseId,
        int $toWarehouseId,
        int $quantity,
        string $processDate,
        bool $isDelivered,
        ?string $deliveredDate,
        ?string $pickingDate = null,
    ): void {
        $tradeId = DB::connection('sakemaru')->table('trades')->insertGetId([
            'client_id' => $clientId,
            'creator_id' => 1,
            'last_updater_id' => 1,
            'trade_category' => 'STOCK_TRANSFER',
            'uuid' => (string) Str::uuid(),
            'serial_id' => random_int(900000000, 999999999),
            'entry_lot_number' => 0,
            'subtotal' => 0,
            'total' => 0,
            'process_date' => $processDate,
            'is_active' => true,
            'is_latest' => true,
            'trade_item_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('sakemaru')->table('stock_transfers')->insert([
            'trade_id' => $tradeId,
            'client_id' => $clientId,
            'from_warehouse_id' => $fromWarehouseId,
            'to_warehouse_id' => $toWarehouseId,
            'is_delivered' => $isDelivered,
            'delivered_date' => $deliveredDate ?? '2023-01-01',
            'picking_date' => $pickingDate,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('sakemaru')->table('trade_items')->insert([
            'client_id' => $clientId,
            'trade_id' => $tradeId,
            'item_id' => $item->id,
            'item_name' => (string) $item->name,
            'stock_allocation_id' => 0,
            'order_quantity_type' => 'PIECE',
            'quantity' => $quantity,
            'quantity_type' => 'PIECE',
            'capacity_case' => 1,
            'capacity_carton' => 1,
            'price_category' => 'OTHER',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createRealStockLot(int $realStockId, int $quantity): int
    {
        return (int) DB::connection('sakemaru')
            ->table('real_stock_lots')
            ->insertGetId([
                'real_stock_id' => $realStockId,
                'initial_quantity' => $quantity,
                'current_quantity' => $quantity,
                'reserved_quantity' => 0,
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function createOwnedSetEarningAllocation(
        int $clientId,
        int $warehouseId,
        int $setParentId,
        int $realStockLotId,
        int $quantity,
        string $deliveredDate,
    ): void {
        $setParent = DB::connection('sakemaru')->table('items')->where('id', $setParentId)->first();
        $tradeId = DB::connection('sakemaru')->table('trades')->insertGetId([
            'client_id' => $clientId,
            'partner_id' => 1,
            'creator_id' => 1,
            'last_updater_id' => 1,
            'trade_category' => 'EARNING',
            'trade_direction' => 'NORMAL',
            'is_returned' => false,
            'uuid' => (string) Str::uuid(),
            'serial_id' => random_int(900000000, 999999999),
            'entry_lot_number' => 0,
            'subtotal' => 0,
            'total' => 0,
            'process_date' => $deliveredDate,
            'is_active' => true,
            'is_latest' => true,
            'trade_item_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $earningId = DB::connection('sakemaru')->table('earnings')->insertGetId([
            'trade_id' => $tradeId,
            'client_id' => $clientId,
            'buyer_id' => 1,
            'warehouse_id' => $warehouseId,
            'delivered_date' => $deliveredDate,
            'account_date' => $deliveredDate,
            'is_delivered' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tradeItemId = DB::connection('sakemaru')->table('trade_items')->insertGetId([
            'client_id' => $clientId,
            'trade_id' => $tradeId,
            'item_id' => $setParentId,
            'item_name' => (string) ($setParent->name ?? $setParent->name_main ?? 'OWNEDセット親'),
            'stock_allocation_id' => 0,
            'order_quantity_type' => 'PIECE',
            'quantity' => 1,
            'quantity_type' => 'PIECE',
            'capacity_case' => 1,
            'capacity_carton' => 1,
            'price_category' => 'OTHER',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('sakemaru')->table('real_stock_lot_earnings')->insert([
            'real_stock_lot_id' => $realStockLotId,
            'earning_id' => $earningId,
            'trade_item_id' => $tradeItemId,
            'quantity' => $quantity,
            'status' => 'DELIVERED',
            'reserved_at' => now(),
            'delivered_at' => $deliveredDate.' 00:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createOpeningBalance(int $clientId, int $warehouseId, object $item, int $quantity): void
    {
        DB::connection('sakemaru')->table('stats_item_stock_opening_balances')->insert([
            'client_id' => $clientId,
            'opening_date' => InventoryCountLedgerBalanceService::OPENING_DATE,
            'source_database' => 'phpunit',
            'warehouse_id' => $warehouseId,
            'warehouse_code' => (string) $warehouseId,
            'warehouse_name' => '棚卸受払テスト倉庫',
            'item_id' => $item->id,
            'item_code' => (string) $item->code,
            'item_name' => (string) $item->name,
            'stock_allocation_id' => 0,
            'opening_quantity' => $quantity,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createOwnedSetItem(int $clientId): int
    {
        $itemSetId = DB::connection('sakemaru')->table('item_sets')->insertGetId([
            'description' => '棚卸対象外自社セット',
            'set_type' => 'OWNED',
            'is_active' => true,
            'client_id' => $clientId,
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
            'client_id' => $clientId,
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

    private function createUnmanagedStockItem(int $clientId): int
    {
        return (int) DB::connection('sakemaru')->table('items')->insertGetId([
            'name_main' => '在庫管理対象外'.Str::upper(Str::random(8)),
            'code' => random_int(800000000, 899999999),
            'type' => 'NOT_ALCOHOL',
            'manufacturer_id' => 0,
            'volume' => 1,
            'capacity_case' => 1,
            'creator_id' => 1,
            'packaging' => '1',
            'nickname' => '在庫管理対象外',
            'client_id' => $clientId,
            'item_set_id' => 0,
            'item_category1_id' => 0,
            'item_category2_id' => 0,
            'container_type_id' => 0,
            'manufacture_type_id' => 0,
            'storage_type_id' => 0,
            'measurement_unit_weight' => 0,
            'measurement_case_weight' => 0,
            'order_rank' => 'ORDER_MANUAL',
            'is_managed_stock' => false,
            'last_updater_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ledgerTestItems()
    {
        $query = DB::connection('sakemaru')
            ->table('items as i')
            ->whereNotNull('i.client_id');

        $itemColumns = Schema::connection('sakemaru')->getColumnListing('items');

        if (in_array('is_active', $itemColumns, true)) {
            $query->where('i.is_active', true);
        }

        if (in_array('is_managed_stock', $itemColumns, true)) {
            $query->where('i.is_managed_stock', true);
        }

        if (in_array('type', $itemColumns, true)) {
            $query->whereRaw("COALESCE(i.type, '') <> 'CONTAINER'");
        }

        if (Schema::connection('sakemaru')->hasTable('item_sets')) {
            $query
                ->leftJoin('item_sets as item_set', function ($join) {
                    $join->on('item_set.id', '=', 'i.item_set_id')
                        ->where('item_set.is_active', true);
                })
                ->where(function ($query) {
                    $query
                        ->whereNull('item_set.id')
                        ->orWhere('item_set.set_type', '!=', 'OWNED');
                });
        }

        $clientId = (clone $query)
            ->orderBy('i.id')
            ->value('i.client_id');

        if ($clientId !== null) {
            $query->where('i.client_id', $clientId);
        }

        return $query
            ->select(['i.id', 'i.code', 'i.name', 'i.client_id'])
            ->orderBy('i.id')
            ->limit(2)
            ->get();
    }
}
