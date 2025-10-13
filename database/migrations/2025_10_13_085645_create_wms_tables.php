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
        Schema::table('real_stocks', function (Blueprint $table) {
            $table->integer('wms_reserved_qty')->nullable(false)->default(0)->comment('WMS引当拘束（ピッキング未開始）')->after('available_quantity');
            $table->integer('wms_picking_qty')->nullable(false)->default(0)->comment('WMSピッキング進行中拘束')->after('wms_reserved_qty');
            $table->integer('wms_lock_version')->nullable(false)->default(0)->comment('楽観ロック用バージョン')->after('updated_at')->index();
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

        // Add WMS columns to existing real_stocks table
        if (Schema::hasTable('real_stocks')) {
            Schema::table('real_stocks', function (Blueprint $table) {
                if (!Schema::hasColumn('real_stocks', 'wms_reserved_qty')) {
                    $table->integer('wms_reserved_qty')->default(0);
                }
                if (!Schema::hasColumn('real_stocks', 'wms_picking_qty')) {
                    $table->integer('wms_picking_qty')->default(0);
                }
                if (!Schema::hasColumn('real_stocks', 'wms_lock_version')) {
                    $table->integer('wms_lock_version')->default(0);
                }
            });
        }

        // Create view for available stock (only if real_stocks exists)
        if (Schema::hasTable('real_stocks')) {
            DB::statement("
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
                    GREATEST(rs.available_quantity - (rs.wms_reserved_qty + rs.wms_picking_qty), 0) AS available_for_wms,
                    rs.wms_reserved_qty,
                    rs.wms_picking_qty
                FROM real_stocks rs
            ");
        }

        // Note: clients, items, locations tables are managed by core system (sakemaru)
        // These tables should already exist in the database
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('real_stocks', function (Blueprint $table) {
            $table->dropColumn(['wms_reserved_qty', 'wms_picking_qty', 'wms_lock_version']);
        });
        // Drop view
        DB::statement('DROP VIEW IF EXISTS wms_v_stock_available');

        // Remove WMS columns from real_stocks if exists
        if (Schema::hasTable('real_stocks')) {
            Schema::table('real_stocks', function (Blueprint $table) {
                if (Schema::hasColumn('real_stocks', 'wms_reserved_qty')) {
                    $table->dropColumn('wms_reserved_qty');
                }
                if (Schema::hasColumn('real_stocks', 'wms_picking_qty')) {
                    $table->dropColumn('wms_picking_qty');
                }
                if (Schema::hasColumn('real_stocks', 'wms_lock_version')) {
                    $table->dropColumn('wms_lock_version');
                }
            });
        }

        // Drop WMS tables only
        Schema::dropIfExists('wms_stock_allocations');
        Schema::dropIfExists('wms_idempotency_keys');
        Schema::dropIfExists('wms_reservations');

        // Note: clients, items, locations are NOT dropped as they are managed by core system
    }
};
