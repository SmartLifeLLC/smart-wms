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
     * Add wms_picking_area_id to wms_picking_tasks table.
     *
     * IMPORTANT: When an order (earning/trade) contains items from multiple picking areas,
     * the system must create SEPARATE picking tasks for each area because:
     * 1. Different areas may require different pickers
     * 2. Pickers are assigned to specific areas (e.g., frozen area picker vs ambient area picker)
     * 3. This allows parallel picking operations across different areas
     *
     * Example:
     * - Order #123 has 5 items: 3 from 常温エリア, 2 from 冷凍エリア
     * - System creates 2 picking tasks:
     *   Task A: Order #123, 常温エリア, 3 items
     *   Task B: Order #123, 冷凍エリア, 2 items
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_picking_tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('wms_picking_area_id')
                ->nullable()
                ->after('wave_id')
                ->comment('ピッキングエリアID（同じ伝票でもエリアごとにタスク分割）');

            $table->index('wms_picking_area_id', 'idx_picking_area_id');
            // Composite index for queries filtering by wave and area
            $table->index(['wave_id', 'wms_picking_area_id', 'status'], 'idx_wave_area_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_picking_tasks', function (Blueprint $table) {
            $table->dropIndex('idx_wave_area_status');
            $table->dropIndex('idx_picking_area_id');
            $table->dropColumn('wms_picking_area_id');
        });
    }
};
