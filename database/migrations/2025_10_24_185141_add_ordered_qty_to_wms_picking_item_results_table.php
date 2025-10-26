<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_picking_item_results', function (Blueprint $table) {
            // Add ordered_qty before planned_qty
            // ordered_qty = original quantity from trade_items (発注数量)
            // planned_qty = allocated quantity from reservations (引当済み数量)
            // picked_qty = actual picked quantity (実ピッキング数量) - set by picker
            // shortage_qty = shortage quantity (欠品数量) - set by picker
            $table->integer('ordered_qty')->after('real_stock_id')->nullable(false)->default(0)
                ->comment('元の発注数量（trade_items.quantity）');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_picking_item_results', function (Blueprint $table) {
            $table->dropColumn('ordered_qty');
        });
    }
};
