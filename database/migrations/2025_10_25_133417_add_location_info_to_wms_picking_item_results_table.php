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
     * Add location information to wms_picking_item_results for efficient picking list generation.
     * This allows sorting by zone_code -> walking_order -> item_id while maintaining earning traceability.
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_picking_item_results', function (Blueprint $table) {
            // Add location_id for reference
            $table->unsignedBigInteger('location_id')
                ->nullable()
                ->after('real_stock_id')
                ->comment('ピッキング元ロケーションID');

            // Add denormalized location attributes for fast picking list sorting
            $table->string('zone_code', 50)
                ->nullable()
                ->after('location_id')
                ->comment('温度帯・エリア区分（wms_locations.zone_codeから）');

            $table->integer('walking_order')
                ->nullable()
                ->after('zone_code')
                ->comment('倉庫内動線順序（wms_locations.walking_orderから）');

            // Add indexes for picking list queries
            $table->index(['zone_code', 'walking_order', 'item_id'], 'idx_picking_list_sort');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_picking_item_results', function (Blueprint $table) {
            $table->dropIndex('idx_picking_list_sort');
            $table->dropColumn(['location_id', 'zone_code', 'walking_order']);
        });
    }
};
