<?php

namespace App\Services\OutboundInspection;

use App\Enums\EItemSearchCodeType;
use App\Enums\QuantityType;
use App\Models\Sakemaru\Location;
use App\Models\WmsPickingItemResult;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OutboundInspectionSnapshotService
{
    private const LIST_TYPE = 'secondary_v2';

    public function buildSnapshot(int $warehouseId, string $period, string $workingDate): ?array
    {
        $waveGroup = $this->latestWaveGroup($warehouseId, $period, $workingDate);

        if (! $waveGroup) {
            return null;
        }

        $waveIds = $this->db()
            ->table('wms_waves')
            ->where('wave_group_id', $waveGroup->id)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $warehouse = $this->db()
            ->table('warehouses')
            ->where('id', $warehouseId)
            ->first(['id', 'code', 'name']);

        $rows = empty($waveIds) ? collect() : $this->fetchRows($warehouseId, $waveIds);
        $scanCodesByItem = $this->fetchScanCodesByItem($rows->pluck('item_id')->unique()->values()->all());
        $courses = $this->buildCourses($waveGroup->id, $rows, $scanCodesByItem);

        return [
            'warehouse' => [
                'id' => (int) ($warehouse->id ?? $warehouseId),
                'code' => (string) ($warehouse->code ?? ''),
                'name' => (string) ($warehouse->name ?? ''),
            ],
            'business_date' => $workingDate,
            'period' => $period,
            'period_label' => $period === 'morning' ? '午前' : '午後',
            'generated_at' => now()->toIso8601String(),
            'source' => [
                'wave_group_id' => (int) $waveGroup->id,
                'group_no' => (string) $waveGroup->group_no,
                'shipping_date' => $this->dateString($waveGroup->shipping_date),
                'created_at' => $this->dateTimeString($waveGroup->created_at),
                'list_type' => self::LIST_TYPE,
                'wave_ids' => $waveIds,
            ],
            'courses' => $courses,
            'summary' => $this->summary($courses),
        ];
    }

    private function latestWaveGroup(int $warehouseId, string $period, string $workingDate): ?object
    {
        $query = $this->db()
            ->table('wms_wave_groups')
            ->where('warehouse_id', $warehouseId)
            ->whereDate('shipping_date', $workingDate)
            ->whereNull('cancelled_at')
            ->where(function ($query) {
                $query->whereNull('target_document_types')
                    ->orWhereJsonContains('target_document_types', 'shipment');
            });

        if ($period === 'morning') {
            $query->whereTime('created_at', '<', '12:00:00');
        } else {
            $query->whereTime('created_at', '>=', '12:00:00');
        }

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first(['id', 'group_no', 'shipping_date', 'created_at']);
    }

    private function fetchRows(int $warehouseId, array $waveIds): Collection
    {
        return $this->db()
            ->table('wms_picking_item_results as pir')
            ->join('wms_picking_tasks as pt', 'pir.picking_task_id', '=', 'pt.id')
            ->leftJoin('delivery_courses as dc', 'pt.delivery_course_id', '=', 'dc.id')
            ->join('items as i', 'pir.item_id', '=', 'i.id')
            ->leftJoin('locations as l', 'pir.location_id', '=', 'l.id')
            ->leftJoin('item_incoming_default_locations as idl', function ($join) {
                $join->on('idl.item_id', '=', 'pir.item_id')
                    ->whereColumn('idl.warehouse_id', 'pt.warehouse_id');
            })
            ->leftJoin('locations as default_l', 'idl.location_id', '=', 'default_l.id')
            ->leftJoin('floors as f', DB::raw('COALESCE(l.floor_id, default_l.floor_id, pt.floor_id)'), '=', 'f.id')
            ->whereIn('pt.wave_id', $waveIds)
            ->where('pt.warehouse_id', $warehouseId)
            ->where('pir.source_type', WmsPickingItemResult::SOURCE_TYPE_EARNING)
            ->where(function ($query) {
                $query->where('pir.planned_qty', '>', 0)
                    ->orWhere('pir.shortage_qty', '>', 0);
            })
            ->select([
                'pir.id as picking_item_result_id',
                'pir.earning_id',
                'pir.item_id',
                'pir.ordered_qty',
                'pir.ordered_qty_type',
                'pir.planned_qty',
                'pir.planned_qty_type',
                'pir.shortage_qty',
                'dc.id as delivery_course_id',
                'dc.code as delivery_course_code',
                'dc.name as delivery_course_name',
                'i.code as item_code',
                'i.name as item_name',
                'i.packaging',
                'i.capacity_case',
                'i.capacity_carton',
                DB::raw('COALESCE(pir.location_id, idl.location_id) as location_id'),
                DB::raw('COALESCE(l.code1, default_l.code1) as code1'),
                DB::raw('COALESCE(l.code2, default_l.code2) as code2'),
                DB::raw('COALESCE(l.code3, default_l.code3) as code3'),
                DB::raw('COALESCE(l.floor_id, default_l.floor_id, pt.floor_id) as floor_id'),
                'f.name as floor_name',
            ])
            ->orderBy('dc.code')
            ->orderByRaw('COALESCE(l.floor_id, default_l.floor_id, pt.floor_id, 999999)')
            ->orderByRaw("COALESCE(l.code1, default_l.code1, 'ZZZ')")
            ->orderByRaw("COALESCE(l.code2, default_l.code2, 'ZZZ')")
            ->orderByRaw("COALESCE(l.code3, default_l.code3, 'ZZZ')")
            ->orderBy('i.code')
            ->orderBy('pir.id')
            ->get();
    }

    private function fetchScanCodesByItem(array $itemIds): Collection
    {
        if (empty($itemIds)) {
            return collect();
        }

        return $this->db()
            ->table('item_search_information as isi')
            ->whereIn('isi.item_id', $itemIds)
            ->where('isi.is_active', 1)
            ->whereIn('isi.code_type', [
                EItemSearchCodeType::JAN->value,
                EItemSearchCodeType::SDP->value,
                EItemSearchCodeType::OTHER->value,
            ])
            ->whereNotNull('isi.search_string')
            ->where('isi.search_string', '!=', '')
            ->orderBy('isi.item_id')
            ->orderByRaw('isi.priority IS NULL')
            ->orderBy('isi.priority')
            ->orderBy('isi.id')
            ->get([
                'isi.item_id',
                'isi.search_string',
                'isi.code_type',
                'isi.quantity_type',
                'isi.priority',
            ])
            ->groupBy(fn ($row) => (int) $row->item_id);
    }

    private function buildCourses(int $waveGroupId, Collection $rows, Collection $scanCodesByItem): array
    {
        $buckets = [];

        foreach ($rows as $row) {
            $courseId = (int) ($row->delivery_course_id ?? 0);
            $floor = $this->floorPayload($row);
            $locationKey = $row->location_id ?? 0;
            $itemKey = $courseId.'|'.$floor['floor_key'].'|'.$locationKey.'|'.(int) $row->item_id;

            if (! isset($buckets[$courseId])) {
                $buckets[$courseId] = [
                    'delivery_course_id' => $courseId,
                    'delivery_course_code' => (string) ($row->delivery_course_code ?? ''),
                    'delivery_course_name' => (string) ($row->delivery_course_name ?? ''),
                    'floors' => [],
                ];
            }

            if (! isset($buckets[$courseId]['floors'][$floor['floor_key']])) {
                $buckets[$courseId]['floors'][$floor['floor_key']] = [
                    'floor_key' => $floor['floor_key'],
                    'floor_label' => $floor['floor_label'],
                    'floor_sort' => $floor['floor_sort'],
                    '_items' => [],
                ];
            }

            if (! isset($buckets[$courseId]['floors'][$floor['floor_key']]['_items'][$itemKey])) {
                $capacityCase = max(1, (int) ($row->capacity_case ?: 1));
                $scanCodes = $this->scanCodesForItem((int) $row->item_id, $scanCodesByItem);

                $buckets[$courseId]['floors'][$floor['floor_key']]['_items'][$itemKey] = [
                    'inspection_item_id' => "WG{$waveGroupId}-C{$courseId}-{$floor['floor_key']}-L{$locationKey}-I{$row->item_id}",
                    'item_id' => (int) $row->item_id,
                    'item_code' => (string) ($row->item_code ?? ''),
                    'item_name' => $this->normalizeItemName((string) ($row->item_name ?? '')),
                    'packaging' => $row->packaging,
                    'capacity_case' => $capacityCase,
                    'capacity_carton' => $row->capacity_carton !== null ? (int) $row->capacity_carton : null,
                    'location' => [
                        'location_id' => $row->location_id !== null ? (int) $row->location_id : null,
                        'location_code' => $row->location_id !== null
                            ? $this->formatLocationCode($row->code1, $row->code2, $row->code3)
                            : '',
                        'floor_id' => $row->floor_id !== null ? (int) $row->floor_id : null,
                        'floor_name' => $floor['floor_label'],
                    ],
                    '_ordered_total_pieces' => 0,
                    '_planned_total_pieces' => 0,
                    '_shortage_total_pieces' => 0,
                    '_ordered_quantity_type' => $row->ordered_qty_type ?? QuantityType::PIECE->value,
                    '_planned_quantity_type' => $row->planned_qty_type ?? QuantityType::PIECE->value,
                    '_picking_item_result_ids' => [],
                    'scan_codes' => $scanCodes,
                ];
            }

            $item = &$buckets[$courseId]['floors'][$floor['floor_key']]['_items'][$itemKey];
            $item['_ordered_total_pieces'] += $this->toPieces((int) $row->ordered_qty, $row->ordered_qty_type, $item['capacity_case']);
            $item['_planned_total_pieces'] += $this->toPieces((int) $row->planned_qty, $row->planned_qty_type, $item['capacity_case']);
            $item['_shortage_total_pieces'] += (int) $row->shortage_qty;
            $item['_picking_item_result_ids'][] = (int) $row->picking_item_result_id;
            unset($item);
        }

        return collect($buckets)
            ->sortBy('delivery_course_code')
            ->map(function (array $course): array {
                $floors = collect($course['floors'])
                    ->sortBy('floor_sort')
                    ->map(function (array $floor): array {
                        $items = collect($floor['_items'])
                            ->map(fn (array $item): array => $this->finalizeItem($item))
                            ->values()
                            ->all();

                        unset($floor['_items']);
                        $floor['items'] = $items;
                        $floor['summary'] = $this->itemSummary(collect($items));

                        return $floor;
                    })
                    ->values()
                    ->all();

                $course['floors'] = $floors;
                $course['summary'] = $this->summary([['floors' => $floors]]);

                return $course;
            })
            ->values()
            ->all();
    }

    private function finalizeItem(array $item): array
    {
        $capacityCase = max(1, (int) $item['capacity_case']);
        $orderedTotalPieces = (int) $item['_ordered_total_pieces'];
        $plannedTotalPieces = (int) $item['_planned_total_pieces'];
        $shortageTotalPieces = (int) $item['_shortage_total_pieces'];

        $item['ordered_quantity'] = $this->quantityPayload(
            $orderedTotalPieces,
            (string) $item['_ordered_quantity_type'],
            $capacityCase
        );
        $item['planned_quantity'] = $this->quantityPayload(
            $plannedTotalPieces,
            (string) $item['_planned_quantity_type'],
            $capacityCase
        );
        $item['shortage_quantity'] = $this->quantityPayload(
            $shortageTotalPieces,
            (string) $item['_planned_quantity_type'],
            $capacityCase
        );
        $item['source'] = [
            'wms_picking_item_result_ids' => array_values(array_unique($item['_picking_item_result_ids'])),
            'source_type' => WmsPickingItemResult::SOURCE_TYPE_EARNING,
        ];

        unset(
            $item['_ordered_total_pieces'],
            $item['_planned_total_pieces'],
            $item['_shortage_total_pieces'],
            $item['_ordered_quantity_type'],
            $item['_planned_quantity_type'],
            $item['_picking_item_result_ids'],
        );

        return $item;
    }

    private function floorPayload(object $row): array
    {
        $code1 = (string) ($row->code1 ?? '');
        $isYx = str_starts_with($code1, 'YA')
            || str_starts_with($code1, 'YB')
            || str_starts_with($code1, 'YC')
            || str_starts_with($code1, 'YX');

        if ($isYx) {
            return [
                'floor_key' => 'YX',
                'floor_label' => 'YX',
                'floor_sort' => 999,
            ];
        }

        $label = $this->floorLabel($row->floor_name, $row->floor_id);
        $floorNo = $this->floorNumber($label);

        return [
            'floor_key' => $label,
            'floor_label' => $label,
            'floor_sort' => $floorNo ?? 998,
        ];
    }

    private function floorLabel(?string $floorName, $floorId): string
    {
        if ($floorName && preg_match('/(\d+F)$/u', $floorName, $matches)) {
            return $matches[1];
        }

        if ($floorName) {
            return $floorName;
        }

        return $floorId ? "floor:{$floorId}" : '未設定';
    }

    private function floorNumber(string $label): ?int
    {
        if (preg_match('/^(\d+)F$/', $label, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private function formatLocationCode(?string $code1, ?string $code2, ?string $code3): string
    {
        if ($code1 === 'Z' && $code2 === '0' && $code3 === '0') {
            return '';
        }

        return Location::formatCode($code1, $code2, $code3, '-');
    }

    private function scanCodesForItem(int $itemId, Collection $scanCodesByItem): array
    {
        return collect($scanCodesByItem->get($itemId, []))
            ->map(fn ($row): array => [
                'code' => (string) $row->search_string,
                'code_type' => (string) $row->code_type,
                'quantity_type' => $row->quantity_type !== null ? (string) $row->quantity_type : null,
                'priority' => $row->priority !== null ? (int) $row->priority : null,
            ])
            ->values()
            ->all();
    }

    private function quantityPayload(int $totalPieces, string $quantityType, int $capacityCase): array
    {
        return [
            'quantity' => $quantityType === QuantityType::CASE->value
                ? intdiv($totalPieces, max(1, $capacityCase))
                : $totalPieces,
            'quantity_type' => $quantityType,
            'case_qty' => intdiv($totalPieces, max(1, $capacityCase)),
            'piece_qty' => $totalPieces % max(1, $capacityCase),
            'total_piece_qty' => $totalPieces,
        ];
    }

    private function toPieces(int $quantity, ?string $quantityType, int $capacityCase): int
    {
        return $quantityType === QuantityType::CASE->value
            ? $quantity * max(1, $capacityCase)
            : $quantity;
    }

    private function summary(array $courses): array
    {
        $floors = collect($courses)->flatMap(fn (array $course) => $course['floors'] ?? []);
        $items = $floors->flatMap(fn (array $floor) => $floor['items'] ?? []);

        return $this->itemSummary($items, count($courses), $floors->count());
    }

    private function itemSummary(Collection $items, int $courseCount = 0, int $floorCount = 0): array
    {
        return [
            'course_count' => $courseCount,
            'floor_count' => $floorCount,
            'item_count' => $items->count(),
            'scan_code_count' => $items->sum(fn (array $item) => count($item['scan_codes'] ?? [])),
            'total_case_qty' => $items->sum(fn (array $item) => (int) ($item['planned_quantity']['case_qty'] ?? 0)),
            'total_piece_qty' => $items->sum(fn (array $item) => (int) ($item['planned_quantity']['piece_qty'] ?? 0)),
            'total_pieces' => $items->sum(fn (array $item) => (int) ($item['planned_quantity']['total_piece_qty'] ?? 0)),
        ];
    }

    private function normalizeItemName(string $name): string
    {
        return preg_replace('/\s+/u', ' ', trim($name)) ?: $name;
    }

    private function dateString(mixed $value): string
    {
        return $value instanceof CarbonInterface ? $value->toDateString() : (string) $value;
    }

    private function dateTimeString(mixed $value): string
    {
        return $value instanceof CarbonInterface ? $value->toIso8601String() : (string) $value;
    }

    private function db()
    {
        return DB::connection('sakemaru');
    }
}
