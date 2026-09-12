<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_inventory_count_items', function (Blueprint $table) {
            $table->string('first_count_actor_name', 100)->nullable()->after('first_count_quantity');
            $table->string('second_count_actor_name', 100)->nullable()->after('second_count_quantity');
            $table->string('final_count_actor_name', 100)->nullable()->after('final_count_quantity');
        });

        DB::connection('sakemaru')->statement("
            UPDATE wms_inventory_count_items
            SET location_no = CONCAT(
                COALESCE(location_code1, ''),
                COALESCE(location_code2, ''),
                COALESCE(location_code3, '')
            )
            WHERE location_code1 IS NOT NULL
               OR location_code2 IS NOT NULL
               OR location_code3 IS NOT NULL
        ");

        $this->backfillActorName(1, 'first_count_quantity', 'first_count_actor_name');
        $this->backfillActorName(2, 'second_count_quantity', 'second_count_actor_name');
        $this->backfillActorName(3, 'final_count_quantity', 'final_count_actor_name');
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_inventory_count_items', function (Blueprint $table) {
            $table->dropColumn([
                'first_count_actor_name',
                'second_count_actor_name',
                'final_count_actor_name',
            ]);
        });
    }

    private function backfillActorName(int $round, string $quantityColumn, string $actorColumn): void
    {
        DB::connection('sakemaru')->statement("
            UPDATE wms_inventory_count_items ici
            SET {$actorColumn} = (
                SELECT CASE
                    WHEN l.device_id = 'WEB' THEN COALESCE(CONCAT('WEB: ', u.name), 'WEB')
                    ELSE COALESCE(CONCAT('[', p.code, '] ', p.name), u.name, CONCAT('HANDY: ', l.device_id), '不明')
                END
                FROM wms_inventory_count_item_logs l
                LEFT JOIN users u ON u.id = l.user_id
                LEFT JOIN wms_pickers p ON p.id = l.user_id
                WHERE l.inventory_count_item_id = ici.id
                  AND l.count_round = {$round}
                ORDER BY l.created_at DESC, l.id DESC
                LIMIT 1
            )
            WHERE ici.{$quantityColumn} IS NOT NULL
              AND ici.{$actorColumn} IS NULL
        ");
    }
};
