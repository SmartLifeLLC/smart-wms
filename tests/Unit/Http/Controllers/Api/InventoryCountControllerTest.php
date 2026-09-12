<?php

namespace Tests\Unit\Http\Controllers\Api;

use App\Http\Controllers\Api\InventoryCountController;
use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use App\Services\InventoryCount\InventoryCountService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

class InventoryCountControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['sakemaru'];

    public function test_piece_jan_package_quantity_uses_item_capacity_case(): void
    {
        $this->assertSame(12, $this->packageQuantity((object) [
            'quantity_type' => 'PIECE',
            'package_quantity' => 1,
            'item_capacity_case' => 12,
        ]));
    }

    public function test_case_jan_package_quantity_uses_jan_quantity(): void
    {
        $this->assertSame(6, $this->packageQuantity((object) [
            'quantity_type' => 'CASE',
            'package_quantity' => 6,
            'item_capacity_case' => 12,
        ]));
    }

    public function test_piece_jan_package_quantity_falls_back_to_one(): void
    {
        $this->assertSame(1, $this->packageQuantity((object) [
            'quantity_type' => 'PIECE',
            'package_quantity' => 1,
            'item_capacity_case' => null,
        ]));
    }

    public function test_item_payload_adds_ending_system_quantity_without_changing_existing_system_quantity(): void
    {
        $item = new WmsInventoryCountItem([
            'id' => 123,
            'item_id' => 0,
            'item_code' => 'TEST001',
            'item_name' => 'API payload test item',
            'system_quantity' => 5,
            'ending_system_quantity' => 8,
        ]);

        $controller = new InventoryCountController(new InventoryCountService);
        $method = new ReflectionMethod($controller, 'itemPayload');
        $method->setAccessible(true);

        $payload = $method->invoke($controller, $item, false);

        $this->assertSame(5, $payload['system_quantity']);
        $this->assertSame(5, $payload['system_quantity_start']);
        $this->assertSame(8, $payload['ending_system_quantity']);
        $this->assertSame(8, $payload['system_quantity_end']);
    }

    public function test_show_counts_exclude_owned_set_items(): void
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
            'warehouse_name' => 'API自社セット除外テスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999801,
            'item_code' => 'API-VISIBLE',
            'item_name' => 'API通常棚卸対象',
            'system_quantity' => 5,
            'ending_system_quantity' => 5,
            'first_count_quantity' => 5,
            'cost_price' => 10,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $this->createOwnedSetItem(),
            'item_code' => 'API-OWNED',
            'item_name' => 'API自社セット対象外',
            'system_quantity' => 5,
            'ending_system_quantity' => 5,
            'cost_price' => 10,
        ]);

        $response = (new InventoryCountController(new InventoryCountService))
            ->show(Request::create('/api/wms/inventory-counts/'.$inventoryCount->id, 'GET'), (int) $inventoryCount->id);

        $payload = $response->getData(true);

        $this->assertSame(1, $payload['result']['data']['inventory_count']['total_items']);
        $this->assertSame(1, $payload['result']['data']['inventory_count']['counted_items']);
        $this->assertSame(0, $payload['result']['data']['inventory_count']['uncounted_items']);
    }

    public function test_bulk_count_accepts_negative_quantities_from_handy(): void
    {
        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => 'API負数棚卸テスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'handy_reception' => true,
        ]);

        $countItem = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999802,
            'item_code' => 'API-NEGATIVE',
            'item_name' => 'API負数棚卸対象',
            'system_quantity' => 10,
            'ending_system_quantity' => 10,
            'cost_price' => 2,
        ]);

        $request = Request::create('/api/wms/inventory-counts/'.$inventoryCount->id.'/counts/bulk', 'POST', [
            'count_round' => 1,
            'device_id' => 'DENSO',
            'items' => [[
                'item_id' => $countItem->id,
                'case_quantity' => -1,
                'piece_quantity' => -2,
                'quantity' => -8,
                'request_uuid' => (string) Str::uuid(),
            ]],
        ]);

        $response = (new InventoryCountController(new InventoryCountService))
            ->bulkCount($request, (int) $inventoryCount->id);

        $payload = $response->getData(true);

        $this->assertTrue($payload['is_success']);
        $this->assertSame(1, $payload['result']['data']['updated_count']);
        $this->assertSame(-8, (int) $countItem->refresh()->first_count_quantity);
        $this->assertSame(-18, (int) $countItem->difference_quantity);
    }

    private function packageQuantity(object $row): int
    {
        $controller = new InventoryCountController(new InventoryCountService);
        $method = new ReflectionMethod($controller, 'packageQuantity');
        $method->setAccessible(true);

        return $method->invoke($controller, $row);
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
            'name_main' => 'API自社セット対象外'.Str::upper(Str::random(8)),
            'code' => random_int(800000000, 899999999),
            'type' => 'NOT_ALCOHOL',
            'manufacturer_id' => 0,
            'volume' => 1,
            'capacity_case' => 1,
            'creator_id' => 1,
            'packaging' => '1',
            'nickname' => 'API自社セット対象外',
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
