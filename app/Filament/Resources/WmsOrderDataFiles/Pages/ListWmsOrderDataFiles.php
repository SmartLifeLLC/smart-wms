<?php

namespace App\Filament\Resources\WmsOrderDataFiles\Pages;

use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsOrderDataFiles\WmsOrderDataFileResource;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsOrderDataFile;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ListWmsOrderDataFiles extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsOrderDataFileResource::class;

    /**
     * プリセットビュー用倉庫データのキャッシュ
     * getPresetViews()が複数回呼び出されるため、リクエスト内でキャッシュする
     */
    protected ?Collection $cachedWarehouses = null;

    public function getView(): string
    {
        return 'filament.resources.wms-order-data-files.pages.list-wms-order-data-files';
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['warehouse', 'contractor', 'csvDownloadedByUser'])
                ->where('is_test', false)
            );
    }

    public function getPresetViews(): array
    {
        // ユーザーがヘッダーで選択中の倉庫を取得（未選択時はデフォルト倉庫）
        $selectedWarehouseId = auth()->user()?->getSelectedWarehouseId();

        // 倉庫データをキャッシュから取得（リクエスト内で複数回呼び出されるため）
        $warehouses = $this->getWarehousesForPresetViews();
        $warehouseIds = $warehouses->pluck('id')->toArray();

        // 選択中の倉庫が発注データファイルに存在するかチェック
        $hasSelectedWarehouse = $selectedWarehouseId && in_array($selectedWarehouseId, $warehouseIds);
        $selectedWarehouse = $hasSelectedWarehouse ? $warehouses->firstWhere('id', $selectedWarehouseId) : null;

        // プリセットビュー構築（データがなくても「全て」タブは常に表示）
        if ($selectedWarehouse) {
            $views = [
                'default' => PresetView::make()
                    ->modifyQueryUsing(fn (Builder $query) => $query->where('warehouse_id', $selectedWarehouseId))
                    ->favorite()
                    ->label($selectedWarehouse->name)
                    ->default(),
            ];
        } else {
            $views = [
                'default' => PresetView::make()
                    ->favorite()
                    ->label('全て')
                    ->default(),
            ];
        }

        $views['all'] = PresetView::make()
            ->favorite()
            ->label('全て');

        // 倉庫タブを追加（データがある場合のみ）
        foreach ($warehouses as $warehouse) {
            if ($hasSelectedWarehouse && $warehouse->id === $selectedWarehouseId) {
                continue;
            }
            $views["default_{$warehouse->id}"] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('warehouse_id', $warehouse->id))
                ->favorite()
                ->label($warehouse->name);
        }

        return $views;
    }

    /**
     * プリセットビュー用の倉庫データを取得（キャッシュ付き）
     */
    protected function getWarehousesForPresetViews(): Collection
    {
        if ($this->cachedWarehouses !== null) {
            return $this->cachedWarehouses;
        }

        $warehouseIds = WmsOrderDataFile::where('is_test', false)
            ->distinct()
            ->pluck('warehouse_id')
            ->toArray();

        $this->cachedWarehouses = Warehouse::whereIn('id', $warehouseIds)
            ->orderBy('code')
            ->get();

        return $this->cachedWarehouses;
    }
}
