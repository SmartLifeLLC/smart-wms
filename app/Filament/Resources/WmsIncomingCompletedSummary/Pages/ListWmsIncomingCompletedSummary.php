<?php

namespace App\Filament\Resources\WmsIncomingCompletedSummary\Pages;

use App\Filament\Resources\WmsIncomingCompletedSummary\WmsIncomingCompletedSummaryResource;
use App\Models\WmsOrderIncomingSchedule;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListWmsIncomingCompletedSummary extends ListRecords
{
    protected static string $resource = WmsIncomingCompletedSummaryResource::class;

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query): Builder => static::applySummaryQuery(
                static::applySelectedWarehouseQuery($query),
            ));
    }

    public static function applySummaryQuery(Builder $query): Builder
    {
        $table = (new WmsOrderIncomingSchedule)->getTable();

        return $query
            ->select([
                "{$table}.warehouse_id",
                "{$table}.contractor_id",
            ])
            ->selectRaw("MIN({$table}.id) as id")
            ->selectRaw('COUNT(*) as summary_detail_count')
            ->selectRaw("COUNT(DISTINCT {$table}.item_id) as summary_item_count")
            ->selectRaw("COUNT(DISTINCT NULLIF(TRIM({$table}.slip_number), '')) as summary_slip_count")
            ->selectRaw("MIN({$table}.expected_arrival_date) as summary_expected_from")
            ->selectRaw("MAX({$table}.expected_arrival_date) as summary_expected_until")
            ->selectRaw("MIN({$table}.actual_arrival_date) as summary_actual_from")
            ->selectRaw("MAX({$table}.actual_arrival_date) as summary_actual_until")
            ->selectRaw("MAX({$table}.confirmed_at) as summary_last_confirmed_at")
            ->selectRaw("SUM(CASE WHEN {$table}.purchase_queue_id IS NULL THEN 1 ELSE 0 END) as summary_untransmitted_count")
            ->selectRaw("SUM(CASE WHEN {$table}.purchase_queue_id IS NULL THEN 0 ELSE 1 END) as summary_transmitted_count")
            ->groupBy("{$table}.warehouse_id", "{$table}.contractor_id");
    }

    private static function applySelectedWarehouseQuery(Builder $query): Builder
    {
        $warehouseId = auth()->user()?->getSelectedWarehouseId();

        if (! $warehouseId) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('warehouse_id', (int) $warehouseId);
    }
}
