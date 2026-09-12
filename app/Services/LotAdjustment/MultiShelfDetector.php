<?php

namespace App\Services\LotAdjustment;

use Illuminate\Support\Facades\DB;

/**
 * 複数棚番・空棚番の検出（検出のみ・適用しない）
 *
 * 棚番の自動統一（USER_CHANGED/USER_CREATED への寄せ）は、本番DBに per-lot の
 * 棚番変更監査ログ・ユーザー作成LOT判別が存在しないため自動化しない。
 * 再発検知のため、以下をプレビューに表示する。
 *
 * - MULTI_SHELF      : 有数量 ACTIVE LOT が 2 棚番以上に分かれている real_stock（手動判断）
 * - BLANK_LOCATION   : 有数量 ACTIVE LOT の floor/location が空（要手動棚番割当）
 */
class MultiShelfDetector
{
    private string $conn = 'sakemaru';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function detectForWarehouse(int $warehouseId): array
    {
        // 有数量＝current または reserved が 0 でない（出荷待ち・予約絡みも棚番ズレ対象に含める）
        $hasQuantity = function ($q) {
            $q->where('l.current_quantity', '<>', 0)->orWhere('l.reserved_quantity', '<>', 0);
        };

        $candidateIds = DB::connection($this->conn)
            ->table('real_stock_lots as l')
            ->join('real_stocks as rs', 'rs.id', '=', 'l.real_stock_id')
            ->where('rs.warehouse_id', $warehouseId)
            ->where('l.status', 'ACTIVE')
            ->where($hasQuantity)
            ->groupBy('l.real_stock_id')
            ->havingRaw('COUNT(DISTINCT l.location_id) >= 2 OR SUM(CASE WHEN l.location_id IS NULL THEN 1 ELSE 0 END) > 0')
            ->pluck('l.real_stock_id')
            ->all();

        if (empty($candidateIds)) {
            return [];
        }

        $lotsByStock = DB::connection($this->conn)
            ->table('real_stock_lots as l')
            ->leftJoin('locations as loc', 'loc.id', '=', 'l.location_id')
            ->whereIn('l.real_stock_id', $candidateIds)
            ->where('l.status', 'ACTIVE')
            ->where($hasQuantity)
            ->selectRaw("l.real_stock_id, l.id as lot_id, l.location_id, l.current_quantity, l.reserved_quantity,
                CONCAT_WS('-', loc.code1, loc.code2, loc.code3) as shelf")
            ->orderBy('l.real_stock_id')
            ->orderBy('l.id')
            ->get()
            ->groupBy('real_stock_id');

        $records = [];

        foreach ($lotsByStock as $rsId => $group) {
            $distinctNonNull = $group->pluck('location_id')->filter(fn ($v) => $v !== null)->unique();

            if ($distinctNonNull->count() >= 2) {
                $detail = $group
                    ->map(fn ($r) => $r->lot_id.'@'.($r->shelf ?: 'NULL').':'.$r->current_quantity.'/'.$r->reserved_quantity)
                    ->implode(', ');

                $records[] = [
                    'type' => 'MULTI_SHELF',
                    'real_stock_id' => (int) $rsId,
                    'lot_id' => null,
                    'reason' => 'MULTI_SHELF_MANUAL_REQUIRED: '.$detail,
                ];
            }

            foreach ($group as $r) {
                if ($r->location_id === null) {
                    $records[] = [
                        'type' => 'BLANK_LOCATION',
                        'real_stock_id' => (int) $rsId,
                        'lot_id' => (int) $r->lot_id,
                        'current_before' => (int) $r->current_quantity,
                        'current_after' => (int) $r->current_quantity,
                        'reserved_before' => (int) $r->reserved_quantity,
                        'reserved_after' => (int) $r->reserved_quantity,
                        'location_id' => null,
                        'reason' => 'BLANK_LOCATION_LOT（要手動棚番割当）',
                    ];
                }
            }
        }

        return $records;
    }
}
