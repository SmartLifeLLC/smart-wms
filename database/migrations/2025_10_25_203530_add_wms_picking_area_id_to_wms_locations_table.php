<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * Run the migrations.
     *
     * Add wms_picking_area_id to wms_locations table.
     * This allows us to separate picking tasks by area when an order contains items
     * from different picking areas (which may require different pickers).
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_locations', function (Blueprint $table) {
            $table->unsignedBigInteger('wms_picking_area_id')
                ->nullable()
                ->after('location_id')
                ->comment('ピッキングエリアID（異なるエリアは異なるピッカーが必要）');

            $table->index('wms_picking_area_id', 'idx_picking_area_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_locations', function (Blueprint $table) {
            $table->dropIndex('idx_picking_area_id');
            $table->dropColumn('wms_picking_area_id');
        });
    }
};
