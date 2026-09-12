<?php

namespace App\Services\LotAdjustment;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * D. STLA 参照修正（§4.1 repoint）
 *
 * stock_transfer_lot_allocations.from_real_stock_lot_id が負/DEPLETED LOT を指し、
 * 同一 real_stock に有効な正ACTIVE LOT が存在する場合、ポインタを正LOTへ付け替える。
 * 正LOTが同一棚番内に複数ある場合は、先に最新LOTへ数量を合算してから付け替える。
 *
 * - 棚番は変えない。同一棚番内の正LOT集約のみ行う。
 * - ai-core のテーブル（stla）への書き込みであるため、禁止条件（§7.2）を必須でチェックする。
 *
 * 禁止条件（該当時は SKIP・適用しない）:
 * - wms_reservations / wms_picking_item_results / wms_shortages が当該 transfer/明細に存在
 * - stock_transfers.is_delivered / is_confirmed = true、picking_status が着手以降
 * - 正ACTIVE候補が複数棚番に分かれている
 */
class StlaReferenceRepairService
{
    private string $conn = 'sakemaru';

    /**
     * 倉庫スコープの repoint 候補を検出（read-only）。各候補に eligible 判定を付与。
     *
     * @return Collection<int, object>
     */
    public function detectCandidates(int $warehouseId): Collection
    {
        // from倉庫 = warehouseId で、from LOT が 負 or DEPLETED の stla
        $rows = DB::connection($this->conn)
            ->table('stock_transfer_lot_allocations as stla')
            ->join('real_stock_lots as from_lot', 'from_lot.id', '=', 'stla.from_real_stock_lot_id')
            ->join('real_stocks as rs', 'rs.id', '=', 'from_lot.real_stock_id')
            ->join('stock_transfers as st', 'st.id', '=', 'stla.stock_transfer_id')
            ->where('rs.warehouse_id', $warehouseId)
            // 未着手の transfer のみ（着手・出荷確定済みは対象外。§7.2）
            ->where('st.is_delivered', 0)
            ->where('st.is_confirmed', 0)
            ->where('st.picking_status', 'BEFORE')
            ->where(function ($q) {
                $q->where('from_lot.current_quantity', '<', 0)
                    ->orWhere('from_lot.status', 'DEPLETED');
            })
            ->select([
                'stla.id as stla_id',
                'stla.stock_transfer_id',
                'stla.trade_item_id',
                'stla.quantity',
                'stla.from_real_stock_lot_id as old_lot_id',
                'from_lot.real_stock_id',
                'rs.item_id',
                'rs.warehouse_id',
                'st.is_delivered',
                'st.is_confirmed',
                'st.picking_status',
            ])
            ->orderBy('stla.id')
            ->get();

        return $rows->map(function ($row) {
            $candidate = $this->evaluate($row);

            return (object) $candidate;
        });
    }

    /**
     * 1件の repoint を適用（eligible 前提）。stla.from_real_stock_lot_id を new_lot_id へ更新。
     *
     * - APPLY 直前に WMS 行存在を再チェック（プレビュー後に予約等が作られた場合に備える）。
     * - update() の影響行数が 1 の時だけ REPOINT 成功。0 件は SKIP（競合変更）扱いとし、成功ログにしない。
     */
    public function applyRepoint(object $candidate): array
    {
        // APPLY 直前の WMS 行再チェック
        if ($this->hasWmsRows((int) $candidate->stock_transfer_id, (int) $candidate->trade_item_id)) {
            return $this->repointSkip($candidate, 'WMS_ROWS_EXIST_AT_APPLY');
        }

        $merge = [];
        if ((bool) ($candidate->requires_positive_lot_merge ?? false)) {
            $merge = $this->mergePositiveLotsToTarget($candidate);
        }

        $affected = DB::connection($this->conn)
            ->table('stock_transfer_lot_allocations')
            ->where('id', $candidate->stla_id)
            ->where('from_real_stock_lot_id', $candidate->old_lot_id) // 楽観的整合
            ->where('quantity', $candidate->quantity)
            ->update([
                'from_real_stock_lot_id' => $candidate->new_lot_id,
                'updated_at' => now(),
            ]);

        if ($affected !== 1) {
            return $this->repointSkip($candidate, 'SKIP_CONCURRENTLY_CHANGED');
        }

        return [
            'type' => 'REPOINT',
            'real_stock_id' => (int) $candidate->real_stock_id,
            'lot_id' => null,
            'stla_id' => (int) $candidate->stla_id,
            'old_lot_id' => (int) $candidate->old_lot_id,
            'new_lot_id' => (int) $candidate->new_lot_id,
            'location_id' => $candidate->new_location_id !== null ? (int) $candidate->new_location_id : null,
            'quantity' => (int) $candidate->quantity,
            'new_lot_current' => $candidate->new_lot_current,
            'quantity_sufficient' => $candidate->quantity_sufficient,
            'affected_rows' => $affected,
            'reason' => $merge === [] ? 'NEGATIVE_LOT_REPOINT' : 'SAME_SHELF_POSITIVE_LOT_MERGE_REPOINT',
        ] + $merge;
    }

