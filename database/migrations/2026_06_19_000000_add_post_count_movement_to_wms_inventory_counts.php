<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_inventory_counts', function (Blueprint $table) {
            if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_counts', 'stock_movement_from_at')) {
                $table->timestamp('stock_movement_from_at')->nullable()->after('current_stock_saved_at')->comment('受払計算基準日時');
            }

            if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_counts', 'stock_movement_calculated_at')) {
                $table->timestamp('stock_movement_calculated_at')->nullable()->after('stock_movement_from_at')->comment('受払計算実行日時');
            }
        });

        Schema::connection('sakemaru')->table('wms_inventory_count_items', function (Blueprint $table) {
            if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_count_items', 'post_count_movement_quantity')) {
                $table->decimal('post_count_movement_quantity', 15, 3)->nullable()->after('system_quantity')->comment('棚卸実施後受払合計数量');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_inventory_count_items', function (Blueprint $table) {
            if (Schema::connection('sakemaru')->hasColumn('wms_inventory_count_items', 'post_count_movement_quantity')) {
                $table->dropColumn('post_count_movement_quantity');
            }
        });

        Schema::connection('sakemaru')->table('wms_inventory_counts', function (Blueprint $table) {
            foreach ([
                'stock_movement_calculated_at',
                'stock_movement_from_at',
            ] as $column) {
                if (Schema::connection('sakemaru')->hasColumn('wms_inventory_counts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
