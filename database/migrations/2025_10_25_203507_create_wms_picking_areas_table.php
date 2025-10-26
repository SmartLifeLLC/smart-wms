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
     * Create wms_picking_areas table to manage different picking zones within a warehouse.
     * Different picking areas may require different pickers, so tasks must be separated by area.
     *
     * Examples of picking areas:
     * - 常温エリア (Ambient temperature area)
     * - 冷蔵エリア (Refrigerated area)
     * - 冷凍エリア (Frozen area)
     * - 危険物エリア (Hazardous materials area)
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->create('wms_picking_areas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('warehouse_id')->comment('倉庫ID');
            $table->string('code', 50)->comment('ピッキングエリアコード（常温/冷蔵/冷凍など）');
            $table->string('name', 100)->comment('ピッキングエリア名');
            $table->integer('display_order')->default(0)->comment('表示順序');
            $table->boolean('is_active')->default(true)->comment('有効フラグ');
            $table->timestamps();

            // Indexes
            $table->unique(['warehouse_id', 'code'], 'uniq_warehouse_code');
            $table->index('is_active', 'idx_is_active');
            $table->index('display_order', 'idx_display_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_picking_areas');
    }
};
