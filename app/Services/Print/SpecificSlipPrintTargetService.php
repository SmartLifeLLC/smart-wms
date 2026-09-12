<?php

namespace App\Services\Print;

use App\Enums\QuantityType;
use App\Models\Sakemaru\ClientPrinterDriver;
use App\Models\Sakemaru\ClientSetting;
use App\Models\WmsPickingTask;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SpecificSlipPrintTargetService
{
    public function collectForRecord(WmsPickingTask $record): Collection
    {
        return $this->collect(
            (int) $record->delivery_course_id,
            $record->shipment_date instanceof \DateTimeInterface
                ? $record->shipment_date->format('Y-m-d')
                : (string) $record->shipment_date,
            (int) $record->warehouse_id,
            $record->wave_id ? (int) $record->wave_id : null,
        );
    }

    public function collect(
        int $deliveryCourseId,
        string $shipmentDate,
        int $warehouseId,
        ?int $waveId = null,
    ): Collection {
        $taskIds = WmsPickingTask::query()
            ->where('delivery_course_id', $deliveryCourseId)
            ->where('shipment_date', $shipmentDate)
            ->when($waveId, fn ($query) => $query->where('wave_id', $waveId))
            ->pluck('id')
            ->all();

        if (empty($taskIds)) {
            return collect();
        }

        $systemDate = ClientSetting::systemDateYMD();
        $currentDetails = DB::connection('sakemaru')->table('buyer_details')
            ->selectRaw('buyer_id, slip_type_id, ROW_NUMBER() OVER (PARTITION BY buyer_id ORDER BY start_date DESC) AS rn')
            ->where('start_date', '<=', $systemDate);

        $rows = DB::connection('sakemaru')->table('wms_picking_item_results as pir')
            ->join('wms_picking_tasks as pt', 'pir.picking_task_id', '=', 'pt.id')
            ->join('items as i', 'pir.item_id', '=', 'i.id')
            ->join('earnings as e', 'pir.earning_id', '=', 'e.id')
            ->join('trades as et', 'e.trade_id', '=', 'et.id')
            ->leftJoin('buyers as b', 'e.buyer_id', '=', 'b.id')
            ->leftJoin('partners as bp', 'b.partner_id', '=', 'bp.id')
            ->leftJoin('trade_items as ti', 'pir.trade_item_id', '=', 'ti.id')
            ->leftJoin('locations as l', 'pir.location_id', '=', 'l.id')
            ->joinSub($currentDetails, 'bd', function ($join) {
                $join->on('e.buyer_id', '=', 'bd.buyer_id');
            })
            ->join('slip_types as st', 'bd.slip_type_id', '=', 'st.id')
            ->whereIn('pir.picking_task_id', $taskIds)
            ->where('pir.ordered_qty', '>', 0)
            ->where('bd.rn', 1)
            ->where('st.category', 2)
            ->where('e.is_active', true)
            ->where('et.is_active', true)
            ->where(function ($query) {
                $query->whereNull('ti.id')
                    ->orWhere('ti.is_active', true);
            })
            ->select([
                'pir.id as picking_item_result_id',
                'pir.trade_item_id',
                'pir.item_id',
                'pir.ordered_qty',
                'pir.ordered_qty_type',
                'pir.planned_qty',
                'pir.planned_qty_type',
                'pir.picked_qty',
                'pir.picked_qty_type',
                'e.id as earning_id',
                'et.id as trade_id',
                'et.serial_id as trade_serial_id',
                'e.buyer_id',
                'bp.code as buyer_code',
                'bp.name as buyer_name',
                'e.client_id',
                'e.warehouse_id',
                'i.code as item_code',
                'i.name as item_master_name',
                'i.capacity_case as item_capacity_case',
                'i.capacity_carton as item_capacity_carton',
                'ti.item_name as trade_item_name',
                'ti.order_of_items_in_slip',
                'ti.order_quantity',
                'ti.order_quantity_type',
                'ti.quantity as trade_quantity',
                'ti.quantity_type as trade_quantity_type',
                'ti.capacity_case as trade_capacity_case',
                'ti.capacity_carton as trade_capacity_carton',
                'st.id as slip_type_id',
                'st.name as slip_type_name',
                'st.printer_key',
            ])
            ->orderByRaw('COALESCE(l.floor_id, 999999)')
            ->orderByRaw("COALESCE(l.code1, 'ZZZ')")
            ->orderByRaw("COALESCE(l.code2, 'ZZZ')")
            ->orderByRaw("COALESCE(l.code3, 'ZZZ')")
            ->orderBy('i.code')
            ->orderBy('e.id')
            ->get();

        return $rows
            ->groupBy(fn ($row) => ((int) $row->slip_type_id).'|'.((int) $row->warehouse_id))
            ->map(function (Collection $group) use ($warehouseId) {
                $first = $group->first();
                $printerKey = $this->normalizePrinterKey($first->printer_key);
                $clientId = (int) $first->client_id;
                $resolvedWarehouseId = (int) ($first->warehouse_id ?: $warehouseId);
                $slipTypeId = (int) $first->slip_type_id;
                $earningIds = $group->pluck('earning_id')
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values()
                    ->all();
                $slips = $group
                    ->groupBy('earning_id')
                    ->map(function (Collection $slipRows) {
                        $row = $slipRows->first();
                        $items = $this->buildSlipItemDetails($slipRows);

                        return [
                            'earning_id' => (int) $row->earning_id,
                            'trade_id' => $row->trade_id ? (int) $row->trade_id : null,
                            'serial_id' => $row->trade_serial_id !== null ? (string) $row->trade_serial_id : null,
                            'buyer_id' => $row->buyer_id ? (int) $row->buyer_id : null,
                            'buyer_code' => $row->buyer_code !== null ? (string) $row->buyer_code : null,
                            'buyer_name' => $row->buyer_name !== null ? (string) $row->buyer_name : null,
                            'line_count' => count($items),
                            'items' => $items,
                        ];
                    })
                    ->sortBy([
                        ['serial_id', 'asc'],
                        ['earning_id', 'asc'],
                    ])
                    ->values()
                    ->all();

                $printerInfo = $this->resolvePrinterInfo(
                    $clientId,
                    $resolvedWarehouseId,
                    $printerKey,
                );

                return [
                    'client_id' => $clientId,
                    'warehouse_id' => $resolvedWarehouseId,
                    'slip_type_id' => $slipTypeId,
                    'slip_type_name' => (string) $first->slip_type_name,
                    'printer_key' => $printerKey,
                    'confirmation_key' => $this->confirmationKey($clientId, $resolvedWarehouseId, $slipTypeId, $printerKey),
                    'earning_ids' => $earningIds,
                    'earning_count' => count($earningIds),
                    'slips' => $slips,
                    'has_active_printer' => $printerInfo['has_active_printer'],
                    'printer_names' => $printerInfo['printer_names'],
                    'printer_display_name' => $printerInfo['printer_display_name'],
                    'can_print_continuously' => $printerInfo['can_print_continuously'],
                    'requires_paper_confirmation' => $printerInfo['requires_paper_confirmation'],
                    'can_print' => $printerKey !== null && $printerInfo['has_active_printer'],
                    'disabled_reason' => $this->disabledReason($printerKey, $printerInfo['has_active_printer']),
                ];
            })
            ->values()
            ->map(function (array $group, int $index) {
                $group['print_order'] = $index + 1;

                return $group;
            });
    }

    private function buildSlipItemDetails(Collection $slipRows): array
    {
        return $slipRows
            ->groupBy(fn ($row) => $row->trade_item_id
                ? 'trade_item_'.(int) $row->trade_item_id
                : 'picking_result_'.(int) $row->picking_item_result_id)
            ->map(function (Collection $itemRows) {
                $row = $itemRows->first();
                $capacityCase = max(1, (int) ($row->trade_capacity_case ?: $row->item_capacity_case ?: 1));
                $capacityCarton = max(1, (int) ($row->trade_capacity_carton ?: $row->item_capacity_carton ?: 1));
                $orderedQuantity = $row->order_quantity !== null
                    ? (int) $row->order_quantity
                    : (int) $row->ordered_qty;
                $orderedQuantityType = $row->order_quantity_type ?: $row->ordered_qty_type;
                $shipmentParts = $row->trade_quantity !== null
                    ? $this->quantityParts((int) $row->trade_quantity, $row->trade_quantity_type, $capacityCase, $capacityCarton)
                    : $this->shipmentPartsFromPickingRows($itemRows, $capacityCase, $capacityCarton);
                $orderedParts = $this->quantityParts($orderedQuantity, $orderedQuantityType, $capacityCase, $capacityCarton);

                return [
                    'trade_item_id' => $row->trade_item_id ? (int) $row->trade_item_id : null,
                    'item_id' => $row->item_id ? (int) $row->item_id : null,
                    'item_code' => $row->item_code !== null ? (string) $row->item_code : null,
                    'item_name' => filled($row->trade_item_name ?? null)
                        ? (string) $row->trade_item_name
                        : (string) ($row->item_master_name ?? ''),
                    'sort_order' => $row->order_of_items_in_slip !== null
                        ? (int) $row->order_of_items_in_slip
                        : PHP_INT_MAX,
                    'ordered_case_qty' => $orderedParts['case'],
                    'shipment_case_qty' => $shipmentParts['case'],
                    'ordered_piece_qty' => $orderedParts['piece'],
                    'shipment_piece_qty' => $shipmentParts['piece'],
                ];
            })
            ->sortBy(fn (array $item) => sprintf(
                '%012d-%s-%012d',
                $item['sort_order'],
                $item['item_code'] ?? '',
                $item['trade_item_id'] ?? 0,
            ))
            ->values()
            ->all();
    }

    private function shipmentPartsFromPickingRows(Collection $rows, int $capacityCase, int $capacityCarton): array
    {
        return $rows->reduce(function (array $carry, $row) use ($capacityCase, $capacityCarton) {
            $hasPickedQuantity = (int) ($row->picked_qty ?? 0) > 0;
            $quantity = $hasPickedQuantity
                ? (int) $row->picked_qty
                : (int) ($row->planned_qty ?? 0);
            $quantityType = $hasPickedQuantity
                ? ($row->picked_qty_type ?: $row->planned_qty_type ?: $row->ordered_qty_type)
                : ($row->planned_qty_type ?: $row->ordered_qty_type);
            $parts = $this->quantityParts($quantity, $quantityType, $capacityCase, $capacityCarton);

            return [
                'case' => $carry['case'] + $parts['case'],
                'piece' => $carry['piece'] + $parts['piece'],
            ];
        }, ['case' => 0, 'piece' => 0]);
    }

    private function quantityParts(int $quantity, ?string $quantityType, int $capacityCase, int $capacityCarton): array
    {
        $quantity = max(0, $quantity);

        return match ($quantityType) {
            QuantityType::CASE->value => ['case' => $quantity, 'piece' => 0],
            QuantityType::CARTON->value => ['case' => 0, 'piece' => $quantity * max(1, $capacityCarton)],
            QuantityType::PIECE->value => ['case' => 0, 'piece' => $quantity],
            default => ['case' => 0, 'piece' => $quantity],
        };
    }

    private function resolvePrinterInfo(int $clientId, int $warehouseId, ?string $printerKey): array
    {
        if (! $printerKey) {
            return [
                'has_active_printer' => false,
                'printer_names' => [],
                'printer_display_name' => null,
                'can_print_continuously' => true,
                'requires_paper_confirmation' => false,
            ];
        }

        $baseQuery = ClientPrinterDriver::query()
            ->where('client_id', $clientId)
            ->where('printer_key', $printerKey)
            ->where('is_active', true);

        $warehousePrinters = (clone $baseQuery)
            ->where('warehouse_id', $warehouseId)
            ->get();

        $printers = $warehousePrinters->isNotEmpty()
            ? $warehousePrinters
            : $baseQuery->get();

        if ($printers->isEmpty()) {
            return [
                'has_active_printer' => false,
                'printer_names' => [],
                'printer_display_name' => null,
                'can_print_continuously' => true,
                'requires_paper_confirmation' => false,
            ];
        }

        $printerNames = $printers
            ->map(fn (ClientPrinterDriver $printer) => filled($printer->user_name) ? $printer->user_name : $printer->display_name)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $canPrintContinuously = ! $printers->contains(
            fn (ClientPrinterDriver $printer) => $printer->can_print_continuously === false
        );

        return [
            'has_active_printer' => true,
            'printer_names' => $printerNames,
            'printer_display_name' => $printerNames === [] ? null : implode(' / ', $printerNames),
            'can_print_continuously' => $canPrintContinuously,
            'requires_paper_confirmation' => ! $canPrintContinuously,
        ];
    }

    private function disabledReason(?string $printerKey, bool $hasActivePrinter): ?string
    {
        if (! $printerKey) {
            return '専用伝票のプリンター未設定';
        }

        if (! $hasActivePrinter) {
            return '対応する有効プリンターなし';
        }

        return null;
    }

    private function normalizePrinterKey(?string $printerKey): ?string
    {
        $printerKey = trim((string) $printerKey);

        return $printerKey === '' ? null : $printerKey;
    }

    private function confirmationKey(int $clientId, int $warehouseId, int $slipTypeId, ?string $printerKey): string
    {
        return implode('_', [
            $clientId,
            $warehouseId,
            $slipTypeId,
            substr(sha1((string) $printerKey), 0, 10),
        ]);
    }
}
