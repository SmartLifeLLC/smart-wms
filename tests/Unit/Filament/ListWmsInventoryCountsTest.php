<?php

namespace Tests\Unit\Filament;

use App\Filament\Resources\WmsInventoryCount\Pages\ListWmsInventoryCounts;
use App\Filament\Resources\WmsInventoryCount\Tables\WmsInventoryCountTable;
use App\Models\WmsInventoryCount;
use ReflectionMethod;
use Tests\TestCase;

class ListWmsInventoryCountsTest extends TestCase
{
    public function test_inventory_count_create_action_is_available_as_header_action(): void
    {
        $page = new ListWmsInventoryCounts;
        $method = new ReflectionMethod($page, 'getHeaderActions');
        $method->setAccessible(true);

        $actions = $method->invoke($page);

        $this->assertNotEmpty($actions);
        $this->assertSame('createInventoryCount', $actions[0]->getName());
        $this->assertSame('棚卸し作成', $actions[0]->getLabel());
        $this->assertSame('downloadAllStoreDifferenceWorkbook', $actions[1]->getName());
        $this->assertSame('全店差異表', $actions[1]->getLabel());
    }

    public function test_inventory_count_default_order_is_latest_first(): void
    {
        $query = WmsInventoryCountTable::applyDefaultOrder(WmsInventoryCount::query());

        $this->assertStringContainsString(
            'order by `count_date` desc, `id` desc',
            strtolower($query->toSql())
        );
    }

    public function test_inventory_count_progress_excludes_owned_set_items(): void
    {
        $table = file_get_contents(app_path('Filament/Resources/WmsInventoryCount/Tables/WmsInventoryCountTable.php'));

        $this->assertStringContainsString('$record->items()->withoutOwnedSetItems()->count()', $table);
        $this->assertStringContainsString('$record->items()->withoutOwnedSetItems()->whereNotNull(\'first_count_quantity\')->count()', $table);
    }

    public function test_inventory_count_list_has_all_store_difference_target_toggle_and_download_action(): void
    {
        $table = file_get_contents(app_path('Filament/Resources/WmsInventoryCount/Tables/WmsInventoryCountTable.php'));

        $this->assertStringContainsString("ToggleColumn::make('is_all_store_difference_target')", $table);
        $this->assertStringContainsString("Action::make('downloadAllStoreDifferenceWorkbook')", $table);
        $this->assertStringContainsString("Select::make('target_round')", $table);
        $this->assertStringContainsString('AllStoreInventoryDifferenceWorkbookService)->generate($targetRecords, $targetRound)', $table);
        $this->assertStringContainsString('AllStoreInventoryDifferenceWorkbookService', $table);
    }
}
