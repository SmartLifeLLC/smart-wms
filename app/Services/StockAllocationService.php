<?php

namespace App\Services;

use App\Support\DbMutex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Optimized stock allocation service using GET_LOCK
 *
 * Implements specification: 02_picking_3.md
 * - FEFO (First Expiry, First Out) for expiration-managed items
 * - FIFO (First In, First Out) for non-expiration items
 * - Location quantity type restriction is ignored for allocation
 * - Stock ceilings are checked in pieces, while reservation quantities keep the order unit
 * - Batch processing (50 rows/batch, max 2 pages)
 */
class StockAllocationService
{
    protected const BATCH_SIZE = 50;

    protected const MAX_PAGES = 2;

    protected const LOCK_TIMEOUT = 1; // seconds

    /**
     * Allocate stock for a specific item in a wave
     *
     * @param  int  $needQty  Required quantity in the order unit
     * @param  string  $quantityType  Order quantity type (CASE|PIECE|CARTON)
     * @param  int  $sourceId  Source record ID (earning_id or trade_item_id)
     * @param  string  $sourceType  Source type (EARNING|TRADE_ITEM)
     * @param  int|null  $buyerId  Buyer ID for lot restriction check (null = no restriction)
     * @return array ['allocated' => int, 'shortage' => int, 'elapsed_ms' => float, 'race_count' => int]
     */
    public function allocateForItem(
        int $waveId,
        int $warehouseId,
        int $itemId,
        int $needQty,
        string $quantityType,
        int $sourceId,
        int $sourceLineId,
        string $sourceType = 'EARNING',
        ?int $buyerId = null
    ): array {
        $startTime = microtime(true);
        $lockKey = "alloc:{$warehouseId}:{$itemId}";

        // Acquire named lock
        if (! DbMutex::acquire($lockKey, self::LOCK_TIMEOUT, 'sakemaru')) {
            Log::warning('Stock allocation lock timeout', [
                'warehouse_id' => $warehouseId,
                'item_id' => $itemId,
                'wave_id' => $waveId,
            ]);

            return [
                'allocated' => 0,
                'shortage' => $needQty,
                'elapsed_ms' => round((microtime(true) - $startTime) * 1000, 2),
                'race_count' => 0,
                'lock_failed' => true,
            ];
        }

        try {
            return $this->doAllocate(
                $waveId,
                $warehouseId,
                $itemId,
                $needQty,
                $quantityType,
                $sourceId,
                $sourceLineId,
                $sourceType,
                $startTime,
                $buyerId
            );
        } finally {
            DbMutex::release($lockKey, 'sakemaru');
        }
    }