    private function repointSkip(object $candidate, string $reason): array
    {
        return [
            'type' => 'SKIP',
            'real_stock_id' => (int) $candidate->real_stock_id,
            'lot_id' => null,
            'stla_id' => (int) $candidate->stla_id,
            'old_lot_id' => (int) $candidate->old_lot_id,
            'new_lot_id' => null,
            'reason' => 'STLA_'.$reason,
        ];
    }

    /**
     * 候補1件の eligible 判定＋新LOT選定。
     */
    private function evaluate(object $row): array
    {
        $base = [
            'stla_id' => (int) $row->stla_id,
            'stock_transfer_id' => (int) $row->stock_transfer_id,
            'trade_item_id' => (int) $row->trade_item_id,
            'quantity' => (int) $row->quantity,
            'old_lot_id' => (int) $row->old_lot_id,
            'real_stock_id' => (int) $row->real_stock_id,
            'item_id' => (int) $row->item_id,
            'warehouse_id' => (int) $row->warehouse_id,
            'new_lot_id' => null,
            'new_location_id' => null,
            'new_lot_current' => null,
            'quantity_sufficient' => null,
            'requires_positive_lot_merge' => false,
            'merge_source_lot_ids' => [],
            'merge_all_lot_ids' => [],
            'eligible' => false,
            'skip_reason' => null,
        ];

        // 出荷確定済みは対象外
        if ((int) $row->is_delivered === 1 || (int) $row->is_confirmed === 1) {
            $base['skip_reason'] = 'DELIVERED_OR_CONFIRMED';

            return $base;
        }
        if (in_array($row->picking_status, ['PICKING', 'COMPLETED', 'SHIPPED'], true)) {
            $base['skip_reason'] = 'PICKING_STARTED';

            return $base;
        }

        // WMS行が存在する場合は STLA だけ自動更新しない
        if ($this->hasWmsRows((int) $row->stock_transfer_id, (int) $row->trade_item_id)) {
            $base['skip_reason'] = 'WMS_ROWS_EXIST';

            return $base;
        }

        // 同一 real_stock の有効な正ACTIVE候補
        $candidates = DB::connection($this->conn)
            ->table('real_stock_lots')
            ->where('real_stock_id', $row->real_stock_id)
            ->where('status', 'ACTIVE')
            ->where('current_quantity', '>', 0)
            ->orderByRaw('expiration_date IS NULL')
            ->orderBy('expiration_date')
            ->orderBy('id')
            ->get(['id', 'floor_id', 'location_id', 'current_quantity', 'reserved_quantity']);

        if ($candidates->isEmpty()) {
            $base['skip_reason'] = 'NO_POSITIVE_LOT';

            return $base;
        }
        if ($candidates->count() > 1) {
            $locationKeys = $candidates
                ->map(fn ($lot) => ($lot->floor_id ?? 'null').'|'.($lot->location_id ?? 'null'))
                ->unique()
                ->values();

            if ($locationKeys->count() > 1) {
                // 棚番が複数に分かれているものは、ユーザー指定棚番の判断が必要なため手動。
                $base['skip_reason'] = 'AMBIGUOUS_POSITIVE_LOT';

                return $base;
            }

            // 同一棚番内なら、最新LOTに正LOTを合算してから repoint する。
            $targetLot = $candidates
                ->sortByDesc(fn ($lot) => (int) $lot->id)
                ->first();

            $base['new_lot_id'] = (int) $targetLot->id;
            $base['new_location_id'] = $targetLot->location_id !== null ? (int) $targetLot->location_id : null;
            $base['new_lot_current'] = (int) $candidates->sum('current_quantity');
            $base['quantity_sufficient'] = (int) $base['new_lot_current'] >= (int) $row->quantity;
            $base['requires_positive_lot_merge'] = true;
            $base['merge_source_lot_ids'] = $candidates
                ->reject(fn ($lot) => (int) $lot->id === (int) $targetLot->id)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
            $base['merge_all_lot_ids'] = $candidates
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
            $base['eligible'] = true;

            return $base;
        }

        $newLot = $candidates->first();
        $base['new_lot_id'] = (int) $newLot->id;
        $base['new_location_id'] = $newLot->location_id !== null ? (int) $newLot->location_id : null;
        // 付け替え先LOTの在庫が stla.quantity を満たすか（満たさなくても repoint 自体は行う＝§4.1。
        // 運用者がプレビューで部分不足を判断できるよう情報として保持する）。
        $base['new_lot_current'] = (int) $newLot->current_quantity;
        $base['quantity_sufficient'] = (int) $newLot->current_quantity >= (int) $row->quantity;
        $base['eligible'] = true;

        return $base;
    }

