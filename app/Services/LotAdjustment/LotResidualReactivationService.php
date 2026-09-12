<?php

namespace App\Services\LotAdjustment;

use App\Models\Sakemaru\RealStock;
use App\Models\Sakemaru\RealStockLot;

/**
 * B. 非ACTIVE残数の分類・修復（§3.3）
 *
 * 数量を持つ非ACTIVE LOT（status != ACTIVE かつ current<>0 or reserved<>0）を検出し分類する。
 *
 * - REACTIVATE_SAME_LOT : 対象LOT自身が棚番・仕入情報を持ち、ACTIVE化すると親在庫へ近づく
 *                         → そのLOT自身を ACTIVE 化（別の0/0 LOTは使わない・棚番不変）
 * - MANUAL_REQUIRED      : 帰属LOT・棚番・仕入情報を一意に判断できない → 検出のみ・適用しない
 *
 * 棚番事故防止のため、別の 0/0 DEPLETED LOT を ACTIVE 化して数量を寄せることはしない。
 * floor_id / location_id は変更しない。
 *
 * 注: 本サービスは Runner で A（相殺）の後段に実行される。そのため「負ACTIVEロット ＋
 * 非ACTIVE正残数」が同居する real_stock では、A 実行時点で正残数はまだ非ACTIVE のため
 * 相殺に使われない（過剰補正を避ける保守的動作）。必要なら再実行で次段が処理する。
 */
class LotResidualReactivationService
{
    /**
     * @return array<int, array<string, mixed>> 明細（type=REACTIVATE / SKIP）
     */
    public function applyForRealStock(int $realStockId): array
    {
        $changes = [];

        $realStock = RealStock::query()->whereKey($realStockId)->lockForUpdate()->first();
        if (! $realStock) {
            return $changes;
        }

        $residuals = RealStockLot::query()
            ->where('real_stock_id', $realStockId)
            ->where('status', '!=', RealStockLot::STATUS_ACTIVE)
            ->where(function ($q) {
                $q->where('current_quantity', '<>', 0)
                    ->orWhere('reserved_quantity', '<>', 0);
            })
            ->lockForUpdate()
            ->orderBy('id')
            ->get();

        if ($residuals->isEmpty()) {
            return $changes;
        }

        $parentCurrent = (int) $realStock->current_quantity;
        $activeSum = (int) RealStockLot::query()
            ->where('real_stock_id', $realStockId)
            ->where('status', RealStockLot::STATUS_ACTIVE)
            ->sum('current_quantity');

        foreach ($residuals as $lot) {
            $classification = $this->classify($lot, $parentCurrent, $activeSum);

            if ($classification === 'REACTIVATE_SAME_LOT') {
                $before = ['status' => $lot->status];
                $lot->status = RealStockLot::STATUS_ACTIVE;
                // floor_id / location_id は触らない（対象LOT自身の棚番のまま）
                $lot->save();
                $activeSum += (int) $lot->current_quantity;

                $changes[] = [
                    'type' => 'REACTIVATE',
                    'real_stock_id' => $realStockId,
                    'lot_id' => (int) $lot->id,
                    'status_before' => $before['status'],
                    'status_after' => $lot->status,
                    'current_before' => (int) $lot->current_quantity,
                    'current_after' => (int) $lot->current_quantity,
                    'reserved_before' => (int) $lot->reserved_quantity,
                    'reserved_after' => (int) $lot->reserved_quantity,
                    'location_id' => $lot->location_id !== null ? (int) $lot->location_id : null,
                    'reason' => 'REACTIVATE_SAME_LOT',
                ];
            } elseif ($classification === 'ZERO_ABNORMAL_RESIDUAL') {
                // 親在庫が既に ACTIVE 合計と一致しており、非ACTIVE LOT に余剰数量が残っている異常残。
                // 0/0 DEPLETED に落とす。棚番は維持し DELETED にはしない（棚番喪失を防ぐ）。
                $before = ['current' => (int) $lot->current_quantity, 'reserved' => (int) $lot->reserved_quantity, 'status' => $lot->status];
                $lot->current_quantity = 0;
                $lot->reserved_quantity = 0;
                $lot->status = RealStockLot::STATUS_DEPLETED;
                $lot->save();

                $changes[] = [
                    'type' => 'ZERO_RESIDUAL',
                    'real_stock_id' => $realStockId,
                    'lot_id' => (int) $lot->id,
                    'status_before' => $before['status'],
                    'status_after' => $lot->status,
                    'current_before' => $before['current'],
                    'current_after' => 0,
                    'reserved_before' => $before['reserved'],
                    'reserved_after' => 0,
                    'location_id' => $lot->location_id !== null ? (int) $lot->location_id : null,
                    'reason' => 'ZERO_ABNORMAL_RESIDUAL',
                ];
            } else {
                $changes[] = [
                    'type' => 'SKIP',
                    'real_stock_id' => $realStockId,
                    'lot_id' => (int) $lot->id,
                    'status_before' => $lot->status,
                    'status_after' => $lot->status,
                    'current_before' => (int) $lot->current_quantity,
                    'current_after' => (int) $lot->current_quantity,
                    'reserved_before' => (int) $lot->reserved_quantity,
                    'reserved_after' => (int) $lot->reserved_quantity,
                    'location_id' => $lot->location_id !== null ? (int) $lot->location_id : null,
                    'reason' => $classification,
                ];
            }
        }

        return $changes;
    }

    /**
     * 非ACTIVE残数LOTの分類。
     * - REACTIVATE_SAME_LOT   : ACTIVE化で親在庫へ近づく（棚番・仕入根拠あり）
     * - ZERO_ABNORMAL_RESIDUAL: 親在庫が既に ACTIVE 合計と一致し、余剰の current のみ残る異常残 → 0/0 DEPLETED
     * - MANUAL_REQUIRED        : 上記以外（予約残・負残・超過・根拠不足）
     */
    private function classify(RealStockLot $lot, int $parentCurrent, int $activeSum): string
    {
        // 予約残のある非ACTIVE、負数残は自動で触らない
        if ((int) $lot->reserved_quantity !== 0) {
            return 'MANUAL_REQUIRED';
        }
        if ((int) $lot->current_quantity <= 0) {
            return 'MANUAL_REQUIRED';
        }

        $oldDiff = abs($parentCurrent - $activeSum);
        $newDiff = abs($parentCurrent - ($activeSum + (int) $lot->current_quantity));

        // 不足を埋める方向に近づくなら ACTIVE 化（棚番・仕入根拠が必要）
        if ($newDiff < $oldDiff) {
            if ($lot->location_id === null || $lot->purchase_id === null) {
                return 'MANUAL_REQUIRED';
            }

            return 'REACTIVATE_SAME_LOT';
        }

        // 親が既に ACTIVE 合計と一致しているのに current 残がある＝余剰の異常残 → 0/0 DEPLETED
        if ($oldDiff === 0) {
            return 'ZERO_ABNORMAL_RESIDUAL';
        }

        return 'MANUAL_REQUIRED';
    }
}
