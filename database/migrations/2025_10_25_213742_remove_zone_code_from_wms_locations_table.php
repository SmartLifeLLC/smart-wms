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
     * Remove zone_code from wms_locations table.
     * Zone information is now managed through wms_picking_areas,
     * so zone_code is redundant.
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_locations', function (Blueprint $table) {
            $table->dropIndex('idx_zone_code');
            $table->dropColumn('zone_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_locations', function (Blueprint $table) {
            $table->string('zone_code', 50)
                ->nullable()
                ->after('walking_order')
                ->comment('温度帯・エリア区分（常温／冷蔵／冷凍など）');

            $table->index('zone_code', 'idx_zone_code');
        });
    }
};
