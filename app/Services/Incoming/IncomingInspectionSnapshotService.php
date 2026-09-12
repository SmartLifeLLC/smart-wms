<?php

namespace App\Services\Incoming;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\AutoOrder\OrderSource;
use App\Models\Sakemaru\Location;
use App\Models\WmsIncomingAppInspectionDetail;
use App\Models\WmsOrderIncomingSchedule;
use App\Services\WarehouseResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class IncomingInspectionSnapshotService
{
    public function build(int $warehouseId, ?string $inspectionDate = null): array
    {
        $date = CarbonImmutable::parse($inspectionDate ?: now()->toDateString());
        $warehouseIds = $this->matchingWarehouseIds($warehouseId);
        $warehouse = $this->warehouse($warehouseId);
        $schedules = $this->pendingSchedules($warehouseIds);
        $scheduleIds = $schedules->pluck('id')->all();
        $eosScheduleIds = $this->eosScheduleIds($scheduleIds);

        return [
            'version' => 'v2',
            'generated_at' => now()->toIso8601String(),
            'inspection_date' => $date->toDateString(),
            'warehouse' => $warehouse,
            'rules' => [
                'eos_inspection_policy' => 'HISTORY_ONLY',
                'eos_confirmed_index_days' => 3,
                'unplanned_order_source' => OrderSource::APP_UNPLANNED->value,
                'quantity_input' => 'CASE_AND_PIECE',
                'matching_warehouse_ids' => $warehouseIds,
                'item_master_sync' => 'DAILY_CACHE',
            ],
            'schedules' => $schedules
                ->map(fn (WmsOrderIncomingSchedule $schedule): array => $this->formatSchedule($schedule, $eosScheduleIds->contains((int) $schedule->id)))
                ->values()
                ->all(),
            'confirmed_eos_index' => $this->confirmedEosIndex($warehouseIds, $date),
            'items' => [],
            'locations' => $this->locations($warehouseId),
        ];
    }

    public function buildItemMaster(int $warehouseId): array
    {
        $warehouseIds = $this->matchingWarehouseIds($warehouseId);
        $itemContractorWarehouseId = WarehouseResolver::resolveRealWarehouseId($warehouseId);
        $handledItemIds = $this->handledItemIds($warehouseIds);

        return [
            'version' => 'v2',
            'generated_at' => now()->toIso8601String(),
            'master_date' => now()->toDateString(),
            'warehouse' => $this->warehouse($warehouseId),
            'rules' => [
                'item_master_sync' => 'DAILY_CACHE',
                'matching_warehouse_ids' => $warehouseIds,
            ],
            'items' => $this->items($warehouseId, $itemContractorWarehouseId, $handledItemIds),
        ];
    }

    private function warehouse(int $warehouseId): ?array
    {
        $warehouse = DB::connection('sakemaru')
            ->table('warehouses')
            ->where('id', $warehouseId)
            ->first(['id', 'code', 'name', 'kana_name']);

        if (! $warehouse) {
            return null;
        }

        return [
            'id' => (int) $warehouse->id,
            'code' => (string) $warehouse->code,
            'name' => (string) $warehouse->name,
            'kana_name' => $warehouse->kana_name,
        ];
    }

    /**
     * @return Collection<int, WmsOrderIncomingSchedule>
     *
     * @param  array<int>  $warehouseIds
     */
    private function pendingSchedules(array $warehouseIds): Collection
    {
        return WmsOrderIncomingSchedule::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereIn('status', [
                IncomingScheduleStatus::PENDING->value,
                IncomingScheduleStatus::PARTIAL->value,
            ])
            ->with(['warehouse', 'item.item_search_information', 'contractor', 'location', 'orderCandidate'])
            ->orderBy('expected_arrival_date')
            ->orderBy('slip_number')
            ->orderBy('id')
            ->get();
    }

    private function eosScheduleIds(array $scheduleIds): Collection
    {
        if ($scheduleIds === []) {
            return collect();
        }

        return WmsOrderIncomingSchedule::query()
            ->whereIn('id', $scheduleIds)
            ->eosSent()
            ->pluck('id')
            ->map(fn ($id) => (int) $id);
    }

    private function formatSchedule(WmsOrderIncomingSchedule $schedule, bool $isEosSent): array
    {
        $schedule->loadMissing('item');
        $expectedPieces = $schedule->expected_piece_quantity;
        $receivedPieces = $schedule->received_piece_quantity;

        return [
            'id' => (int) $schedule->id,
            'warehouse_id' => (int) $schedule->warehouse_id,
            'warehouse' => $schedule->warehouse ? [
                'id' => (int) $schedule->warehouse->id,
                'code' => $schedule->warehouse->code,
                'name' => $schedule->warehouse->name,
            ] : null,
            'slip_number' => $schedule->slip_number,
            'order_source' => $schedule->order_source?->value,
            'order_source_label' => $schedule->isUnassignedJxReceived() ? '不明' : ($schedule->order_source?->label() ?? '-'),
            'inspection_policy' => $this->policyForSchedule($schedule, $isEosSent),
            'is_eos_sent' => $isEosSent,
            'status' => $schedule->status?->value,
            'order_date' => $schedule->order_date?->toDateString(),
            'expected_arrival_date' => $schedule->expected_arrival_date?->toDateString(),
            'actual_arrival_date' => $schedule->actual_arrival_date?->toDateString(),
            'contractor' => $schedule->contractor ? [
                'id' => (int) $schedule->contractor->id,
                'code' => $schedule->contractor->code,
                'name' => $schedule->contractor->name,
            ] : null,
            'item' => $this->formatItem($schedule->item),
            'location' => $schedule->location ? $this->formatLocation($schedule->location) : null,
            'quantity' => [
                'quantity_type' => $schedule->quantity_type?->value,
                'expected_quantity' => (int) $schedule->expected_quantity,
                'received_quantity' => (int) $schedule->received_quantity,
                'remaining_quantity' => max(0, (int) $schedule->expected_quantity - (int) $schedule->received_quantity),
                'expected_piece_quantity' => $expectedPieces,
                'received_piece_quantity' => $receivedPieces,
                'remaining_piece_quantity' => max(0, $expectedPieces - $receivedPieces),
                'capacity_case' => (int) max(1, $schedule->item?->capacity_case ?? 1),
            ],
        ];
    }

    private function policyForSchedule(WmsOrderIncomingSchedule $schedule, bool $isEosSent): string
    {
        if ($schedule->purchase_queue_id !== null || $schedule->status === IncomingScheduleStatus::TRANSMITTED) {
            return WmsIncomingAppInspectionDetail::POLICY_PURCHASE_TRANSMITTED_LOCKED;
        }

        if ($schedule->order_source === OrderSource::TRANSFER
            || $schedule->transfer_candidate_id !== null
            || $schedule->source_warehouse_id !== null
            || $schedule->stock_transfer_id !== null) {
            return WmsIncomingAppInspectionDetail::POLICY_TRANSFER_WEB_ONLY;
        }

        if ($isEosSent) {
            return WmsIncomingAppInspectionDetail::POLICY_EOS_HISTORY_ONLY;
        }

        return WmsIncomingAppInspectionDetail::POLICY_APP_CONFIRM_ALLOWED;
    }

    /**
     * @param  array<int>  $warehouseIds
     */
    private function confirmedEosIndex(array $warehouseIds, CarbonImmutable $inspectionDate): array
    {
        $from = $inspectionDate->subDays(2)->toDateString();
        $to = $inspectionDate->toDateString();

        return WmsOrderIncomingSchedule::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('status', IncomingScheduleStatus::CONFIRMED->value)
            ->where(function ($query) use ($from, $to) {
                $query->whereBetween('actual_arrival_date', [$from, $to])
                    ->orWhere(function ($query) use ($from, $to) {
                        $query->whereNull('actual_arrival_date')
                            ->whereBetween('expected_arrival_date', [$from, $to]);
                    });
            })
            ->eosSent()
            ->with(['warehouse', 'item', 'contractor'])
            ->orderByDesc('actual_arrival_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (WmsOrderIncomingSchedule $schedule): array => [
                'id' => (int) $schedule->id,
                'warehouse_id' => (int) $schedule->warehouse_id,
                'warehouse_code' => $schedule->warehouse?->code,
                'warehouse_name' => $schedule->warehouse?->name,
                'slip_number' => $schedule->slip_number,
                'item_id' => (int) $schedule->item_id,
                'item_code' => $schedule->item_code ?? $schedule->item?->code,
                'contractor_id' => $schedule->contractor_id ? (int) $schedule->contractor_id : null,
                'contractor_code' => $schedule->contractor?->code,
                'actual_arrival_date' => $schedule->actual_arrival_date?->toDateString(),
                'expected_arrival_date' => $schedule->expected_arrival_date?->toDateString(),
                'received_piece_quantity' => $schedule->received_piece_quantity,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int>  $warehouseIds
     */
    private function handledItemIds(array $warehouseIds): array
    {
        return DB::connection('sakemaru')
            ->table('items as i')
            ->where('i.is_active', true)
            ->where(function ($query) use ($warehouseIds) {
                $query->whereExists(function ($subQuery) use ($warehouseIds) {
                    $subQuery
                        ->selectRaw('1')
                        ->from('item_incoming_default_locations as idl')
                        ->whereColumn('idl.item_id', 'i.id')
                        ->whereIn('idl.warehouse_id', $warehouseIds);
                })->orWhereExists(function ($subQuery) use ($warehouseIds) {
                    $subQuery
                        ->selectRaw('1')
                        ->from('real_stocks as rs')
                        ->whereColumn('rs.item_id', 'i.id')
                        ->whereIn('rs.warehouse_id', $warehouseIds);
                });
            })
            ->orderBy('i.code')
            ->pluck('i.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function items(int $warehouseId, int $itemContractorWarehouseId, array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $searchCodes = $this->searchCodesByItem($itemIds);
        $quantityCodes = $this->quantityCodesByItem($itemIds);
        $defaultLocations = $this->defaultLocationsByItem($warehouseId, $itemIds);
        $contractors = $this->contractorsByItem($itemContractorWarehouseId, $itemIds);

        return DB::connection('sakemaru')
            ->table('items as i')
            ->whereIn('i.id', $itemIds)
            ->where('i.is_active', true)
            ->orderBy('i.code')
            ->get([
                'i.id',
                'i.code',
                'i.name',
                'i.kana',
                'i.volume',
                'i.volume_unit',
                'i.capacity_case',
                'i.capacity_carton',
                'i.packaging',
                'i.temperature_type',
                'i.uses_expiration_date',
                'i.supplier_id',
            ])
            ->map(fn ($item): array => [
                'id' => (int) $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'kana' => $item->kana,
                'volume' => $item->volume,
                'volume_unit' => $item->volume_unit,
                'capacity_case' => $item->capacity_case !== null ? (int) $item->capacity_case : null,
                'capacity_carton' => $item->capacity_carton !== null ? (int) $item->capacity_carton : null,
                'packaging' => $item->packaging,
                'temperature_type' => $item->temperature_type,
                'uses_expiration_date' => (bool) $item->uses_expiration_date,
                'supplier_id' => $item->supplier_id ? (int) $item->supplier_id : null,
                'search_codes' => $searchCodes->get((int) $item->id, []),
                'item_quantity_codes' => $quantityCodes->get((int) $item->id, []),
                'default_location' => $defaultLocations->get((int) $item->id),
                'contractors' => $contractors->get((int) $item->id, []),
            ])
            ->values()
            ->all();
    }

    private function contractorsByItem(int $warehouseId, array $itemIds): Collection
    {
        return DB::connection('sakemaru')
            ->table('item_contractors as ic')
            ->join('contractors as c', 'c.id', '=', 'ic.contractor_id')
            ->where('ic.warehouse_id', $warehouseId)
            ->whereIn('ic.item_id', $itemIds)
            ->orderBy('ic.item_id')
            ->orderByDesc('ic.is_auto_order')
            ->orderBy('ic.id')
            ->get([
                'ic.item_id',
                'ic.warehouse_id',
                'ic.contractor_id',
                'ic.supplier_id',
                'ic.purchase_unit',
                'ic.is_auto_order',
                'c.code as contractor_code',
                'c.name as contractor_name',
            ])
            ->groupBy(fn ($row) => (int) $row->item_id)
            ->map(fn (Collection $rows) => $rows->map(fn ($row) => [
                'warehouse_id' => (int) $row->warehouse_id,
                'contractor_id' => (int) $row->contractor_id,
                'contractor_code' => $row->contractor_code,
                'contractor_name' => $row->contractor_name,
                'supplier_id' => $row->supplier_id ? (int) $row->supplier_id : null,
                'purchase_unit' => $row->purchase_unit !== null ? (int) $row->purchase_unit : null,
                'is_auto_order' => (bool) $row->is_auto_order,
            ])->values()->all());
    }

    private function searchCodesByItem(array $itemIds): Collection
    {
        return DB::connection('sakemaru')
            ->table('item_search_information')
            ->whereIn('item_id', $itemIds)
            ->whereNotNull('search_string')
            ->where('search_string', '!=', '')
            ->orderBy('item_id')
            ->orderBy('priority')
            ->get(['item_id', 'search_string', 'code_type', 'quantity_type', 'priority'])
            ->groupBy(fn ($row) => (int) $row->item_id)
            ->map(fn (Collection $rows) => $rows->map(fn ($row) => [
                'code' => (string) $row->search_string,
                'code_type' => $row->code_type,
                'quantity_type' => $row->quantity_type,
                'priority' => $row->priority !== null ? (int) $row->priority : null,
            ])->values()->all());
    }

    private function quantityCodesByItem(array $itemIds): Collection
    {
        return DB::connection('sakemaru')
            ->table('item_quantity_information')
            ->whereIn('item_id', $itemIds)
            ->where(function ($query) {
                $query->whereNotNull('product_code')
                    ->orWhereNotNull('own_code');
            })
            ->orderBy('item_id')
            ->orderBy('quantity')
            ->orderBy('id')
            ->get(['item_id', 'product_code', 'own_code', 'quantity_code', 'quantity', 'can_order'])
            ->groupBy(fn ($row) => (int) $row->item_id)
            ->map(fn (Collection $rows) => $rows->map(fn ($row) => [
                'product_code' => $row->product_code,
                'own_code' => $row->own_code,
                'quantity_code' => $row->quantity_code,
                'quantity' => $row->quantity !== null ? (int) $row->quantity : null,
                'can_order' => (bool) $row->can_order,
            ])->values()->all());
    }

    private function defaultLocationsByItem(int $warehouseId, array $itemIds): Collection
    {
        return DB::connection('sakemaru')
            ->table('item_incoming_default_locations as idl')
            ->join('locations as l', 'l.id', '=', 'idl.location_id')
            ->where('idl.warehouse_id', $warehouseId)
            ->whereIn('idl.item_id', $itemIds)
            ->get([
                'idl.item_id',
                'l.id',
                'l.warehouse_id',
                'l.floor_id',
                'l.code1',
                'l.code2',
                'l.code3',
                'l.name',
                'l.temperature_type',
                'l.is_restricted_area',
                'l.available_quantity_flags',
            ])
            ->keyBy(fn ($row) => (int) $row->item_id)
            ->map(fn ($row): array => $this->formatLocation($row, 'item_default'));
    }

    private function locations(int $warehouseId): array
    {
        return DB::connection('sakemaru')
            ->table('locations')
            ->where('warehouse_id', $warehouseId)
            ->where('is_disabled', false)
            ->orderBy('code1')
            ->orderBy('code2')
            ->orderBy('code3')
            ->get(['id', 'warehouse_id', 'floor_id', 'code1', 'code2', 'code3', 'name', 'temperature_type', 'is_restricted_area', 'available_quantity_flags'])
            ->map(fn ($location): array => $this->formatLocation($location))
            ->values()
            ->all();
    }

    private function formatItem($item): ?array
    {
        if (! $item) {
            return null;
        }

        return [
            'id' => (int) $item->id,
            'code' => $item->code,
            'name' => $item->name,
            'kana' => $item->kana,
            'volume' => $item->volume,
            'volume_unit' => $item->volume_unit,
            'capacity_case' => $item->capacity_case !== null ? (int) $item->capacity_case : null,
            'capacity_carton' => $item->capacity_carton !== null ? (int) $item->capacity_carton : null,
            'packaging' => $item->packaging,
            'search_codes' => $item->relationLoaded('item_search_information')
                ? $item->item_search_information->map(fn ($row) => [
                    'code' => $row->search_string,
                    'code_type' => $row->code_type,
                    'quantity_type' => $row->quantity_type,
                    'priority' => $row->priority !== null ? (int) $row->priority : null,
                ])->values()->all()
                : [],
        ];
    }

    private function formatLocation(object $location, string $source = 'location'): array
    {
        $code = Location::formatCode($location->code1, $location->code2, $location->code3, '-');

        return [
            'id' => (int) $location->id,
            'warehouse_id' => (int) $location->warehouse_id,
            'floor_id' => $location->floor_id ? (int) $location->floor_id : null,
            'code1' => $location->code1,
            'code2' => $location->code2,
            'code3' => $location->code3,
            'code' => $code,
            'display_name' => $location->name ? "{$code} {$location->name}" : $code,
            'name' => $location->name,
            'source' => $source,
            'temperature_type' => $location->temperature_type,
            'is_restricted_area' => (bool) $location->is_restricted_area,
            'available_quantity_flags' => $location->available_quantity_flags !== null
                ? (int) $location->available_quantity_flags
                : null,
        ];
    }

    /**
     * @return array<int>
     */
    private function matchingWarehouseIds(int $warehouseId): array
    {
        $warehouseIds = WarehouseResolver::resolveAllWarehouseIds($warehouseId);

        return $warehouseIds !== [] ? array_map('intval', $warehouseIds) : [$warehouseId];
    }
}
