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
        // WMS Users - 入・出荷作業者管理
        Schema::create('wms_users', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->comment('作業者コード');
            $table->string('name')->comment('作業者名');
            $table->string('password')->comment('パスワード（暗号化）');
            $table->unsignedBigInteger('default_warehouse_id')->nullable()->comment('デフォルト倉庫ID');
            $table->boolean('is_active')->default(true)->comment('有効フラグ');
            $table->timestamps();
        });

        // WMS Waves - 波動ピッキング管理
        Schema::create('wms_waves', function (Blueprint $table) {
            $table->id();
            $table->string('wave_no')->comment('波動番号 例: 20251013-A01');
            $table->unsignedBigInteger('warehouse_id')->comment('倉庫ID');
            $table->time('start_time')->nullable()->comment('ピッキング開始時刻');
            $table->time('end_time')->nullable()->comment('積込締切時刻');
            $table->string('route_code')->nullable()->comment('ルートコード');
            $table->enum('status', ['planned', 'picking', 'completed'])->default('planned')->comment('ステータス');
            $table->timestamps();

            $table->unique(['warehouse_id', 'wave_no']);
        });

        // WMS Wave Shipments - 波動と出荷伝票の紐付け
        Schema::create('wms_wave_shipments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wave_id')->comment('波動ID');
            $table->unsignedBigInteger('earning_id')->comment('売上伝票ID (earnings.id)');
            $table->timestamps();

            $table->unique(['wave_id', 'earning_id']);
            $table->index('wave_id');
        });

        // WMS Picking Tasks - ピッキングタスク
        Schema::create('wms_picking_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wave_id')->comment('波動ID');
            $table->unsignedBigInteger('item_id')->comment('商品ID');
            $table->unsignedBigInteger('location_id')->nullable()->comment('ロケーションID');
            $table->integer('total_qty')->comment('トータル数量');
            $table->unsignedBigInteger('assigned_worker_id')->nullable()->comment('担当作業者ID');
            $table->enum('status', ['pending', 'in_progress', 'completed'])->default('pending')->comment('ステータス');
            $table->timestamps();

            $table->index(['wave_id', 'status']);
        });

        // WMS Receipt Inspections - 入荷検品
        Schema::create('wms_receipt_inspections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_id')->comment('仕入伝票ID (purchases.id)');
            $table->string('inspection_no')->unique()->comment('検品番号');
            $table->unsignedBigInteger('warehouse_id')->comment('倉庫ID');
            $table->enum('status', ['PENDING', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'])->default('PENDING')->comment('ステータス');
            $table->unsignedBigInteger('inspected_by')->nullable()->comment('検品者ID (wms_users.id)');
            $table->dateTime('inspected_at')->nullable()->comment('検品日時');
            $table->text('notes')->nullable()->comment('備考');
            $table->timestamps();

            $table->index(['purchase_id', 'warehouse_id']);
        });

        // WMS Receipt Inspection Lines - 入荷検品明細
        Schema::create('wms_receipt_inspection_lines', function (Blueprint $table) {
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
        Schema::create('wms_shipment_inspections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('earning_id')->comment('売上伝票ID (earnings.id)');
            $table->unsignedBigInteger('warehouse_id')->comment('倉庫ID');
            $table->date('inspection_date')->comment('検品日');
            $table->unsignedBigInteger('inspector_id')->nullable()->comment('検品者ID (wms_users.id)');
            $table->enum('status', ['pending', 'in_progress', 'completed', 'partial'])->default('pending')->comment('ステータス');
            $table->text('notes')->nullable()->comment('備考');
            $table->timestamps();

            $table->index(['earning_id', 'warehouse_id']);
        });

        // WMS Shipment Inspection Lines - 出荷検品明細
        Schema::create('wms_shipment_inspection_lines', function (Blueprint $table) {
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
        Schema::dropIfExists('wms_shipment_inspection_lines');
        Schema::dropIfExists('wms_shipment_inspections');
        Schema::dropIfExists('wms_receipt_inspection_lines');
        Schema::dropIfExists('wms_receipt_inspections');
        Schema::dropIfExists('wms_picking_tasks');
        Schema::dropIfExists('wms_wave_shipments');
        Schema::dropIfExists('wms_waves');
        Schema::dropIfExists('wms_users');
    }
};
