<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_inventory_counts', function (Blueprint $table) {
            if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_counts', 'current_stock_saved_at')) {
                $table->timestamp('current_stock_saved_at')->nullable()->after('started_at')->comment('現状保存日時');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_inventory_counts', function (Blueprint $table) {
            if (Schema::connection('sakemaru')->hasColumn('wms_inventory_counts', 'current_stock_saved_at')) {
                $table->dropColumn('current_stock_saved_at');
            }
        });
    }
};
