<?php

namespace App\Services\LotAdjustment;

use App\Models\Sakemaru\RealStock;
use App\Models\Sakemaru\RealStockLot;
use Illuminate\Support\Facades\DB;

/**
 * C. ACTIVE LOT 合計を real_stocks に合わせる（安全な適用）
 *
 * 制約（棚番事故防止）:
 * - real_stocks は更新しない（real_stocks を正とする）
 * - ACTIVE LOT 合計を real_stocks.current/reserved に合わせる
 * - 棚番が一意に決まる場合のみ自動適用＝「ACTIVE LOT が単一」のときだけ、その LOT の数量を親に合わせる
 * - ACTIVE LOT が 0 個（新規補正LOTが必要）または複数（対象が一意でない）は MANUAL_REQUIRED
 * - floor_id/location_id は変更しない。Z00 や直近 LOT を安易に採用しない（新規作成しない）
 * - 適用後に parent.current/reserved == ACTIVE LOT 合計 を検証（不一致なら例外→Runnerがロールバック）
 */
class LotParentSyncService
{
    private string $conn = 'sakemaru';

    /**
     * @return array<int, array<string, mixed>> 明細（SYNC_APPLIED / SYNC_MANUAL）
     */
    public function applyForRealStock(int $realStockId): array
    {
        $rs = RealStock::query()->whereKey($realStockId)->lockForUpdate()->first();
        if (! $rs) {
            return [];
        }

        $activeLots = RealStockLot::query()
            ->where('real_stock_id', $realStockId)
            ->where('status', RealStockLot::STATUS_ACTIVE)
            ->lockForUpdate()
            ->orderBy('id')
            ->get();

        $activeCurrent = (int) $activeLots->sum('current_quantity');
        $activeReserved = (int) $activeLots->sum('reserved_quantity');
        $parentCurrent = (int) $rs->current_quantity;
        $parentReserved = (int) $rs->reserved_quantity;

        // 既に一致していれば何もしない
        if ($activeCurrent === $parentCurrent && $activeReserved === $parentReserved) {
            return [];
        }

        // 棚番/対象LOTが一意でない（0個=新規補正が必要 / 複数=対象曖昧）→ 手動
        if ($activeLots->count() !== 1) {
            return [[
                'type' => 'SYNC_MANUAL',
                'real_stock_id' => $realStockId,
                'lot_id' => null,
                'current_before' => $activeCurrent,
                'current_after' => $parentCurrent,
                'reserved_before' => $activeReserved,
                'reserved_after' => $parentReserved,
                'reason' => $activeLots->isEmpty() ? 'SYNC_NO_ACTIVE_LOT' : 'SYNC_MULTIPLE_ACTIVE_LOTS',
            ]];
        }

        /** @var RealStockLot $lot */
        $lot = $activeLots->first();
        $before = [
            'current' => (int) $lot->current_quantity,
            'reserved' => (int) $lot->reserved_quantity,
            'status' => $lot->status,
        ];

        // 単一 ACTIVE LOT の数量を親に合わせる（棚番は不変）
        $lot->current_quantity = $parentCurrent;
        $lot->reserved_quantity = $parentReserved;
        $lot->status = ($parentCurrent === 0 && $parentReserved === 0)
            ? RealStockLot::STATUS_DEPLETED
            : RealStockLot::STATUS_ACTIVE;
        $lot->save();

        // 適用後検証: ACTIVE 合計 == 親
        $verify = DB::connection($this->conn)
            ->table('real_stock_lots')
            ->where('real_stock_id', $realStockId)
            ->where('status', RealStockLot::STATUS_ACTIVE)
            ->selectRaw('COALESCE(SUM(current_quantity),0) c, COALESCE(SUM(reserved_quantity),0) r')
            ->first();

        if ((int) $verify->c !== $parentCurrent || (int) $verify->r !== $parentReserved) {
            throw new \RuntimeException("LotParentSync verify failed for real_stock {$realStockId}");
        }

        return [[
            'type' => 'SYNC_APPLIED',
            'real_stock_id' => $realStockId,
            'lot_id' => (int) $lot->id,
            'status_before' => $before['status'],
            'status_after' => $lot->status,
            'current_before' => $before['current'],
            'current_after' => (int) $lot->current_quantity,
            'reserved_before' => $before['reserved'],
            'reserved_after' => (int) $lot->reserved_quantity,
            'location_id' => $lot->location_id !== null ? (int) $lot->location_id : null,
            'reason' => 'ALIGN_SINGLE_ACTIVE_LOT_TO_PARENT',
        ]];
    }
}
