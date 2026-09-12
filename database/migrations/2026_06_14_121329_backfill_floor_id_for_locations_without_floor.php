<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        $db = DB::connection('sakemaru');

        $db->transaction(function () use ($db) {
            // 1. 重複ロケーションの統合
            //    floor_id NULL のロケーションと同じ (warehouse_id, code1, code2, code3) で
            //    floor_id が設定済みのロケーションが既に存在する場合、参照を付け替えて無効化する
            $duplicates = $db->select("
                SELECT target.id AS obsolete_id, dup.id AS canonical_id
                FROM locations target
                INNER JOIN (
                    SELECT warehouse_id, code1, floor_id
                    FROM (
                        SELECT warehouse_id, code1, floor_id, COUNT(*) AS cnt,
                               ROW_NUMBER() OVER (PARTITION BY warehouse_id, code1 ORDER BY COUNT(*) DESC) AS rn
                        FROM locations
                        WHERE floor_id IS NOT NULL AND code1 IS NOT NULL AND is_disabled = 0
                        GROUP BY warehouse_id, code1, floor_id
                    ) ranked WHERE rn = 1
                ) ref ON ref.warehouse_id = target.warehouse_id AND ref.code1 = target.code1
                INNER JOIN locations dup
                    ON dup.warehouse_id = target.warehouse_id
                    AND dup.floor_id = ref.floor_id
                    AND dup.code1 = target.code1
                    AND COALESCE(dup.code2, '') = COALESCE(target.code2, '')
                    AND COALESCE(dup.code3, '') = COALESCE(target.code3, '')
                    AND dup.id != target.id
                WHERE target.floor_id IS NULL AND target.is_disabled = 0 AND target.code1 IS NOT NULL
            ");

            foreach ($duplicates as $dup) {
                $old = $dup->obsolete_id;
                $new = $dup->canonical_id;

                $db->table('real_stock_lots')
                    ->where('location_id', $old)
                    ->update(['location_id' => $new]);

                $db->table('item_incoming_default_locations')
                    ->where('location_id', $old)
                    ->update(['location_id' => $new]);

                $db->table('wms_picking_item_results')
                    ->where('location_id', $old)
                    ->update(['location_id' => $new]);

                $db->table('wms_reservations')
                    ->where('location_id', $old)
                    ->update(['location_id' => $new]);

                $db->table('locations')
                    ->where('id', $old)
                    ->update(['is_disabled' => true]);
            }

            // 2. 残りの floor_id NULL ロケーションに、同じ warehouse_id + code1 の最頻 floor_id を補完
            $db->statement("
                UPDATE locations AS target
                INNER JOIN (
                    SELECT warehouse_id, code1, floor_id
                    FROM (
                        SELECT warehouse_id, code1, floor_id, COUNT(*) AS cnt,
                               ROW_NUMBER() OVER (PARTITION BY warehouse_id, code1 ORDER BY COUNT(*) DESC) AS rn
                        FROM locations
                        WHERE floor_id IS NOT NULL
                          AND code1 IS NOT NULL
                          AND is_disabled = 0
                        GROUP BY warehouse_id, code1, floor_id
                    ) ranked
                    WHERE rn = 1
                ) AS ref ON ref.warehouse_id = target.warehouse_id AND ref.code1 = target.code1
                SET target.floor_id = ref.floor_id
                WHERE target.floor_id IS NULL
                  AND target.is_disabled = 0
                  AND target.code1 IS NOT NULL
            ");
        });
    }

    public function down(): void
    {
        // データ補完・統合のため rollback は行わない
    }
};
