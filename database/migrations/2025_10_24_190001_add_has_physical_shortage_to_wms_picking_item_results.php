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
        // Add generated column to detect physical shortage (warehouse discrepancy)
        // This column automatically becomes TRUE when picked_qty != planned_qty
        // indicating that physical stock differs from system allocation
        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_picking_item_results
            ADD COLUMN has_physical_shortage BOOLEAN
            GENERATED ALWAYS AS (planned_qty != picked_qty) STORED
            COMMENT '倉庫内実在庫相違フラグ（planned_qty ≠ picked_qty の場合 TRUE）'
            AFTER shortage_qty
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_picking_item_results', function (Blueprint $table) {
            $table->dropColumn('has_physical_shortage');
        });
    }
};
