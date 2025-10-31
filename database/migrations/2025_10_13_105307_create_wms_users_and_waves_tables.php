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
        // WMS Pickers - ピッキング作業者管理
        Schema::connection('sakemaru')->create('wms_pickers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->comment('作業者コード');
            $table->string('name')->comment('作業者名');
            $table->string('password')->comment('パスワード（暗号化）');
            $table->unsignedBigInteger('default_warehouse_id')->nullable()->comment('デフォルト倉庫ID');
            $table->boolean('is_active')->default(true)->comment('有効フラグ');
            $table->timestamps();
        });

        // NOTE: wms_waves table is now created in 2025_10_23_040000_create_wms_waves_table.php
        // This old wave implementation is removed in favor of the new wave generation system

        // WMS Picking Tasks - ピッキングタスク (伝票単位)
        Schema::connection('sakemaru')->create('wms_picking_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wave_id')->comment('波動ID');
            $table->unsignedBigInteger('warehouse_id')->comment('倉庫ID');
            $table->unsignedBigInteger('earning_id')->comment('対応伝票 (earnings.id)');
            $table->unsignedBigInteger('trade_id')->comment('取引ID (trades.id)');
            $table->enum('status', ['PENDING', 'PICKING', 'SHORTAGE', 'COMPLETED'])->default('PENDING')->comment('ステータス');
            $table->enum('task_type', ['WAVE', 'REALLOCATION'])->default('WAVE')->comment('タスク種別');
            $table->unsignedBigInteger('picker_id')->nullable()->comment('担当ピッカー (wms_pickers.id)');
            $table->timestamps();

            $table->index(['wave_id', 'status']);
            $table->index('earning_id');
        });

        // WMS Picking Item Results - ピッキング実績 (商品単位)
        Schema::connection('sakemaru')->create('wms_picking_item_results', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('picking_task_id')->comment('ピッキングタスクID');
            $table->unsignedBigInteger('trade_item_id')->comment('商品明細ID (trade_items.id)');
            $table->unsignedBigInteger('item_id')->comment('商品ID (items.id)');
            $table->unsignedBigInteger('real_stock_id')->nullable()->comment('実在庫ID (real_stocks.id)');
            $table->integer('planned_qty')->comment('指示数量');
            $table->integer('picked_qty')->default(0)->comment('実績数量');
            $table->integer('shortage_qty')->default(0)->comment('欠品数量');
            $table->enum('status', ['PICKING', 'COMPLETED', 'SHORTAGE'])->default('PICKING')->comment('ステータス');
            $table->unsignedBigInteger('picker_id')->nullable()->comment('ピッカー');
            $table->timestamps();

            $table->index('picking_task_id');
            $table->index('trade_item_id');
        });

        // WMS Receipt Inspections - 入荷検品
        Schema::connection('sakemaru')->create('wms_receipt_inspections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_id')->comment('仕入伝票ID (purchases.id)');
            $table->string('inspection_no')->unique()->comment('検品番号');
            $table->unsignedBigInteger('warehouse_id')->comment('倉庫ID');
            $table->enum('status', ['PENDING', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'])->default('PENDING')->comment('ステータス');
            $table->unsignedBigInteger('inspected_by')->nullable()->comment('検品者ID (wms_pickers.id)');
            $table->dateTime('inspected_at')->nullable()->comment('検品日時');
            $table->text('notes')->nullable()->comment('備考');
            $table->timestamps();

            $table->index(['purchase_id', 'warehouse_id']);
        });

        // WMS Receipt Inspection Lines - 入荷検品明細
        Schema::connection('sakemaru')->create('wms_receipt_inspection_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('inspection_id')->comment('検品ID');
            $table->unsignedBigInteger('purchase_line_id')->comment('仕入明細ID (purchase_lines.id)');
            $table->unsignedBigInteger('item_id')->comment('商品ID');
            $table->integer('expected_qty')->comment('予定数量');
            $table->integer('actual_qty')->comment('実績数量');
            $table->integer('shortage_qty')->default(0)->comment('欠品数量');
            $table->integer('return_qty')->default(0)->comment('返品数量');
            $table->string('lot_no')->nullable()->comment('ロット番号');
            $table->date('expiration_date')->nullable()->comment('賞味期限');
            $table->text('notes')->nullable()->comment('備考');
            $table->timestamps();

            $table->index('inspection_id');
        });

        // WMS Shipment Inspections - 出荷検品
        Schema::connection('sakemaru')->create('wms_shipment_inspections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('earning_id')->comment('売上伝票ID (earnings.id)');
            $table->unsignedBigInteger('warehouse_id')->comment('倉庫ID');
            $table->date('inspection_date')->comment('検品日');
            $table->unsignedBigInteger('inspector_id')->nullable()->comment('検品者ID (wms_pickers.id)');
            $table->enum('status', ['pending', 'in_progress', 'completed', 'partial'])->default('pending')->comment('ステータス');
            $table->text('notes')->nullable()->comment('備考');
            $table->timestamps();

            $table->index(['earning_id', 'warehouse_id']);
        });

        // WMS Shipment Inspection Lines - 出荷検品明細
        Schema::connection('sakemaru')->create('wms_shipment_inspection_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('inspection_id')->comment('検品ID');
            $table->unsignedBigInteger('earning_line_id')->comment('売上明細ID (earning_lines.id)');
            $table->unsignedBigInteger('item_id')->comment('商品ID');
            $table->integer('expected_qty')->comment('予定数量');
            $table->integer('actual_qty')->comment('実績数量');
            $table->integer('shortage_qty')->default(0)->comment('欠品数量');
            $table->text('notes')->nullable()->comment('備考');
            $table->timestamps();

            $table->index('inspection_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_shipment_inspection_lines');
        Schema::connection('sakemaru')->dropIfExists('wms_shipment_inspections');
        Schema::connection('sakemaru')->dropIfExists('wms_receipt_inspection_lines');
        Schema::connection('sakemaru')->dropIfExists('wms_receipt_inspections');
        Schema::connection('sakemaru')->dropIfExists('wms_picking_item_results');
        Schema::connection('sakemaru')->dropIfExists('wms_picking_tasks');
        // wms_waves is dropped in 2025_10_23_040000_create_wms_waves_table.php
        Schema::connection('sakemaru')->dropIfExists('wms_pickers');
    }
};
