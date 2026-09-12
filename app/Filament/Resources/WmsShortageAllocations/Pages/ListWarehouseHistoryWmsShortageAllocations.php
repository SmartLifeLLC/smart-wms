<?php

namespace App\Filament\Resources\WmsShortageAllocations\Pages;

use App\Models\Sakemaru\Warehouse;
use Archilex\AdvancedTables\Components\PresetView;
use Illuminate\Database\Eloquent\Builder;

class ListWarehouseHistoryWmsShortageAllocations extends ListHistoryWmsShortageAllocations
{
    protected static ?string $title = '倉庫別横持ち出荷履歴';

    public function getPresetViews(): array
    {
        $warehouseId = auth()->user()?->getSelectedWarehouseId();
        if (! $warehouseId) {
            return [];
        }

        $warehouse = Warehouse::find($warehouseId);
        if (! $warehouse) {
            return [];
        }

        return [
            'default' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('target_warehouse_id', $warehouseId))
                ->favorite()
                ->label($warehouse->name)
                ->default(),
        ];
    }

    protected function getSyncWarehouseIds(): array
    {
        $warehouseId = auth()->user()?->getSelectedWarehouseId();

        return $warehouseId ? [(int) $warehouseId] : [];
    }
}
