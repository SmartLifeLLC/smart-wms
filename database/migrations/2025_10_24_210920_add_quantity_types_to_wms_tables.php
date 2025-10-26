<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add quantity types to wms_picking_item_results
        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_picking_item_results
            ADD COLUMN ordered_qty_type ENUM('CASE', 'PIECE', 'CARTON') NOT NULL DEFAULT 'PIECE'
                COMMENT '発注数量区分（trade_items.order_quantity_type）' AFTER ordered_qty,
            ADD COLUMN planned_qty_type ENUM('CASE', 'PIECE', 'CARTON') NOT NULL DEFAULT 'PIECE'
                COMMENT '引当数量区分' AFTER planned_qty,
            ADD COLUMN picked_qty_type ENUM('CASE', 'PIECE', 'CARTON') NOT NULL DEFAULT 'PIECE'
                COMMENT '実績数量区分' AFTER picked_qty
        ");

        // Add quantity type to wms_reservations
        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_reservations
            ADD COLUMN qty_type ENUM('CASE', 'PIECE', 'CARTON') NOT NULL DEFAULT 'PIECE'
                COMMENT '数量区分' AFTER qty_each
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove quantity types from wms_picking_item_results
        Schema::connection('sakemaru')->table('wms_picking_item_results', function (Blueprint $table) {
            $table->dropColumn(['ordered_qty_type', 'planned_qty_type', 'picked_qty_type']);
        });

        // Remove quantity type from wms_reservations
        Schema::connection('sakemaru')->table('wms_reservations', function (Blueprint $table) {
            $table->dropColumn('qty_type');
        });
    }
};
