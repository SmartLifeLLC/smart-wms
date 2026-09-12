<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_inventory_counts', function (Blueprint $table) {
            if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_counts', 'ending_stock_taken_at')) {
                $table->timestamp('ending_stock_taken_at')->nullable()->after('current_stock_saved_at')->comment('棚卸終了時理論在庫取得日時');
            }
        });

        Schema::connection('sakemaru')->table('wms_inventory_count_items', function (Blueprint $table) {
            if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_count_items', 'ending_system_quantity')) {
                $afterColumn = Schema::connection('sakemaru')->hasColumn('wms_inventory_count_items', 'post_count_movement_quantity')
                    ? 'post_count_movement_quantity'
                    : 'system_quantity';

                $table->decimal('ending_system_quantity', 15, 3)->nullable()->after($afterColumn)->comment('棚卸終了時理論在庫数量');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_inventory_count_items', function (Blueprint $table) {
            if (Schema::connection('sakemaru')->hasColumn('wms_inventory_count_items', 'ending_system_quantity')) {
                $table->dropColumn('ending_system_quantity');
            }
        });

        Schema::connection('sakemaru')->table('wms_inventory_counts', function (Blueprint $table) {
            if (Schema::connection('sakemaru')->hasColumn('wms_inventory_counts', 'ending_stock_taken_at')) {
                $table->dropColumn('ending_stock_taken_at');
            }
        });
    }
};
