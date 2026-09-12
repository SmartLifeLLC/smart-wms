<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_inventory_counts', function (Blueprint $table) {
            $table->boolean('handy_reception')->default(false)->after('lock_mode');
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_inventory_counts', function (Blueprint $table) {
            $table->dropColumn('handy_reception');
        });
    }
};
