<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasTable('wms_inventory_count_theory_update_runs')) {
            Schema::connection($this->connection)->create('wms_inventory_count_theory_update_runs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('inventory_count_id')->index('wms_ictur_count_idx');
                $table->unsignedBigInteger('client_id')->index('wms_ictur_client_idx');
                $table->unsignedBigInteger('warehouse_id')->index('wms_ictur_warehouse_idx');
                $table->date('end_date')->index('wms_ictur_end_date_idx');
                $table->string('update_type', 32)->default('ending_ledger')->index('wms_ictur_type_idx');
                $table->string('status', 32)->default('running')->index('wms_ictur_status_idx');
                $table->unsignedBigInteger('executed_by')->nullable()->index('wms_ictur_executed_by_idx');
                $table->timestamp('started_at')->nullable()->index('wms_ictur_started_idx');
                $table->timestamp('finished_at')->nullable()->index('wms_ictur_finished_idx');
                $table->timestamp('ending_stock_taken_at_before')->nullable();
                $table->timestamp('ending_stock_taken_at_after')->nullable();
                $table->unsignedInteger('calculated_item_count')->default(0);
                $table->unsignedInteger('updated_items')->default(0);
                $table->unsignedInteger('inserted_items')->default(0);
                $table->unsignedInteger('skipped_items')->default(0);
                $table->unsignedInteger('backed_up_existing_rows')->default(0);
                $table->unsignedInteger('backed_up_inserted_rows')->default(0);
                $table->decimal('calculation_seconds', 10, 3)->nullable();
                $table->decimal('update_seconds', 10, 3)->nullable();
                $table->text('error_message')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['inventory_count_id', 'created_at'], 'wms_ictur_count_created_idx');
                $table->index(['warehouse_id', 'end_date'], 'wms_ictur_wh_end_idx');
            });
        }

        if (! Schema::connection($this->connection)->hasTable('wms_inventory_count_theory_update_rows')) {
            Schema::connection($this->connection)->create('wms_inventory_count_theory_update_rows', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('run_id')->index('wms_icturow_run_idx');
                $table->unsignedBigInteger('inventory_count_id')->index('wms_icturow_count_idx');
                $table->unsignedBigInteger('inventory_count_item_id')->nullable()->index('wms_icturow_item_row_idx');
                $table->boolean('was_existing')->default(true)->index('wms_icturow_existing_idx');
                $table->unsignedBigInteger('item_id')->nullable()->index('wms_icturow_item_idx');
                $table->unsignedBigInteger('real_stock_id')->nullable()->index('wms_icturow_real_stock_idx');
                $table->decimal('old_ending_system_quantity', 15, 3)->nullable();
                $table->decimal('new_ending_system_quantity', 15, 3)->nullable();
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->timestamps();

                $table->index(['run_id', 'inventory_count_item_id'], 'wms_icturow_run_item_row_idx');
                $table->index(['inventory_count_id', 'item_id'], 'wms_icturow_count_item_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_inventory_count_theory_update_rows');
        Schema::connection($this->connection)->dropIfExists('wms_inventory_count_theory_update_runs');
    }
};
