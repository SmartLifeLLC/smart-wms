<?php

namespace App\Services\InventoryCount;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InventoryCountLedgerBalanceService
{
    public const OPENING_DATE = '2026-05-06';

    private const QUANTITY_SCALE = 1000;

    /**
     * ai-core /stats/item-stock-movements と同じ元データ基準で、倉庫・商品別の受払最終残を返す。
     *
     * @return array<int, float>
     */
    public function balancesByItem(int $clientId, int $warehouseId, string $endDate): array
    {
        $end = CarbonImmutable::parse($endDate)->toDateString();
        if ($end < self::OPENING_DATE) {
            throw new \RuntimeException(self::OPENING_DATE.' より前の日付では理論在庫を更新できません。');
        }

        $balances = $this->openingBalances($clientId, $warehouseId);
        $movementsByItem = [];

        foreach ($this->bulkMovementRows($clientId, $warehouseId, self::OPENING_DATE, $end) as $row) {
            $itemId = (int) $row->item_id;
            $movementsByItem[$itemId][] = [
                'movement_date' => CarbonImmutable::parse($row->movement_date)->toDateString(),
                'sort_order' => (int) $row->sort_order,
                'source_id' => (int) ($row->source_id ?? 0),
                'source_detail_id' => (int) ($row->source_detail_id ?? 0),
                'kind' => 'bulk',
                'net_quantity' => $this->quantityToScaled($row->net_quantity),
            ];
        }

        foreach ($this->inventoryAdjustmentRows($clientId, $warehouseId, self::OPENING_DATE, $end) as $row) {
            $itemId = (int) $row->item_id;
            $movementsByItem[$itemId][] = [
                'movement_date' => CarbonImmutable::parse($row->movement_date)->toDateString(),
                'sort_order' => 90,
                'source_id' => (int) ($row->source_id ?? 0),
                'source_detail_id' => (int) ($row->source_detail_id ?? 0),
                'kind' => 'inventory_adjustment',
                'stock_quantity_before' => $row->stock_quantity_before !== null ? $this->quantityToScaled($row->stock_quantity_before) : null,
                'stock_quantity_after' => $row->stock_quantity_after !== null ? $this->quantityToScaled($row->stock_quantity_after) : null,
                'net_quantity' => $this->quantityToScaled($row->net_quantity ?? 0),
            ];
        }

        foreach ($movementsByItem as $itemId => $movements) {
            usort($movements, fn (array $a, array $b): int => [
                $a['movement_date'],
                $a['sort_order'],
                $a['source_id'],
                $a['source_detail_id'],
            ] <=> [
                $b['movement_date'],
                $b['sort_order'],
                $b['source_id'],
                $b['source_detail_id'],
            ]);

            $balance = (int) ($balances[$itemId] ?? 0);

            foreach ($movements as $movement) {
                if ($movement['kind'] === 'inventory_adjustment') {
                    $stockBefore = $movement['stock_quantity_before'];
                    if ($stockBefore !== null && $stockBefore !== $balance) {
                        $balance = $stockBefore;
                    }

                    if ($movement['stock_quantity_after'] !== null) {
                        $balance = $movement['stock_quantity_after'];
                    } else {
                        $balance += (int) $movement['net_quantity'];
                    }

                    continue;
                }

                $balance += (int) $movement['net_quantity'];
            }

            $balances[$itemId] = $balance;
        }

        $eligibleItemIds = array_flip($this->eligibleItemIds(array_keys($balances), $clientId));

        return collect($balances)
            ->filter(fn (int $quantity, int $itemId): bool => isset($eligibleItemIds[$itemId]))
            ->map(fn (int $quantity): float => $this->scaledToQuantity($quantity))
            ->all();
    }

