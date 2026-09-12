<?php

namespace Tests\Unit\Services;

use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use App\Services\InventoryCount\InventoryInstructionPdfService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

class InventoryInstructionPdfServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['sakemaru'];

    public function test_jan_book_can_exclude_zero_theory_items(): void
    {
        if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_count_items', 'ending_system_quantity')) {
            $this->markTestSkipped('wms_inventory_count_items.ending_system_quantity is not available.');
        }

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => 'JANブックテスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'current_count_round' => 1,
        ]);

        $this->createCountItem($inventoryCount, 'JAN-NONZERO', 0, 2, 998000001, '001');
        $this->createCountItem($inventoryCount, 'JAN-ZERO', 5, 0, 998000002, '002');
        $this->createCountItem($inventoryCount, 'JAN-START-ZERO', 0, null, 998000003, '003');

        $service = new InventoryInstructionPdfService;

        $allItemCodes = $this->queryItems($service, $inventoryCount, false)
            ->pluck('item_code')
            ->all();
        $filteredItemCodes = $this->queryItems($service, $inventoryCount, true)
            ->pluck('item_code')
            ->all();

        $this->assertEqualsCanonicalizing(['JAN-NONZERO', 'JAN-ZERO', 'JAN-START-ZERO'], $allItemCodes);
        $this->assertSame(['JAN-NONZERO'], $filteredItemCodes);
    }

    private function createCountItem(
        WmsInventoryCount $inventoryCount,
        string $itemCode,
        int $systemQuantity,
        ?int $endingSystemQuantity,
        int $itemId,
        string $locationCode3,
    ): WmsInventoryCountItem {
        return WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => random_int(900000000, 999999999),
            'item_id' => $itemId,
            'item_code' => $itemCode,
            'item_name' => 'JANブック対象 '.$itemCode,
            'location_id' => random_int(100000, 999999),
            'location_code1' => 'A',
            'location_code2' => '01',
            'location_code3' => $locationCode3,
            'location_no' => 'A0-'.$itemCode,
            'system_quantity' => $systemQuantity,
            'ending_system_quantity' => $endingSystemQuantity,
            'cost_price' => 10,
            'input_count' => 0,
        ]);
    }

    private function queryItems(
        InventoryInstructionPdfService $service,
        WmsInventoryCount $inventoryCount,
        bool $excludeZeroTheory,
    ): EloquentCollection {
        $method = new ReflectionMethod(InventoryInstructionPdfService::class, 'queryItems');
        $method->setAccessible(true);

        return $method->invoke($service, $inventoryCount, $excludeZeroTheory);
    }
}