    /**
     * 同一棚番内の正ACTIVE LOTを最新LOTへ合算する。
     *
     * current/reserved を target に寄せ、予約中RSLEだけ target LOT に移す。
     * source LOT は 0/0 DEPLETED にする。floor_id/location_id は変更しない。
     */
    private function mergePositiveLotsToTarget(object $candidate): array
    {
        $targetLotId = (int) $candidate->new_lot_id;
        $lotIds = collect($candidate->merge_all_lot_ids ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($targetLotId <= 0 || $lotIds === [] || ! in_array($targetLotId, $lotIds, true)) {
            throw new \RuntimeException('同一棚番LOT合算の対象LOTが不正です');
        }

        $lots = DB::connection($this->conn)
            ->table('real_stock_lots')
            ->whereIn('id', $lotIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'real_stock_id', 'floor_id', 'location_id', 'status', 'current_quantity', 'reserved_quantity']);

        if ($lots->count() !== count($lotIds)) {
            throw new \RuntimeException('同一棚番LOT合算の対象LOTが不足しています');
        }

        $locationKeys = $lots
            ->map(fn ($lot) => ($lot->floor_id ?? 'null').'|'.($lot->location_id ?? 'null'))
            ->unique()
            ->values();
        if ($locationKeys->count() !== 1) {
            throw new \RuntimeException('同一棚番LOT合算の実行直前に棚番が分岐しました');
        }

        $invalid = $lots->first(function ($lot) use ($candidate, $targetLotId) {
            if ((int) $lot->real_stock_id !== (int) $candidate->real_stock_id) {
                return true;
            }

            if ((int) $lot->id === $targetLotId) {
                return $lot->status !== 'ACTIVE' || (int) $lot->current_quantity <= 0;
            }

            return ! $this->isMergeablePositiveLot($lot) && ! $this->isAlreadyMergedSourceLot($lot);
        });
        if ($invalid !== null) {
            throw new \RuntimeException("同一棚番LOT合算の対象LOT {$invalid->id} が合算可能な状態ではありません");
        }

        $sourceLotIds = $lots
            ->filter(fn ($lot) => (int) $lot->id !== $targetLotId && $this->isMergeablePositiveLot($lot))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
        if ($sourceLotIds === []) {
            return [
                'merged_to_lot_id' => $targetLotId,
                'merged_source_lot_ids' => [],
                'merged_current_quantity' => 0,
                'merged_reserved_quantity' => 0,
                'moved_reserved_rsle_count' => 0,
                'merge_already_applied' => true,
            ];
        }

        $targetLot = $lots->first(fn ($lot) => (int) $lot->id === $targetLotId);
        $sourceLots = $lots->filter(fn ($lot) => in_array((int) $lot->id, $sourceLotIds, true));

        $mergedCurrent = (int) $sourceLots->sum('current_quantity');
        $mergedReserved = (int) $sourceLots->sum('reserved_quantity');
        $now = now();

        $reservedPiecesByLot = DB::connection($this->conn)
            ->table('real_stock_lot_earnings')
            ->selectRaw('real_stock_lot_id, COALESCE(SUM(quantity), 0) as reserved_pieces')
            ->whereIn('real_stock_lot_id', $sourceLotIds)
            ->where('status', 'RESERVED')
            ->groupBy('real_stock_lot_id')
            ->pluck('reserved_pieces', 'real_stock_lot_id');

        $reservedMismatchLot = $sourceLots->first(function ($lot) use ($reservedPiecesByLot) {
            return (int) $lot->reserved_quantity !== (int) ($reservedPiecesByLot[$lot->id] ?? 0);
        });
        if ($reservedMismatchLot !== null) {
            throw new \RuntimeException(
                "同一棚番LOT合算を中止: LOT {$reservedMismatchLot->id} のreservedとRSLE RESERVED合計が一致しません"
            );
        }

        $movedRsleCount = DB::connection($this->conn)
            ->table('real_stock_lot_earnings')
            ->whereIn('real_stock_lot_id', $sourceLotIds)
            ->where('status', 'RESERVED')
            ->update([
                'real_stock_lot_id' => $targetLotId,
                'updated_at' => $now,
            ]);

        DB::connection($this->conn)
            ->table('real_stock_lots')
            ->where('id', $targetLotId)
            ->update([
                'current_quantity' => (int) $targetLot->current_quantity + $mergedCurrent,
                'reserved_quantity' => (int) $targetLot->reserved_quantity + $mergedReserved,
                'updated_at' => $now,
            ]);

        DB::connection($this->conn)
            ->table('real_stock_lots')
            ->whereIn('id', $sourceLotIds)
            ->update([
                'current_quantity' => 0,
                'reserved_quantity' => 0,
                'status' => 'DEPLETED',
                'updated_at' => $now,
            ]);

        return [
            'merged_to_lot_id' => $targetLotId,
            'merged_source_lot_ids' => $sourceLotIds,
            'merged_current_quantity' => $mergedCurrent,
            'merged_reserved_quantity' => $mergedReserved,
            'moved_reserved_rsle_count' => $movedRsleCount,
            'merge_already_applied' => false,
        ];
    }

    private function isMergeablePositiveLot(object $lot): bool
    {
        return $lot->status === 'ACTIVE' && (int) $lot->current_quantity > 0;
    }

    private function isAlreadyMergedSourceLot(object $lot): bool
    {
        return $lot->status === 'DEPLETED'
            && (int) $lot->current_quantity === 0
            && (int) $lot->reserved_quantity === 0;
    }

    private function hasWmsRows(int $stockTransferId, int $tradeItemId): bool
    {
        $hasReservation = DB::connection($this->conn)
            ->table('wms_reservations')
            ->where('source_type', 'STOCK_TRANSFER')
            ->where('source_id', $stockTransferId)
            ->where('source_line_id', $tradeItemId)
            ->exists();
        if ($hasReservation) {
            return true;
        }

        $hasPickResult = DB::connection($this->conn)
            ->table('wms_picking_item_results')
            ->where('source_type', 'STOCK_TRANSFER')
            ->where('stock_transfer_id', $stockTransferId)
            ->exists();
        if ($hasPickResult) {
            return true;
        }

        return DB::connection($this->conn)
            ->table('wms_shortages')
            ->where('trade_item_id', $tradeItemId)
            ->exists();
    }
}
