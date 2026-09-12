<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\WmsInventoryCount\Pages\ListWmsInventoryCounts;
use App\Models\Sakemaru\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Sakemaru\Auth\Services\PermissionService;
use Tests\TestCase;

class WmsInventoryCountResourceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['sakemaru'];

    public function test_list_page_renders_inventory_count_create_button(): void
    {
        $this->mock(PermissionService::class, function ($mock): void {
            $mock->shouldReceive('check')->andReturnTrue();
        });

        $warehouseId = DB::connection('sakemaru')->table('warehouses')->value('id');

        if (! $warehouseId) {
            $this->markTestSkipped('No warehouse is available.');
        }

        $user = User::create([
            'code' => 9999999900 + random_int(0, 99),
            'client_id' => 1,
            'name' => 'WMS_TEST_USER_'.uniqid(),
            'email' => 'wms-inventory-count-'.uniqid().'@test.local',
            'password' => bcrypt('test-password'),
            'is_active' => true,
            'default_warehouse_id' => $warehouseId,
            'creator_id' => 1,
            'last_updater_id' => 1,
        ]);

        Livewire::actingAs($user)
            ->test(ListWmsInventoryCounts::class)
            ->assertSuccessful()
            ->assertSee('棚卸し作成');
    }
}
