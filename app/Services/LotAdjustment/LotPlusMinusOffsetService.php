<?php

namespace App\Services\LotAdjustment;

use App\Models\Sakemaru\RealStockLot;

/**
 * A. +/- 相殺
 *
 * 同一 real_stock 内の ACTIVE 正ロット（current > reserved）と
 * ACTIVE 負ロット（current < 0, reserved = 0）を相殺する。
 *
 * 仕様: spec-lot-plus-minus-offset.md
 * - offset = min(正ロット未予約分, abs(負ロット current))
 * - 正ロット current -= offset / 負ロット current += offset / reserved は不変
 * - current=0 かつ reserved=0 になったロットは DEPLETED（物理削除しない）
 * - floor_id / location_id は一切変更しない（棚番不変）
 * - real_stocks / reserved / WMS行 / earnings は変更しない
 *
 * 呼び出し側（Runner）が real_stock 単位のトランザクションを保持している前提。
 * 本サービスは lockForUpdate でロットを取得し、変更明細を返す。
 */
class LotPlusMinusOffsetService
{
    /**
     * @return array<int, array<string, mixed>> 変更明細（type=OFFSET）
     */
    public function applyForRealStock(int $realStockId): array
    {
        $changes = [];

        $lots = RealStockLot::query()
            ->where('real_stock_id', $realStockId)
            ->where('status', RealStockLot::STATUS_ACTIVE)
            ->lockForUpdate()
            ->orderBy('id')
            ->get();

        $positives = $lots
            ->filter(fn (RealStockLot $l) => (int) $l->current_quantity > (int) $l->reserved_quantity)
            ->values();
        $negatives = $lots
            ->filter(fn (RealStockLot $l) => (int) $l->current_quantity < 0 && (int) $l->reserved_quantity === 0)
            ->values();

        foreach ($negatives as $neg) {
            $remaining = abs((int) $neg->current_quantity);

            foreach ($positives as $pos) {
                if ($remaining <= 0) {
                    break;
                }

                $available = (int) $pos->current_quantity - (int) $pos->reserved_quantity;
                if ($available <= 0) {
                    continue;
                }

                $offset = min($available, $remaining);
                if ($offset <= 0) {
                    continue;
                }

                $changes[] = $this->applyOffsetToLot($realStockId, $pos, -$offset);
                $changes[] = $this->applyOffsetToLot($realStockId, $neg, +$offset);

                $remaining -= $offset;
            }
        }

        return $changes;
    }

    /**
     * 1ロットへ delta を適用し、status を同期して保存。before/after 明細を返す。
     */
    private function applyOffsetToLot(int $realStockId, RealStockLot $lot, int $delta): array
    {
        $before = [
            'current' => (int) $lot->current_quantity,
            'status' => $lot->status,
        ];

        $lot->current_quantity = (int) $lot->current_quantity + $delta;
        $this->syncStatus($lot);
        // floor_id / location_id は触らない
        $lot->save();

        return [
            'type' => 'OFFSET',
            'real_stock_id' => $realStockId,
            'lot_id' => (int) $lot->id,
            'status_before' => $before['status'],
            'status_after' => $lot->status,
            'current_before' => $before['current'],
            'current_after' => (int) $lot->current_quantity,
            'reserved_before' => (int) $lot->reserved_quantity,
            'reserved_after' => (int) $lot->reserved_quantity,
            'location_id' => $lot->location_id !== null ? (int) $lot->location_id : null,
            'reason' => null,
        ];
    }

    /**
     * current=0 かつ reserved=0 のとき DEPLETED、それ以外は ACTIVE。
     */
    private function syncStatus(RealStockLot $lot): void
    {
        if ((int) $lot->current_quantity === 0 && (int) $lot->reserved_quantity === 0) {
            $lot->status = RealStockLot::STATUS_DEPLETED;
        } else {
            $lot->status = RealStockLot::STATUS_ACTIVE;
        }
    }
}
