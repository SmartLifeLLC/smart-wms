<?php

namespace App\Services\InventoryCount;

use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InventoryCountMovementService
{
    public function calculatePostCountMovements(WmsInventoryCount $inventoryCount, CarbonInterface|string $countedAt): array
    {
        $baseAt = CarbonImmutable::parse($countedAt);

        if ($baseAt->greaterThan(now())) {
            throw new \RuntimeException('未来日時を棚卸し実施日時にはできません。');
        }

        return DB::connection('sakemaru')->transaction(function () use ($inventoryCount, $baseAt) {
            $this->assertMovementColumnsExist();

            $inventoryCount = WmsInventoryCount::query()
                ->whereKey($inventoryCount->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($inventoryCount->isCurrentStockSaved()) {
                throw new \RuntimeException('現状保存後の棚卸しは受払計算できません。カウント再開後に実行してください。');
            }

            if (in_array($inventoryCount->status, [
                WmsInventoryCount::STATUS_CONFIRMED,
                WmsInventoryCount::STATUS_CANCELLED,
            ], true)) {
                throw new \RuntimeException('確定済または取消済の棚卸しは受払計算できません。');
            }

            $itemIds = WmsInventoryCountItem::query()
                ->where('inventory_count_id', $inventoryCount->id)
                ->withoutOwnedSetItems()
                ->where(function ($query) {
                    $query
                        ->whereNotNull('first_count_quantity')
                        ->orWhereNotNull('second_count_quantity')
                        ->orWhereNotNull('final_count_quantity');
                })
                ->distinct()
                ->pluck('item_id')
                ->map(fn ($itemId) => (int) $itemId)
                ->filter()
                ->values()
                ->all();

            WmsInventoryCountItem::query()
                ->where('inventory_count_id', $inventoryCount->id)
                ->withoutOwnedSetItems()
                ->update([
                    'post_count_movement_quantity' => null,
                    'updated_at' => now(),
                ]);

            $fromDate = $baseAt->toDateString();
            $endDate = now()->toDateString();
            $totals = [];

            foreach (array_chunk($itemIds, 500) as $chunkItemIds) {
                foreach ($this->netMovementsByItem(
                    clientId: (int) $inventoryCount->client_id,
                    warehouseId: (int) $inventoryCount->warehouse_id,
                    itemIds: $chunkItemIds,
                    fromDate: $fromDate,
                    endDate: $endDate,
                ) as $itemId => $quantity) {
                    $totals[$itemId] = ($totals[$itemId] ?? 0.0) + (float) $quantity;
                }
            }

            foreach (array_chunk($itemIds, 500) as $chunkItemIds) {
                foreach ($chunkItemIds as $itemId) {
                    WmsInventoryCountItem::query()
                        ->where('inventory_count_id', $inventoryCount->id)
                        ->withoutOwnedSetItems()
                        ->where('item_id', $itemId)
                        ->where(function ($query) {
                            $query
                                ->whereNotNull('first_count_quantity')
                                ->orWhereNotNull('second_count_quantity')
                                ->orWhereNotNull('final_count_quantity');
                        })
                        ->update([
                            'post_count_movement_quantity' => $totals[$itemId] ?? 0,
                            'updated_at' => now(),
                        ]);
                }
            }

            $inventoryCount->update([
                'stock_movement_from_at' => $baseAt,
                'stock_movement_calculated_at' => now(),
            ]);

            return [
                'from_at' => $baseAt->format('Y-m-d H:i:s'),
                'from_date' => $fromDate,
                'end_date' => $endDate,
                'counted_item_count' => count($itemIds),
                'moved_item_count' => collect($totals)
                    ->filter(fn (float $quantity) => abs($quantity) > 0.0001)
                    ->count(),
                'movement_total' => array_sum($totals),
            ];
        });
    }

    /**
     * ai-core /stats/item-stock-movements と同じ伝票数量・日付基準で、在庫残に効く純増減を商品単位に集計する。
     *
     * @return array<int, float>
     */
    private function netMovementsByItem(int $clientId, int $warehouseId, array $itemIds, string $fromDate, string $endDate): array
    {
        $totals = [];

        foreach ([
            $this->purchaseMovementRows($clientId, $warehouseId, $itemIds, $fromDate, $endDate),
            $this->earningMovementRows($clientId, $warehouseId, $itemIds, $fromDate, $endDate),
            $this->retailMovementRows($warehouseId, $itemIds, $fromDate, $endDate),
            $this->stockTransferOutMovementRows($clientId, $warehouseId, $itemIds, $fromDate, $endDate),
            $this->stockTransferInMovementRows($clientId, $warehouseId, $itemIds, $fromDate, $endDate),
            $this->stockDisposalMovementRows($clientId, $warehouseId, $itemIds, $fromDate, $endDate),
            $this->adjustmentMovementRows('stock_adjustments', 'stock_adjustment_items', 'adjustment_date', 'stock_adjustment_quantity', $clientId, $warehouseId, $itemIds, $fromDate, $endDate),
            $this->inventoryAdjustmentMovementRows($clientId, $warehouseId, $itemIds, $fromDate, $endDate),
            $this->containerPickupMovementRows($clientId, $warehouseId, $itemIds, $fromDate, $endDate),
            $this->containerReturnMovementRows($clientId, $warehouseId, $itemIds, $fromDate, $endDate),
        ] as $rows) {
            foreach ($rows as $row) {
                $itemId = (int) $row->item_id;
                $totals[$itemId] = ($totals[$itemId] ?? 0.0) + (float) $row->movement_quantity;
            }
        }

        return $totals;
    }

    private function purchaseMovementRows(int $clientId, int $warehouseId, array $itemIds, string $fromDate, string $endDate): Collection
    {
        $pieceQty = $this->pieceQty();

        return DB::connection('sakemaru')->table('trade_items as ti')
            ->join('trades as t', 't.id', '=', 'ti.trade_id')
            ->join('purchases as p', 'p.trade_id', '=', 't.id')
            ->where('t.client_id', $clientId)
            ->whereIn('ti.item_id', $itemIds)
            ->where('p.warehouse_id', $warehouseId)
            ->where('t.trade_category', 'PURCHASE')
            ->where('t.is_active', true)
            ->where('ti.is_active', true)
            ->whereBetween('t.process_date', [$fromDate, $endDate])
            ->groupBy('ti.item_id')
            ->select([
                'ti.item_id',
                DB::raw("SUM(CASE WHEN COALESCE(t.is_returned, 0) = 1 OR COALESCE(t.trade_direction, 'NORMAL') = 'RETURN' THEN -ABS({$pieceQty}) ELSE ABS({$pieceQty}) END) as movement_quantity"),
            ])
            ->get();
    }

    private function earningMovementRows(int $clientId, int $warehouseId, array $itemIds, string $fromDate, string $endDate): Collection
    {
        $pieceQty = $this->pieceQty();

        return DB::connection('sakemaru')->table('trade_items as ti')
            ->join('trades as t', 't.id', '=', 'ti.trade_id')
            ->join('earnings as e', 'e.trade_id', '=', 't.id')
            ->where('t.client_id', $clientId)
            ->whereIn('ti.item_id', $itemIds)
            ->where('e.warehouse_id', $warehouseId)
            ->where('t.trade_category', 'EARNING')
            ->where('t.is_active', true)
            ->where('ti.is_active', true)
            ->whereBetween(DB::raw('COALESCE(e.delivered_date, t.process_date)'), [$fromDate, $endDate])
            ->groupBy('ti.item_id')
            ->select([
                'ti.item_id',
                DB::raw("SUM(-1 * ({$pieceQty})) as movement_quantity"),
            ])
            ->get();
    }

    private function retailMovementRows(int $warehouseId, array $itemIds, string $fromDate, string $endDate): Collection
    {
        if (! $this->hasTable('ret_pos_stock_applications')) {
            return collect();
        }

        return DB::connection('sakemaru')->table('ret_pos_stock_applications as app')
            ->where('app.warehouse_id', $warehouseId)
            ->whereIn('app.item_id', $itemIds)
            ->whereBetween('app.business_date', [$fromDate, $endDate])
            ->groupBy('app.item_id')
            ->select([
                'app.item_id',
                DB::raw('SUM(-1 * app.quantity) as movement_quantity'),
            ])
            ->get();
    }

    private function stockTransferOutMovementRows(int $clientId, int $warehouseId, array $itemIds, string $fromDate, string $endDate): Collection
    {
        $pieceQty = $this->pieceQty();
        $movementDate = 'COALESCE(st.picking_date, st.delivered_date)';

        return DB::connection('sakemaru')->table('trade_items as ti')
            ->join('trades as t', 't.id', '=', 'ti.trade_id')
            ->join('stock_transfers as st', 'st.trade_id', '=', 't.id')
            ->where('t.client_id', $clientId)
            ->whereIn('ti.item_id', $itemIds)
            ->where('st.from_warehouse_id', $warehouseId)
            ->where('t.trade_category', 'STOCK_TRANSFER')
            ->where('t.is_active', true)
            ->where('ti.is_active', true)
            ->whereBetween(DB::raw($movementDate), [$fromDate, $endDate])
            ->groupBy('ti.item_id')
            ->select([
                'ti.item_id',
                DB::raw("SUM(-ABS({$pieceQty})) as movement_quantity"),
            ])
            ->get();
    }

    private function stockTransferInMovementRows(int $clientId, int $warehouseId, array $itemIds, string $fromDate, string $endDate): Collection
    {
        $pieceQty = $this->pieceQty();

        return DB::connection('sakemaru')->table('trade_items as ti')
            ->join('trades as t', 't.id', '=', 'ti.trade_id')
            ->join('stock_transfers as st', 'st.trade_id', '=', 't.id')
            ->where('t.client_id', $clientId)
            ->whereIn('ti.item_id', $itemIds)
            ->where('st.to_warehouse_id', $warehouseId)
            ->where('st.is_delivered', true)
            ->where('t.trade_category', 'STOCK_TRANSFER')
            ->where('t.is_active', true)
            ->where('ti.is_active', true)
            ->whereNotNull('st.delivered_date')
            ->whereBetween('st.delivered_date', [$fromDate, $endDate])
            ->groupBy('ti.item_id')
            ->select([
                'ti.item_id',
                DB::raw("SUM(ABS({$pieceQty})) as movement_quantity"),
            ])
            ->get();
    }

    private function stockDisposalMovementRows(int $clientId, int $warehouseId, array $itemIds, string $fromDate, string $endDate): Collection
    {
        if (! $this->hasTable('stock_disposals')) {
            return collect();
        }

        $pieceQty = $this->pieceQty();

        return DB::connection('sakemaru')->table('trade_items as ti')
            ->join('trades as t', 't.id', '=', 'ti.trade_id')
            ->join('stock_disposals as sd', 'sd.trade_id', '=', 't.id')
            ->where('t.client_id', $clientId)
            ->whereIn('ti.item_id', $itemIds)
            ->where('sd.warehouse_id', $warehouseId)
            ->where('sd.is_active', true)
            ->where('t.trade_category', 'STOCK_DISPOSAL')
            ->where('t.is_active', true)
            ->where('ti.is_active', true)
            ->whereBetween('sd.disposal_date', [$fromDate, $endDate])
            ->groupBy('ti.item_id')
            ->select([
                'ti.item_id',
                DB::raw("SUM(-ABS({$pieceQty})) as movement_quantity"),
            ])
            ->get();
    }

    private function adjustmentMovementRows(
        string $table,
        string $itemTable,
        string $dateColumn,
        string $quantityColumn,
        int $clientId,
        int $warehouseId,
        array $itemIds,
        string $fromDate,
        string $endDate,
        ?string $quantityExpression = null,
    ): Collection {
        if (! $this->hasTable($table) || ! $this->hasTable($itemTable)) {
            return collect();
        }

        $foreignKey = str($table)->singular()->append('_id')->toString();
        $quantityExpression ??= "COALESCE(ai.applied_stock_quantity_after - ai.applied_stock_quantity_before, ai.{$quantityColumn}, ai.stock_quantity_after - ai.stock_quantity_before, 0)";

        return DB::connection('sakemaru')->table($itemTable.' as ai')
            ->join($table.' as a', 'a.id', '=', 'ai.'.$foreignKey)
            ->join('trade_items as ti', 'ti.id', '=', 'ai.trade_item_id')
            ->where('a.client_id', $clientId)
            ->where('a.warehouse_id', $warehouseId)
            ->whereIn('ti.item_id', $itemIds)
            ->where('a.is_active', true)
            ->whereBetween('a.'.$dateColumn, [$fromDate, $endDate])
            ->groupBy('ti.item_id')
            ->select([
                'ti.item_id',
                DB::raw("SUM({$quantityExpression}) as movement_quantity"),
            ])
            ->get();
    }

    private function inventoryAdjustmentMovementRows(int $clientId, int $warehouseId, array $itemIds, string $fromDate, string $endDate): Collection
    {
        return $this->adjustmentMovementRows(
            table: 'inventory_adjustments',
            itemTable: 'inventory_adjustment_items',
            dateColumn: 'adjustment_date',
            quantityColumn: 'inventory_adjustment_quantity',
            clientId: $clientId,
            warehouseId: $warehouseId,
            itemIds: $itemIds,
            fromDate: $fromDate,
            endDate: $endDate,
            quantityExpression: 'COALESCE(ai.inventory_adjustment_quantity, ai.stock_quantity_after - ai.stock_quantity_before, ai.applied_stock_quantity_after - ai.applied_stock_quantity_before, 0)',
        );
    }

    private function containerPickupMovementRows(int $clientId, int $warehouseId, array $itemIds, string $fromDate, string $endDate): Collection
    {
        if (! $this->hasTable('container_pickups')) {
            return collect();
        }

        $pieceQty = $this->pieceQty();

        return DB::connection('sakemaru')->table('trade_items as ti')
            ->join('trades as t', 't.id', '=', 'ti.trade_id')
            ->join('container_pickups as cp', 'cp.trade_id', '=', 't.id')
            ->where('t.client_id', $clientId)
            ->whereIn('ti.item_id', $itemIds)
            ->where('cp.warehouse_id', $warehouseId)
            ->where('cp.is_active', true)
            ->where('t.trade_category', 'CONTAINER_PICKUP')
            ->where('t.is_active', true)
            ->where('ti.is_active', true)
            ->whereNotNull('cp.delivered_date')
            ->whereBetween('cp.delivered_date', [$fromDate, $endDate])
            ->groupBy('ti.item_id')
            ->select([
                'ti.item_id',
                DB::raw("SUM(CASE WHEN COALESCE(t.is_returned, 0) = 1 OR COALESCE(t.trade_direction, 'NORMAL') = 'RETURN' THEN -ABS({$pieceQty}) ELSE ABS({$pieceQty}) END) as movement_quantity"),
            ])
            ->get();
    }

    private function containerReturnMovementRows(int $clientId, int $warehouseId, array $itemIds, string $fromDate, string $endDate): Collection
    {
        if (! $this->hasTable('container_returns')) {
            return collect();
        }

        $pieceQty = $this->pieceQty();

        return DB::connection('sakemaru')->table('trade_items as ti')
            ->join('trades as t', 't.id', '=', 'ti.trade_id')
            ->join('container_returns as cr', 'cr.trade_id', '=', 't.id')
            ->where('t.client_id', $clientId)
            ->whereIn('ti.item_id', $itemIds)
            ->where('cr.warehouse_id', $warehouseId)
            ->where('cr.is_active', true)
            ->where('t.trade_category', 'CONTAINER_RETURN')
            ->where('t.is_active', true)
            ->where('ti.is_active', true)
            ->whereNotNull('cr.delivered_date')
            ->whereBetween('cr.delivered_date', [$fromDate, $endDate])
            ->groupBy('ti.item_id')
            ->select([
                'ti.item_id',
                DB::raw("SUM(CASE WHEN COALESCE(t.is_returned, 0) = 1 OR COALESCE(t.trade_direction, 'NORMAL') = 'RETURN' THEN ABS({$pieceQty}) ELSE -ABS({$pieceQty}) END) as movement_quantity"),
            ])
            ->get();
    }

    private function pieceQty(string $alias = 'ti'): string
    {
        return "COALESCE(NULLIF({$alias}.total_piece_quantity, 0),"
            ." CASE {$alias}.quantity_type"
            ." WHEN 'CASE' THEN {$alias}.quantity * COALESCE(NULLIF({$alias}.capacity_case, 0), 1)"
            ." WHEN 'CARTON' THEN {$alias}.quantity * COALESCE(NULLIF({$alias}.capacity_carton, 0), 1)"
            ." ELSE {$alias}.quantity END)";
    }

    private function assertMovementColumnsExist(): void
    {
        if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_counts', 'stock_movement_from_at')
            || ! Schema::connection('sakemaru')->hasColumn('wms_inventory_counts', 'stock_movement_calculated_at')
            || ! Schema::connection('sakemaru')->hasColumn('wms_inventory_count_items', 'post_count_movement_quantity')
        ) {
            throw new \RuntimeException('受払計算用のDB列が未作成です。マイグレーションを実行してください。');
        }
    }

    private function hasTable(string $table): bool
    {
        return Schema::connection('sakemaru')->hasTable($table);
    }
}
