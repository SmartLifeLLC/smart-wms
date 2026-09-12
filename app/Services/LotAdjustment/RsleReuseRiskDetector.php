<?php

namespace App\Services\LotAdjustment;

use Illuminate\Support\Facades\DB;

/**
 * E. RSLE 再利用リスク検出（検出のみ・自動補正なし）
 *
 * 親在庫の available が負なのに、同じ受注の real_stock_lot_earnings.status='RESERVED' が残っており、
 * WMS 波動生成時に「自分の予約（own reservation）」を加算して実在庫不足でも引当してしまうリスク行を検出する。
 *
 * 重要（禁止事項）:
 * - このツールの APPLY で real_stock_lot_earnings.status を CANCELLED にしない。
 * - real_stocks / real_stock_lots / wms_* を一切変更しない。検出して表示・ログするだけ。
 * - mismatch_count=0 を理由にリスクなしと判定しない（親=ACTIVE合計でもリスクは成立する）。
 *
 * 分類:
 * - RSLE_REUSE_RISK       : WMS 行（予約/ピッキング結果/有効な欠品）が無い。波動前に RSLE 要確認。
 * - RSLE_REUSE_WMS_EXISTS : 既に WMS 行が存在。自動 CANCEL してはいけない・WMS 状態調査が必要。
 */
class RsleReuseRiskDetector
{
    private string $conn = 'sakemaru';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function detectForWarehouse(int $warehouseId): array
    {
        // WMS が own reservation を加算して再利用可能と判断する数量（spec の式）
        $reusableExpr = 'GREATEST(LEAST(rsl.current_quantity,
              rsl.current_quantity
                - GREATEST(rsl.reserved_quantity, COALESCE(lr.rp, 0))
                + COALESCE(own.op, 0)), 0)';

        $sql = "
            SELECT
                rs.id AS real_stock_id,
                rsl.id AS lot_id,
                rsle.id AS real_stock_lot_earning_id,
                rsle.earning_id,
                rsle.trade_item_id,
                i.code AS item_code,
                i.name AS item_name,
                rs.current_quantity AS parent_current,
                rs.reserved_quantity AS parent_reserved,
                rs.available_quantity AS parent_available,
                rsl.current_quantity AS lot_current,
                rsl.reserved_quantity AS lot_reserved,
                COALESCE(lr.rp, 0) AS lot_reserved_earnings_pieces,
                COALESCE(own.op, 0) AS own_reserved_pieces,
                {$reusableExpr} AS wms_reusable_pieces,
                ti.quantity_type,
                ti.quantity AS trade_item_quantity,
                e.delivered_date,
                t.serial_id AS trade_serial_id,
                (SELECT COUNT(*) FROM wms_reservations wr
                    WHERE wr.source_type='EARNING' AND wr.source_id=rsle.earning_id AND wr.source_line_id=rsle.trade_item_id) AS wms_reservation_count,
                (SELECT COUNT(*) FROM wms_picking_item_results wpir
                    WHERE wpir.source_type='EARNING' AND wpir.earning_id=rsle.earning_id) AS wms_picking_result_count,
                (SELECT COUNT(*) FROM wms_shortages ws
                    WHERE ws.trade_item_id=rsle.trade_item_id AND ws.status <> 'CANCELLED') AS wms_shortage_count
            FROM real_stocks rs
            JOIN items i ON i.id = rs.item_id AND i.is_managed_stock = 1
            JOIN real_stock_lots rsl ON rsl.real_stock_id = rs.id AND rsl.status = 'ACTIVE'
            JOIN real_stock_lot_earnings rsle ON rsle.real_stock_lot_id = rsl.id AND rsle.status = 'RESERVED'
            LEFT JOIN earnings e ON e.id = rsle.earning_id
            LEFT JOIN trades t ON t.id = e.trade_id
            LEFT JOIN trade_items ti ON ti.id = rsle.trade_item_id
            LEFT JOIN (SELECT real_stock_lot_id, SUM(quantity) rp FROM real_stock_lot_earnings WHERE status='RESERVED' GROUP BY real_stock_lot_id) lr
                ON lr.real_stock_lot_id = rsl.id
            LEFT JOIN (SELECT real_stock_lot_id, earning_id, trade_item_id, SUM(quantity) op FROM real_stock_lot_earnings WHERE status='RESERVED' GROUP BY real_stock_lot_id, earning_id, trade_item_id) own
                ON own.real_stock_lot_id = rsl.id AND own.earning_id = rsle.earning_id AND own.trade_item_id = rsle.trade_item_id
            WHERE rs.warehouse_id = ?
              AND rs.available_quantity < 0
              AND {$reusableExpr} > 0
            ORDER BY rs.id, rsl.id, rsle.id
        ";

        $rows = DB::connection($this->conn)->select($sql, [$warehouseId]);

        $records = [];
        foreach ($rows as $r) {
            $wmsTotal = (int) $r->wms_reservation_count + (int) $r->wms_picking_result_count + (int) $r->wms_shortage_count;
            $hasWms = $wmsTotal > 0;

            $code = $hasWms ? 'RSLE_REUSE_RISK_WMS_ROWS_EXIST' : 'RSLE_REUSE_RISK_NO_WMS_ROWS';
            $info = sprintf(
                'RSLE%d / earn%d / ti%d / 再利用%d / WMS%s',
                (int) $r->real_stock_lot_earning_id,
                (int) $r->earning_id,
                (int) $r->trade_item_id,
                (int) $r->wms_reusable_pieces,
                $hasWms ? '有' : '無',
            );

            $records[] = [
                'type' => $hasWms ? 'RSLE_REUSE_WMS_EXISTS' : 'RSLE_REUSE_RISK',
                'real_stock_id' => (int) $r->real_stock_id,
                'lot_id' => (int) $r->lot_id,
                'real_stock_lot_earning_id' => (int) $r->real_stock_lot_earning_id,
                'earning_id' => (int) $r->earning_id,
                'trade_item_id' => (int) $r->trade_item_id,
                'item_code' => $r->item_code,
                'item_name' => $r->item_name,
                'parent_current' => (int) $r->parent_current,
                'parent_reserved' => (int) $r->parent_reserved,
                'parent_available' => (int) $r->parent_available,
                'lot_current' => (int) $r->lot_current,
                'lot_reserved' => (int) $r->lot_reserved,
                'lot_reserved_earnings_pieces' => (int) $r->lot_reserved_earnings_pieces,
                'own_reserved_pieces' => (int) $r->own_reserved_pieces,
                'wms_reusable_pieces' => (int) $r->wms_reusable_pieces,
                'quantity_type' => $r->quantity_type,
                'trade_item_quantity' => $r->trade_item_quantity !== null ? (int) $r->trade_item_quantity : null,
                'delivered_date' => $r->delivered_date,
                'trade_serial_id' => $r->trade_serial_id !== null ? (int) $r->trade_serial_id : null,
                'wms_reservation_count' => (int) $r->wms_reservation_count,
                'wms_picking_result_count' => (int) $r->wms_picking_result_count,
                'wms_shortage_count' => (int) $r->wms_shortage_count,
                'location_id' => null,
                'reason' => $code.': '.$info,
            ];
        }

        return $records;
    }
}