    /**
     * Main allocation logic (protected by GET_LOCK)
     */
    protected function doAllocate(
        int $waveId,
        int $warehouseId,
        int $itemId,
        int $needQty,
        string $quantityType,
        int $sourceId,
        int $sourceLineId,
        string $sourceType,
        float $startTime,
        ?int $buyerId = null
    ): array {
        $existing = DB::connection('sakemaru')
            ->table('wms_reservations')
            ->where('wave_id', $waveId)
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('source_line_id', $sourceLineId)
            ->whereIn('status', ['RESERVED', 'PARTIAL', 'SHORTAGE'])
            ->get(['qty_each', 'shortage_qty']);

        if ($existing->isNotEmpty()) {
            $allocated = (int) $existing->sum('qty_each');
            $shortage = (int) $existing->sum('shortage_qty');

            return [
                'allocated' => $allocated,
                'shortage' => $shortage,
                'elapsed_ms' => round((microtime(true) - $startTime) * 1000, 2),
                'race_count' => 0,
                'lock_failed' => false,
            ];
        }

        $totalAllocated = 0;
        $raceCount = 0;
        $reservations = [];

        // Get item info for expiration management
        $item = DB::connection('sakemaru')
            ->table('items')
            ->where('id', $itemId)
            ->first(['uses_expiration_date', 'capacity_case', 'capacity_carton']);

        $usesExpiration = (bool) ($item?->uses_expiration_date ?? false);
        $unitSize = $this->unitSizeFor($quantityType, $item);

        // Process in batches (max 2 pages)
        for ($page = 0; $page < self::MAX_PAGES && $totalAllocated < $needQty; $page++) {
            $offset = $page * self::BATCH_SIZE;

            // Get candidate stocks
            $candidates = $this->getCandidateStocks(
                $warehouseId,
                $itemId,
                $usesExpiration,
                self::BATCH_SIZE,
                $offset,
                $buyerId,
                $sourceType,
                $sourceId,
                $sourceLineId
            );

            if ($candidates->isEmpty()) {
                break;
            }

            // Try to allocate from candidates
            foreach ($candidates as $stock) {
                if ($totalAllocated >= $needQty) {
                    break;
                }

                // available_quantity is in pieces. Convert to the order unit so
                // CASE/CARTON reservations cannot consume more pieces than exist.
                $availableUnits = $this->allocatableUnitsFromPieces((int) $stock->available_quantity, $unitSize);
                if ($availableUnits <= 0) {
                    continue;
                }

                $takeQty = min($needQty - $totalAllocated, $availableUnits);

                // Note: real_stocks の数量更新は行わない（Sakemaru側で管理）
                // wms_reservations の作成のみ行う

                $reservationKey = implode(':', [
                    $waveId,
                    $itemId,
                    $stock->real_stock_id,
                    $stock->location_id,
                    $sourceId,
                    $sourceLineId,
                    'RESERVED',
                ]);

                if (isset($reservations[$reservationKey])) {
                    $reservations[$reservationKey]['qty_each'] += $takeQty;
                    $totalAllocated += $takeQty;

                    continue;
                }

                // Success - record reservation
                $reservations[$reservationKey] = [
                    'warehouse_id' => $warehouseId,
                    'location_id' => $stock->location_id,
                    'real_stock_id' => $stock->real_stock_id,
                    'item_id' => $itemId,
                    'expiry_date' => $stock->expiration_date,
                    'received_at' => null,
                    'purchase_id' => $stock->purchase_id,
                    'unit_cost' => $stock->unit_cost,
                    'qty_each' => $takeQty,
                    'qty_type' => $quantityType,
                    'shortage_qty' => 0,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'source_line_id' => $sourceLineId,
                    'wave_id' => $waveId,
                    'status' => 'RESERVED',
                    'created_by' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $totalAllocated += $takeQty;
            }
        }

        // Insert reservations in batch (if any)
        if (! empty($reservations)) {
            DB::connection('sakemaru')
                ->table('wms_reservations')
                ->insertOrIgnore(array_values($reservations));
        }

        // Handle shortage
        $shortageQty = $needQty - $totalAllocated;
        if ($shortageQty > 0) {
            DB::connection('sakemaru')->table('wms_reservations')->insertOrIgnore([
                'warehouse_id' => $warehouseId,
                'location_id' => null,
                'real_stock_id' => null,
                'item_id' => $itemId,
                'expiry_date' => null,
                'received_at' => null,
                'purchase_id' => null,
                'unit_cost' => null,
                'qty_each' => 0,
                'qty_type' => $quantityType,
                'shortage_qty' => $shortageQty,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_line_id' => $sourceLineId,
                'wave_id' => $waveId,
                'status' => $totalAllocated > 0 ? 'PARTIAL' : 'SHORTAGE',
                'created_by' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $elapsed = round((microtime(true) - $startTime) * 1000, 2);

        Log::debug('Stock allocation completed', [
            'warehouse_id' => $warehouseId,
            'item_id' => $itemId,
            'wave_id' => $waveId,
            'need_qty' => $needQty,
            'need_pieces' => $needQty * $unitSize,
            'allocated' => $totalAllocated,
            'allocated_pieces' => $totalAllocated * $unitSize,
            'shortage' => $shortageQty,
            'shortage_pieces' => $shortageQty * $unitSize,
            'elapsed_ms' => $elapsed,
            'race_count' => $raceCount,
        ]);

        return [
            'allocated' => $totalAllocated,
            'shortage' => $shortageQty,
            'elapsed_ms' => $elapsed,
            'race_count' => $raceCount,
            'lock_failed' => false,
        ];
    }

    /**
     * Get candidate stocks for allocation
     * real_stock_lots経由でlocation, expiration_date, price等を取得
     *
     * @param  int|null  $buyerId  Buyer ID for lot restriction check (null = no restriction)
     */
    protected function getCandidateStocks(
        int $warehouseId,
        int $itemId,
        bool $usesExpiration,
        int $limit,
        int $offset,
        ?int $buyerId = null,
        string $sourceType = 'EARNING',
        ?int $sourceId = null,
        ?int $sourceLineId = null
    ): \Illuminate\Support\Collection {
        $lotEarningPiecesExpr = $this->lotEarningPiecesExpression();
        $reservedPiecesExpr = $this->reservedLotEarningsPiecesExpression($lotEarningPiecesExpr);
        $effectiveReservedPiecesExpr = "GREATEST(COALESCE(rsl.reserved_quantity, 0), {$reservedPiecesExpr})";

        $ownReservationPiecesExpr = '0';
        if ($sourceType === 'EARNING' && $sourceId !== null && $sourceLineId !== null) {
            $ownReservationPiecesExpr = sprintf(
                '(SELECT COALESCE(SUM(%s), 0)
                    FROM real_stock_lot_earnings rsle
                    WHERE rsle.real_stock_lot_id = rsl.id
                      AND rsle.earning_id = %d
                      AND rsle.trade_item_id = %d
                      AND rsle.status = "RESERVED")',
                $lotEarningPiecesExpr,
                $sourceId,
                $sourceLineId
            );
        }

        $availableExpr = "GREATEST(LEAST(COALESCE(rsl.current_quantity, 0), COALESCE(rsl.current_quantity, 0) - {$effectiveReservedPiecesExpr} + {$ownReservationPiecesExpr}), 0)";

        $query = DB::connection('sakemaru')
            ->table('real_stocks as rs')
            ->join('real_stock_lots as rsl', function ($join) {
                $join->on('rsl.real_stock_id', '=', 'rs.id')
                    ->where('rsl.status', '=', 'ACTIVE');
            })
            ->join('locations as l', 'l.id', '=', 'rsl.location_id')
            ->where('rs.warehouse_id', $warehouseId)
            ->where('rs.item_id', $itemId)
            ->where('l.is_disabled', false)
            ->whereRaw("{$availableExpr} > 0");

        // Apply buyer restriction filter if buyerId is provided
        if ($buyerId !== null) {
            $query->where(function ($q) use ($buyerId) {
                // ロットに制限がない場合は全得意先に販売可能
                $q->whereNotExists(function ($subQuery) {
                    $subQuery->select(DB::raw(1))
                        ->from('real_stock_lot_buyer_restrictions')
                        ->whereColumn('real_stock_lot_buyer_restrictions.real_stock_lot_id', 'rsl.id');
                })
                // または制限があり、対象得意先が許可されている場合
                    ->orWhereExists(function ($subQuery) use ($buyerId) {
                        $subQuery->select(DB::raw(1))
                            ->from('real_stock_lot_buyer_restrictions')
                            ->whereColumn('real_stock_lot_buyer_restrictions.real_stock_lot_id', 'rsl.id')
                            ->where('real_stock_lot_buyer_restrictions.buyer_id', $buyerId);
                    });
            });
        }

        $query->select([
            'rs.id as real_stock_id',
            'rsl.id as lot_id',
            'rsl.location_id',
            'rsl.purchase_id',
            'rsl.expiration_date',
            'rsl.price as unit_cost',
            DB::raw("{$availableExpr} as available_quantity"),
        ]);

        // Order by FEFO or FIFO
        if ($usesExpiration) {
            $query->orderByRaw('rsl.expiration_date IS NULL') // NULL last
                ->orderBy('rsl.expiration_date', 'asc');
        } else {
            // FIFO: Order by creation date
            $query->orderBy('rsl.created_at', 'asc');
        }

        $query->orderBy('rsl.id', 'asc')
            ->limit($limit)
            ->offset($offset);

        return $query->get();
    }

    protected function unitSizeFor(string $quantityType, ?object $item): int
    {
        return match (strtoupper($quantityType)) {
            'CASE' => max(1, (int) ($item->capacity_case ?? 1)),
            'CARTON' => max(1, (int) ($item->capacity_carton ?? $item->capacity_case ?? 1)),
            default => 1,
        };
    }

    protected function allocatableUnitsFromPieces(int $availablePieces, int $unitSize): int
    {
        return intdiv(max(0, $availablePieces), max(1, $unitSize));
    }

    protected function reservedLotEarningsPiecesExpression(string $lotEarningPiecesExpr): string
    {
        return "(SELECT COALESCE(SUM({$lotEarningPiecesExpr}), 0)
            FROM real_stock_lot_earnings rsle
            WHERE rsle.real_stock_lot_id = rsl.id
              AND rsle.status = \"RESERVED\")";
    }

    protected function lotEarningPiecesExpression(): string
    {
        // real_stock_lot_earnings.quantity is stored as piece quantity, even for CASE/CARTON orders.
        return 'COALESCE(rsle.quantity, 0)';
    }
}