    /**
     * @param  array<int, int>  $itemIds
     * @return array<int, int>
     */
    public function eligibleItemIds(array $itemIds, int $clientId): array
    {
        $itemIds = array_values(array_unique(array_map('intval', array_filter($itemIds))));
        if ($itemIds === []) {
            return [];
        }

        $itemColumns = $this->tableColumns('items');
        $query = DB::connection('sakemaru')
            ->table('items as i')
            ->whereIn('i.id', $itemIds);

        if (in_array('client_id', $itemColumns, true)) {
            $query->where('i.client_id', $clientId);
        }

        if (in_array('is_managed_stock', $itemColumns, true)) {
            $query->where('i.is_managed_stock', true);
        }

        if (in_array('type', $itemColumns, true)) {
            $query->whereRaw("COALESCE(i.type, '') <> 'CONTAINER'");
        }

        if (Schema::connection('sakemaru')->hasTable('item_sets')
            && in_array('item_set_id', $itemColumns, true)
        ) {
            $query
                ->leftJoin('item_sets as item_set', function ($join) {
                    $join->on('item_set.id', '=', 'i.item_set_id')
                        ->where('item_set.is_active', true);
                })
                ->where(function ($query) {
                    $query
                        ->whereNull('item_set.id')
                        ->orWhere('item_set.set_type', '!=', 'OWNED');
                });
        }

        return $query
            ->pluck('i.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function openingBalances(int $clientId, int $warehouseId): array
    {
        if (! Schema::connection('sakemaru')->hasTable('stats_item_stock_opening_balances')) {
            return [];
        }

        return DB::connection('sakemaru')
            ->table('stats_item_stock_opening_balances')
            ->where('client_id', $clientId)
            ->where('opening_date', self::OPENING_DATE)
            ->where('warehouse_id', $warehouseId)
            ->groupBy('item_id')
            ->selectRaw('item_id, SUM(opening_quantity) AS quantity')
            ->pluck('quantity', 'item_id')
            ->map(fn ($quantity): int => $this->quantityToScaled($quantity))
            ->all();
    }

    private function bulkMovementRows(int $clientId, int $warehouseId, string $fromDate, string $endDate): Collection
    {
        return collect()
            ->merge($this->purchaseRows($clientId, $warehouseId, $fromDate, $endDate))
            ->merge($this->earningRows($clientId, $warehouseId, $fromDate, $endDate))
            ->merge($this->ownSetComponentEarningRows($clientId, $warehouseId, $fromDate, $endDate))
            ->merge($this->retailRows($warehouseId, $fromDate, $endDate))
            ->merge($this->retailOwnSetComponentRows($warehouseId, $fromDate, $endDate))
            ->merge($this->stockTransferOutRows($clientId, $warehouseId, $fromDate, $endDate))
            ->merge($this->stockTransferInRows($clientId, $warehouseId, $fromDate, $endDate))
            ->merge($this->stockTransferOutSetComponentRows($clientId, $warehouseId, $fromDate, $endDate))
            ->merge($this->stockTransferInSetComponentRows($clientId, $warehouseId, $fromDate, $endDate))
            ->merge($this->stockDisposalRows($clientId, $warehouseId, $fromDate, $endDate))
            ->merge($this->stockAdjustmentRows($clientId, $warehouseId, $fromDate, $endDate))
            ->merge($this->containerPickupRows($clientId, $warehouseId, $fromDate, $endDate))
            ->merge($this->containerReturnRows($clientId, $warehouseId, $fromDate, $endDate));
    }

    private function purchaseRows(int $clientId, int $warehouseId, string $fromDate, string $endDate): Collection
    {
        $pieceQty = $this->pieceQty();
        $returnCondition = "COALESCE(t.is_returned, 0) = 1 OR COALESCE(t.trade_direction, 'NORMAL') = 'RETURN' OR ({$pieceQty}) < 0";

        return DB::connection('sakemaru')
            ->table('trade_items as ti')
            ->join('trades as t', 't.id', '=', 'ti.trade_id')
            ->join('purchases as p', 'p.trade_id', '=', 't.id')
            ->where('t.client_id', $clientId)
            ->where('p.warehouse_id', $warehouseId)
            ->where('t.trade_category', 'PURCHASE')
            ->where('t.is_active', true)
            ->where('t.is_latest', true)
            ->where('p.is_active', true)
            ->where('ti.is_active', true)
            ->whereBetween('t.process_date', [$fromDate, $endDate])
            ->groupBy('ti.item_id', 't.process_date')
            ->selectRaw("ti.item_id, t.process_date AS movement_date, 20 AS sort_order, 0 AS source_id, 0 AS source_detail_id, SUM(CASE WHEN {$returnCondition} THEN -ABS({$pieceQty}) ELSE ABS({$pieceQty}) END) AS net_quantity")
            ->get();
    }

    private function earningRows(int $clientId, int $warehouseId, string $fromDate, string $endDate): Collection
    {
        $pieceQty = $this->pieceQty();
        $movementDate = 'COALESCE(e.delivered_date, t.process_date)';
        $returnCondition = "COALESCE(t.is_returned, 0) = 1 OR COALESCE(t.trade_direction, 'NORMAL') = 'RETURN' OR ({$pieceQty}) < 0";

        return DB::connection('sakemaru')
            ->table('trade_items as ti')
            ->join('trades as t', 't.id', '=', 'ti.trade_id')
            ->join('earnings as e', 'e.trade_id', '=', 't.id')
            ->where('t.client_id', $clientId)
            ->where('e.warehouse_id', $warehouseId)
            ->where('t.trade_category', 'EARNING')
            ->where('t.is_active', true)
            ->where('t.is_latest', true)
            ->where('e.is_active', true)
            ->where('ti.is_active', true)
            ->whereBetween(DB::raw($movementDate), [$fromDate, $endDate])
            ->groupBy('ti.item_id', DB::raw($movementDate))
            ->selectRaw("ti.item_id, {$movementDate} AS movement_date, 30 AS sort_order, 0 AS source_id, 0 AS source_detail_id, SUM(CASE WHEN {$returnCondition} THEN ABS({$pieceQty}) ELSE -ABS({$pieceQty}) END) AS net_quantity")
            ->get();
    }

    private function ownSetComponentEarningRows(int $clientId, int $warehouseId, string $fromDate, string $endDate): Collection
    {
        if (! Schema::connection('sakemaru')->hasTable('item_sets')) {
            return collect();
        }

        return $this->ownSetComponentEarningRowsFromLotEarnings($clientId, $warehouseId, $fromDate, $endDate)
            ->merge($this->ownSetComponentEarningRowsFromRecipeFallback($clientId, $warehouseId, $fromDate, $endDate))
            ->values();
    }

    private function ownSetComponentEarningRowsFromLotEarnings(int $clientId, int $warehouseId, string $fromDate, string $endDate): Collection
    {
        if (! Schema::connection('sakemaru')->hasTable('real_stock_lot_earnings')
            || ! Schema::connection('sakemaru')->hasTable('real_stock_lots')
            || ! Schema::connection('sakemaru')->hasTable('real_stocks')
            || ! Schema::connection('sakemaru')->hasTable('item_sets')
        ) {
            return collect();
        }

        $movementDate = 'COALESCE(e.delivered_date, t.process_date)';
        $returnCondition = "COALESCE(t.is_returned, 0) = 1 OR COALESCE(t.trade_direction, 'NORMAL') = 'RETURN' OR (".$this->pieceQty().') < 0';

        $rows = DB::connection('sakemaru')
            ->table('real_stock_lot_earnings as rle')
            ->join('real_stock_lots as lot', 'lot.id', '=', 'rle.real_stock_lot_id')
            ->join('real_stocks as rs', 'rs.id', '=', 'lot.real_stock_id')
            ->join('trade_items as ti', 'ti.id', '=', 'rle.trade_item_id')
            ->join('trades as t', 't.id', '=', 'ti.trade_id')
            ->join('earnings as e', 'e.id', '=', 'rle.earning_id')
            ->join('items as set_item', 'set_item.id', '=', 'ti.item_id')
            ->join('item_sets as item_set', 'item_set.id', '=', 'set_item.item_set_id')
            ->where('t.client_id', $clientId)
            ->where('rs.client_id', $clientId)
            ->where('rs.warehouse_id', $warehouseId)
            ->where('t.trade_category', 'EARNING')
            ->where('t.is_active', true)
            ->where('t.is_latest', true)
            ->where('e.is_active', true)
            ->where('ti.is_active', true)
            ->where('item_set.set_type', 'OWNED')
            ->whereIn('rle.status', ['RESERVED', 'DELIVERED'])
            ->whereBetween(DB::raw($movementDate), [$fromDate, $endDate])
            ->groupBy('rs.item_id', DB::raw($movementDate), 't.id', 'ti.id', DB::raw("CASE WHEN {$returnCondition} THEN 1 ELSE 0 END"))
            ->selectRaw("rs.item_id, {$movementDate} AS movement_date, 31 AS sort_order, t.id AS source_id, ti.id AS source_detail_id, SUM(rle.quantity) AS quantity, CASE WHEN {$returnCondition} THEN 1 ELSE 0 END AS is_returned")
            ->get();

        return $this->formatOwnSetComponentEarningRows($rows);
    }

    private function ownSetComponentEarningRowsFromRecipeFallback(int $clientId, int $warehouseId, string $fromDate, string $endDate): Collection
    {
        if (! Schema::connection('sakemaru')->hasTable('item_set_details')) {
            return collect();
        }

        $pieceQty = $this->pieceQty();
        $movementDate = 'COALESCE(e.delivered_date, t.process_date)';
        $factor = 'CASE WHEN COALESCE(d.quantity_case, 0) <> 0 THEN d.quantity_case * COALESCE(NULLIF(comp.capacity_case, 0), 1) ELSE COALESCE(d.quantity_piece, 0) END';
        $componentQty = "({$pieceQty}) * ({$factor})";
        $returnCondition = "COALESCE(t.is_returned, 0) = 1 OR COALESCE(t.trade_direction, 'NORMAL') = 'RETURN' OR ({$pieceQty}) < 0";
        $hasLotEarningTables = Schema::connection('sakemaru')->hasTable('real_stock_lot_earnings')
            && Schema::connection('sakemaru')->hasTable('real_stock_lots')
            && Schema::connection('sakemaru')->hasTable('real_stocks');

        $rows = DB::connection('sakemaru')
            ->table('trade_items as ti')
            ->join('trades as t', 't.id', '=', 'ti.trade_id')
            ->join('earnings as e', 'e.trade_id', '=', 't.id')
            ->join('items as set_item', 'set_item.id', '=', 'ti.item_id')
            ->join('item_sets as item_set', function ($join) {
                $join->on('item_set.id', '=', 'set_item.item_set_id')
                    ->where('item_set.set_type', 'OWNED');
            })
            ->join('item_set_details as d', 'd.item_set_id', '=', 'item_set.id')
            ->join('items as comp', 'comp.id', '=', 'd.item_id')
            ->where('t.client_id', $clientId)
            ->where('e.warehouse_id', $warehouseId)
            ->where('t.trade_category', 'EARNING')
            ->where('t.is_active', true)
            ->where('t.is_latest', true)
            ->where('e.is_active', true)
            ->where('ti.is_active', true)
            ->whereBetween(DB::raw($movementDate), [$fromDate, $endDate])
            ->when($hasLotEarningTables, fn ($query) => $query->whereNotExists(function ($query) {
                $query
                    ->select(DB::raw(1))
                    ->from('real_stock_lot_earnings as ex_rle')
                    ->join('real_stock_lots as ex_lot', 'ex_lot.id', '=', 'ex_rle.real_stock_lot_id')
                    ->join('real_stocks as ex_rs', 'ex_rs.id', '=', 'ex_lot.real_stock_id')
                    ->whereColumn('ex_rle.earning_id', 'e.id')
                    ->whereColumn('ex_rle.trade_item_id', 'ti.id')
                    ->whereColumn('ex_rs.item_id', '<>', 'ti.item_id')
                    ->whereIn('ex_rle.status', ['RESERVED', 'DELIVERED']);
            }))
            ->selectRaw("d.item_id, {$movementDate} AS movement_date, 31 AS sort_order, t.id AS source_id, ti.id AS source_detail_id, ({$componentQty}) AS quantity, CASE WHEN {$returnCondition} THEN 1 ELSE 0 END AS is_returned")
            ->get();

        return $this->formatOwnSetComponentEarningRows($rows);
    }

    private function formatOwnSetComponentEarningRows(Collection $rows): Collection
    {
        return $rows
            ->groupBy(fn ($row): string => implode('|', [
                $row->item_id,
                $row->movement_date,
                $row->source_id,
                $row->source_detail_id,
                $row->is_returned,
            ]))
            ->map(function (Collection $group) {
                $row = $group->first();
                $quantity = abs((float) $group->sum('quantity'));
                if (abs($quantity) <= 0.0001) {
                    return null;
                }

                return (object) [
                    'item_id' => $row->item_id,
                    'movement_date' => $row->movement_date,
                    'sort_order' => $row->sort_order,
                    'source_id' => $row->source_id,
                    'source_detail_id' => $row->source_detail_id,
                    'net_quantity' => (bool) $row->is_returned ? $quantity : -$quantity,
                ];
            })
            ->filter()
            ->values();
    }

    private function retailRows(int $warehouseId, string $fromDate, string $endDate): Collection
    {
        if (! Schema::connection('sakemaru')->hasTable('ret_pos_stock_applications')) {
            return collect();
        }

        return DB::connection('sakemaru')
            ->table('ret_pos_stock_applications')
            ->where('warehouse_id', $warehouseId)
            ->whereBetween('business_date', [$fromDate, $endDate])
            ->groupBy('item_id', 'business_date')
            ->selectRaw('item_id, business_date AS movement_date, 40 AS sort_order, 0 AS source_id, 0 AS source_detail_id, SUM(-1 * quantity) AS net_quantity')
            ->get();
    }

    private function retailOwnSetComponentRows(int $warehouseId, string $fromDate, string $endDate): Collection
    {
        if (! Schema::connection('sakemaru')->hasTable('ret_pos_stock_application_set_items')
            || ! Schema::connection('sakemaru')->hasTable('ret_pos_stock_applications')
        ) {
            return collect();
        }

        return DB::connection('sakemaru')
            ->table('ret_pos_stock_application_set_items as c')
            ->join('ret_pos_stock_applications as app', 'app.id', '=', 'c.ret_pos_stock_application_id')
            ->where('c.warehouse_id', $warehouseId)
            ->where('c.stock_applied', true)
            ->whereBetween('app.business_date', [$fromDate, $endDate])
            ->groupBy('c.item_id', 'app.business_date')
            ->selectRaw('c.item_id, app.business_date AS movement_date, 41 AS sort_order, 0 AS source_id, 0 AS source_detail_id, SUM(-1 * c.quantity) AS net_quantity')
            ->get();
    }

    private function stockTransferOutRows(int $clientId, int $warehouseId, string $fromDate, string $endDate): Collection
    {
        $pieceQty = $this->pieceQty();
        $movementDate = 'COALESCE(st.picking_date, t.process_date)';

        return DB::connection('sakemaru')
            ->table('trade_items as ti')
            ->join('trades as t', 't.id', '=', 'ti.trade_id')
            ->join('stock_transfers as st', 'st.trade_id', '=', 't.id')
            ->where('t.client_id', $clientId)
            ->where('st.from_warehouse_id', $warehouseId)
            ->where('t.trade_category', 'STOCK_TRANSFER')
            ->where('t.is_active', true)
            ->where('t.is_latest', true)
            ->where('st.is_active', true)
            ->where('ti.is_active', true)
            ->when(Schema::connection('sakemaru')->hasTable('stock_transfer_lot_allocations'), fn ($query) => $query->whereNotExists(function ($query) {
                $query
                    ->select(DB::raw(1))
                    ->from('stock_transfer_lot_allocations as exa')
                    ->whereColumn('exa.stock_transfer_id', 'st.id')
                    ->whereColumn('exa.parent_item_id', 'ti.item_id')
                    ->where('exa.is_set_component', true);
            }))
            ->whereBetween(DB::raw($movementDate), [$fromDate, $endDate])
            ->groupBy('ti.item_id', DB::raw($movementDate))
            ->selectRaw("ti.item_id, {$movementDate} AS movement_date, 50 AS sort_order, 0 AS source_id, 0 AS source_detail_id, SUM(-1 * ({$pieceQty})) AS net_quantity")
            ->get();
    }

    private function stockTransferInRows(int $clientId, int $warehouseId, string $fromDate, string $endDate): Collection
    {
        $pieceQty = $this->pieceQty();
        $movementDate = 'st.delivered_date';

        return DB::connection('sakemaru')
            ->table('trade_items as ti')
            ->join('trades as t', 't.id', '=', 'ti.trade_id')
            ->join('stock_transfers as st', 'st.trade_id', '=', 't.id')
            ->where('t.client_id', $clientId)
            ->where('st.to_warehouse_id', $warehouseId)
            ->where('t.trade_category', 'STOCK_TRANSFER')
            ->where('t.is_active', true)
            ->where('t.is_latest', true)
            ->where('st.is_active', true)
            ->where('st.is_delivered', true)
            ->where('ti.is_active', true)
            ->whereNotNull('st.delivered_date')
            ->when(Schema::connection('sakemaru')->hasTable('stock_transfer_lot_allocations'), fn ($query) => $query->whereNotExists(function ($query) {
                $query
                    ->select(DB::raw(1))
                    ->from('stock_transfer_lot_allocations as exa')
                    ->whereColumn('exa.stock_transfer_id', 'st.id')
                    ->whereColumn('exa.parent_item_id', 'ti.item_id')
                    ->where('exa.is_set_component', true);
            }))
            ->whereBetween(DB::raw($movementDate), [$fromDate, $endDate])
            ->groupBy('ti.item_id', DB::raw($movementDate))
            ->selectRaw("ti.item_id, {$movementDate} AS movement_date, 60 AS sort_order, 0 AS source_id, 0 AS source_detail_id, SUM({$pieceQty}) AS net_quantity")
            ->get();
    }

    private function stockTransferOutSetComponentRows(int $clientId, int $warehouseId, string $fromDate, string $endDate): Collection
    {
        if (! Schema::connection('sakemaru')->hasTable('stock_transfer_lot_allocations')) {
            return collect();
        }

        $movementDate = 'COALESCE(st.picking_date, t.process_date)';

        return DB::connection('sakemaru')
            ->table('stock_transfer_lot_allocations as a')
            ->join('real_stock_lots as lot', 'lot.id', '=', 'a.from_real_stock_lot_id')
            ->join('real_stocks as rs', 'rs.id', '=', 'lot.real_stock_id')
            ->join('stock_transfers as st', 'st.id', '=', 'a.stock_transfer_id')
            ->join('trades as t', 't.id', '=', 'st.trade_id')
            ->join('trade_items as ti', 'ti.id', '=', 'a.trade_item_id')
            ->where('a.is_set_component', true)
            ->where('st.from_warehouse_id', $warehouseId)
            ->where('t.client_id', $clientId)
            ->where('t.trade_category', 'STOCK_TRANSFER')
            ->where('t.is_active', true)
            ->where('t.is_latest', true)
            ->where('st.is_active', true)
            ->where('ti.is_active', true)
            ->whereBetween(DB::raw($movementDate), [$fromDate, $endDate])
            ->groupBy('rs.item_id', DB::raw($movementDate))
            ->selectRaw("rs.item_id, {$movementDate} AS movement_date, 51 AS sort_order, 0 AS source_id, 0 AS source_detail_id, SUM(-1 * a.quantity) AS net_quantity")
            ->get();
    }

    private function stockTransferInSetComponentRows(int $clientId, int $warehouseId, string $fromDate, string $endDate): Collection
    {
        if (! Schema::connection('sakemaru')->hasTable('stock_transfer_lot_allocations')) {
            return collect();
        }

        $movementDate = 'st.delivered_date';

        return DB::connection('sakemaru')
            ->table('stock_transfer_lot_allocations as a')
            ->join('real_stock_lots as lot', 'lot.id', '=', 'a.from_real_stock_lot_id')
            ->join('real_stocks as rs', 'rs.id', '=', 'lot.real_stock_id')
            ->join('stock_transfers as st', 'st.id', '=', 'a.stock_transfer_id')
            ->join('trades as t', 't.id', '=', 'st.trade_id')
            ->join('trade_items as ti', 'ti.id', '=', 'a.trade_item_id')
            ->where('a.is_set_component', true)
            ->where('st.to_warehouse_id', $warehouseId)
            ->where('t.client_id', $clientId)
            ->where('t.trade_category', 'STOCK_TRANSFER')
            ->where('t.is_active', true)
            ->where('t.is_latest', true)
            ->where('st.is_active', true)
            ->where('st.is_delivered', true)
            ->where('ti.is_active', true)
            ->whereNotNull('st.delivered_date')
            ->whereBetween(DB::raw($movementDate), [$fromDate, $endDate])
            ->groupBy('rs.item_id', DB::raw($movementDate))
            ->selectRaw("rs.item_id, {$movementDate} AS movement_date, 61 AS sort_order, 0 AS source_id, 0 AS source_detail_id, SUM(a.quantity) AS net_quantity")
            ->get();
    }

    private function stockDisposalRows(int $clientId, int $warehouseId, string $fromDate, string $endDate): Collection
    {
        if (! Schema::connection('sakemaru')->hasTable('stock_disposals')) {
            return collect();
        }

        $pieceQty = $this->pieceQty();

        return DB::connection('sakemaru')
            ->table('trade_items as ti')
            ->join('trades as t', 't.id', '=', 'ti.trade_id')
            ->join('stock_disposals as sd', 'sd.trade_id', '=', 't.id')
            ->where('t.client_id', $clientId)
            ->where('sd.warehouse_id', $warehouseId)
            ->where('t.trade_category', 'STOCK_DISPOSAL')
            ->where('t.is_active', true)
            ->where('t.is_latest', true)
            ->where('sd.is_active', true)
            ->where('ti.is_active', true)
            ->whereBetween('sd.disposal_date', [$fromDate, $endDate])
            ->groupBy('ti.item_id', 'sd.disposal_date')
            ->selectRaw("ti.item_id, sd.disposal_date AS movement_date, 70 AS sort_order, 0 AS source_id, 0 AS source_detail_id, SUM(-1 * {$pieceQty}) AS net_quantity")
            ->get();
    }

    private function stockAdjustmentRows(int $clientId, int $warehouseId, string $fromDate, string $endDate): Collection
    {
        if (! Schema::connection('sakemaru')->hasTable('stock_adjustments')
            || ! Schema::connection('sakemaru')->hasTable('stock_adjustment_items')
        ) {
            return collect();
        }

        return DB::connection('sakemaru')
            ->table('stock_adjustment_items as ai')
            ->join('stock_adjustments as a', 'a.id', '=', 'ai.stock_adjustment_id')
            ->join('trade_items as ti', 'ti.id', '=', 'ai.trade_item_id')
            ->join('trades as t', 't.id', '=', 'a.trade_id')
            ->where('a.client_id', $clientId)
            ->where('a.warehouse_id', $warehouseId)
            ->where('a.is_active', true)
            ->where('t.is_active', true)
            ->where('t.is_latest', true)
            ->where('ti.is_active', true)
            ->whereBetween('a.adjustment_date', [$fromDate, $endDate])
            ->groupBy('ti.item_id', 'a.adjustment_date')
            ->selectRaw('ti.item_id, a.adjustment_date AS movement_date, 80 AS sort_order, 0 AS source_id, 0 AS source_detail_id, SUM(COALESCE(ai.applied_stock_quantity_after - ai.applied_stock_quantity_before, ai.stock_adjustment_quantity, ai.stock_quantity_after - ai.stock_quantity_before, 0)) AS net_quantity')
            ->get();
    }

    private function inventoryAdjustmentRows(int $clientId, int $warehouseId, string $fromDate, string $endDate): Collection
    {
        if (! Schema::connection('sakemaru')->hasTable('inventory_adjustments')
            || ! Schema::connection('sakemaru')->hasTable('inventory_adjustment_items')
        ) {
            return collect();
        }

        return DB::connection('sakemaru')
            ->table('inventory_adjustment_items as ai')
            ->join('inventory_adjustments as a', 'a.id', '=', 'ai.inventory_adjustment_id')
            ->join('trade_items as ti', 'ti.id', '=', 'ai.trade_item_id')
            ->join('trades as t', 't.id', '=', 'a.trade_id')
            ->where('a.client_id', $clientId)
            ->where('a.warehouse_id', $warehouseId)
            ->where('a.is_active', true)
            ->where('t.is_active', true)
            ->where('t.is_latest', true)
            ->where('ti.is_active', true)
            ->whereBetween('a.adjustment_date', [$fromDate, $endDate])
            ->select([
                'ti.item_id',
                'a.adjustment_date as movement_date',
                'a.id as source_id',
                'ai.id as source_detail_id',
                'ai.stock_quantity_before',
                DB::raw('COALESCE(ai.stock_quantity_after, ai.applied_stock_quantity_after) AS stock_quantity_after'),
                DB::raw('COALESCE(ai.inventory_adjustment_quantity, ai.stock_quantity_after - ai.stock_quantity_before, ai.applied_stock_quantity_after - ai.applied_stock_quantity_before, 0) AS net_quantity'),
            ])
            ->get();
    }

    private function containerPickupRows(int $clientId, int $warehouseId, string $fromDate, string $endDate): Collection
    {
        if (! Schema::connection('sakemaru')->hasTable('container_pickups')) {
            return collect();
        }

        $pieceQty = $this->pieceQty();
        $returnCondition = "COALESCE(t.is_returned, 0) = 1 OR COALESCE(t.trade_direction, 'NORMAL') = 'RETURN' OR ({$pieceQty}) < 0";

        return DB::connection('sakemaru')
            ->table('trade_items as ti')
            ->join('trades as t', 't.id', '=', 'ti.trade_id')
            ->join('container_pickups as cp', 'cp.trade_id', '=', 't.id')
            ->where('t.client_id', $clientId)
            ->where('cp.warehouse_id', $warehouseId)
            ->where('t.trade_category', 'CONTAINER_PICKUP')
            ->where('t.is_active', true)
            ->where('t.is_latest', true)
            ->where('cp.is_active', true)
            ->where('ti.is_active', true)
            ->whereNotNull('cp.delivered_date')
            ->whereBetween('cp.delivered_date', [$fromDate, $endDate])
            ->groupBy('ti.item_id', 'cp.delivered_date')
            ->selectRaw("ti.item_id, cp.delivered_date AS movement_date, 100 AS sort_order, 0 AS source_id, 0 AS source_detail_id, SUM(CASE WHEN {$returnCondition} THEN -ABS({$pieceQty}) ELSE ABS({$pieceQty}) END) AS net_quantity")
            ->get();
    }

    private function containerReturnRows(int $clientId, int $warehouseId, string $fromDate, string $endDate): Collection
    {
        if (! Schema::connection('sakemaru')->hasTable('container_returns')) {
            return collect();
        }

        $pieceQty = $this->pieceQty();
        $returnCondition = "COALESCE(t.is_returned, 0) = 1 OR COALESCE(t.trade_direction, 'NORMAL') = 'RETURN' OR ({$pieceQty}) < 0";

        return DB::connection('sakemaru')
            ->table('trade_items as ti')
            ->join('trades as t', 't.id', '=', 'ti.trade_id')
            ->join('container_returns as cr', 'cr.trade_id', '=', 't.id')
            ->where('t.client_id', $clientId)
            ->where('cr.warehouse_id', $warehouseId)
            ->where('t.trade_category', 'CONTAINER_RETURN')
            ->where('t.is_active', true)
            ->where('t.is_latest', true)
            ->where('cr.is_active', true)
            ->where('ti.is_active', true)
            ->whereNotNull('cr.delivered_date')
            ->whereBetween('cr.delivered_date', [$fromDate, $endDate])
            ->groupBy('ti.item_id', 'cr.delivered_date')
            ->selectRaw("ti.item_id, cr.delivered_date AS movement_date, 110 AS sort_order, 0 AS source_id, 0 AS source_detail_id, SUM(CASE WHEN {$returnCondition} THEN ABS({$pieceQty}) ELSE -ABS({$pieceQty}) END) AS net_quantity")
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

    private function quantityToScaled(mixed $quantity): int
    {
        if ($quantity === null || $quantity === '') {
            return 0;
        }

        $value = trim((string) $quantity);
        if (! preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', $value, $matches)) {
            return (int) round((float) $quantity * self::QUANTITY_SCALE);
        }

        $fraction = str_pad($matches[3] ?? '', 4, '0');
        $scaled = ((int) $matches[2]) * self::QUANTITY_SCALE;
        $scaled += (int) substr($fraction, 0, 3);

        if ((int) $fraction[3] >= 5) {
            $scaled++;
        }

        return ($matches[1] ?? '') === '-' ? -$scaled : $scaled;
    }

    private function scaledToQuantity(int $quantity): float
    {
        return round($quantity / self::QUANTITY_SCALE, 3);
    }

    /**
     * @return array<int, string>
     */
    private function tableColumns(string $table): array
    {
        if (! Schema::connection('sakemaru')->hasTable($table)) {
            return [];
        }

        return Schema::connection('sakemaru')->getColumnListing($table);
    }
}
