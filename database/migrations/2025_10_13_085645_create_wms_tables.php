<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{

    protected $connection = 'sakemaru'; // Specify your connection name here

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('wms_real_stocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('real_stock_id')->index();
            $table->integer('reserved_quantity')->nullable(false)->default(0)->comment('WMS引当拘束（ピッキング未開始）');
            $table->integer('picking_quantity')->nullable(false)->default(0)->comment('WMSピッキング進行中拘束');
            $table->integer('lock_version')->nullable(false)->default(0)->comment('楽観ロック用バージョン');
            $table->timestamps();
        });

        // WMS Reservations - Stock allocation tracking
        Schema::create('wms_reservations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('real_stock_id')->nullable();
            $table->unsignedBigInteger('item_id');
            $table->date('expiry_date')->nullable();
            $table->dateTime('received_at')->nullable();
            $table->unsignedBigInteger('purchase_id')->nullable();
            $table->decimal('unit_cost', 12, 4)->nullable();
            $table->integer('qty_each');
            $table->enum('source_type', ['EARNING', 'PURCHASE', 'REPLENISH', 'COUNT', 'MOVE']);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->unsignedBigInteger('wave_id')->nullable();
            $table->enum('status', ['RESERVED', 'RELEASED', 'CONSUMED', 'CANCELLED'])->default('RESERVED');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->index(['warehouse_id', 'item_id', 'expiry_date', 'received_at', 'status'], 'idx_resv_main');
            $table->index(['source_type', 'source_line_id'], 'idx_resv_source');
        });

        // WMS Idempotency Keys - Prevent duplicate operations
        Schema::create('wms_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 64);
            $table->char('key_hash', 64);
            $table->timestamp('created_at')->nullable();

            $table->unique(['scope', 'key_hash'], 'uniq_scope_key');
        });

        // WMS Stock Allocations
        Schema::create('wms_stock_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->string('name');
            $table->timestamps();
        });


        // Create view for available stock with WMS tracking
        DB::connection('sakemaru')->statement("
                CREATE OR REPLACE VIEW wms_v_stock_available AS
                SELECT
                    rs.id AS real_stock_id,
                    rs.client_id,
                    rs.warehouse_id,
                    rs.stock_allocation_id,
                    rs.item_id,
                    rs.expiration_date,
                    rs.purchase_id,
                    rs.price AS unit_cost,
                    rs.current_quantity,
                    GREATEST(rs.available_quantity - COALESCE(wrs.reserved_quantity, 0) - COALESCE(wrs.picking_quantity, 0), 0) AS available_for_wms,
                    COALESCE(wrs.reserved_quantity, 0) AS wms_reserved_qty,
                    COALESCE(wrs.picking_quantity, 0) AS wms_picking_qty,
                    COALESCE(wrs.lock_version, 0) AS wms_lock_version,
                    rs.created_at
                FROM real_stocks rs
                LEFT JOIN wms_real_stocks wrs ON rs.id = wrs.real_stock_id
            ");


    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wms_real_stocks');
        // Drop view
        DB::connection('sakemaru')->statement('DROP VIEW IF EXISTS wms_v_stock_available');

        // Drop WMS tables only
        Schema::dropIfExists('wms_stock_allocations');
        Schema::dropIfExists('wms_idempotency_keys');
        Schema::dropIfExists('wms_reservations');
        Schema::dropIfExists('wms_real_stocks');

        // Note: clients, items, locations are NOT dropped as they are managed by core system
    }
};
