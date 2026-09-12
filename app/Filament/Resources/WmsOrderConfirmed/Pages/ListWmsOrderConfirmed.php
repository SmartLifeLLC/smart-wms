<?php

namespace App\Filament\Resources\WmsOrderConfirmed\Pages;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\OrderChannel;
use App\Enums\AutoOrder\OrderDataFileChannel;
use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsOrderConfirmationWaiting\Tables\WmsOrderConfirmationWaitingTable;
use App\Filament\Resources\WmsOrderConfirmed\WmsOrderConfirmedResource;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsOrderCandidate;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ListWmsOrderConfirmed extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsOrderConfirmedResource::class;

    /**
     * プリセットビュー用倉庫データのキャッシュ
     */
    protected ?Collection $cachedWarehouses = null;

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with([
                    'warehouse',
                    'item',
                    'contractor',
                    'jxDocument',
                ])
                ->addSelect([
                    'order_data_file_generated' => DB::connection('sakemaru')
                        ->table('wms_order_data_files')
                        ->selectRaw('1')
                        ->whereColumn('wms_order_data_files.batch_code', (new WmsOrderCandidate)->getTable().'.batch_code')
                        ->whereColumn('wms_order_data_files.warehouse_id', (new WmsOrderCandidate)->getTable().'.warehouse_id')
                        ->whereColumn('wms_order_data_files.contractor_id', (new WmsOrderCandidate)->getTable().'.contractor_id')
                        ->whereColumn('wms_order_data_files.expected_arrival_date', (new WmsOrderCandidate)->getTable().'.expected_arrival_date')
                        ->where(function ($query) {
                            $candidateTable = (new WmsOrderCandidate)->getTable();

                            $query
                                ->whereNull("{$candidateTable}.order_channel")
                                ->orWhere("{$candidateTable}.order_channel", OrderChannel::FAX->value);
                        })
                        ->where(function ($query): void {
                            $query
                                ->whereNull('wms_order_data_files.order_channel')
                                ->orWhere('wms_order_data_files.order_channel', OrderDataFileChannel::FAX->value);
                        })
                        ->where(function ($query) {
                            $candidateTable = (new WmsOrderCandidate)->getTable();

                            $query
                                ->whereRaw("JSON_CONTAINS(wms_order_data_files.candidate_ids, JSON_ARRAY({$candidateTable}.id))")
                                ->orWhereNull('wms_order_data_files.candidate_ids');
                        })
                        ->limit(1),
                ])
            );
    }

    protected function paginateTableQuery(Builder $query): Paginator
    {
        $paginator = parent::paginateTableQuery($query);
        $items = $paginator->getCollection();

        if ($items->isNotEmpty()) {
            WmsOrderConfirmationWaitingTable::preloadItemContractorOrderSettings($items);
        }

        return $paginator;
    }

    public function getPresetViews(): array
    {
        $selectedWarehouseId = auth()->user()?->getSelectedWarehouseId();
        $warehouseData = $this->getWarehouseDataForPresetViews();
        $warehouseIds = $warehouseData['ids'];
        $warehouses = $warehouseData['warehouses'];

        $hasSelectedWarehouse = $selectedWarehouseId && in_array($selectedWarehouseId, $warehouseIds);
        $selectedWarehouse = $hasSelectedWarehouse ? $warehouses->firstWhere('id', $selectedWarehouseId) : null;

        if ($selectedWarehouse) {
            $views = [
                'default' => PresetView::make()
                    ->modifyQueryUsing(fn (Builder $query) => $query->where((new WmsOrderCandidate)->getTable().'.warehouse_id', $selectedWarehouseId))
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

        foreach ($warehouses as $warehouse) {
            if ($hasSelectedWarehouse && $warehouse->id === $selectedWarehouseId) {
                continue;
            }
            $views["default_{$warehouse->id}"] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where((new WmsOrderCandidate)->getTable().'.warehouse_id', $warehouse->id))
                ->favorite()
                ->label($warehouse->name);
        }

        return $views;
    }

    /**
     * @return array{ids: array<int>, warehouses: Collection<int, Warehouse>}
     */
    protected function getWarehouseDataForPresetViews(): array
    {
        if ($this->cachedWarehouses !== null) {
            return [
                'ids' => $this->cachedWarehouses->pluck('id')->toArray(),
                'warehouses' => $this->cachedWarehouses,
            ];
        }

        $warehouseIds = WmsOrderCandidate::whereIn('status', [CandidateStatus::CONFIRMED, CandidateStatus::EXECUTED])
            ->distinct()
            ->pluck('warehouse_id')
            ->toArray();

        $this->cachedWarehouses = Warehouse::whereIn('id', $warehouseIds)
            ->orderBy('code')
            ->get(['id', 'name']);

        return [
            'ids' => $warehouseIds,
            'warehouses' => $this->cachedWarehouses,
        ];
    }
}
