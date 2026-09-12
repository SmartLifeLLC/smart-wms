<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('wms_incoming_price_check_sources')) {
            return;
        }

        Schema::connection($this->connection)->create('wms_incoming_price_check_sources', function (Blueprint $table) {
            $table->id();
            $table->string('source_type', 32)->default('JX_EOS_RECEIVED');
            $table->string('source_schema_version', 32)->default('2026-07-22');
            $table->char('source_key', 64)->unique('wms_ipcs_source_key_unique');
            $table->char('source_document_key', 64)->nullable()->index('wms_ipcs_document_key_idx');
            $table->char('source_line_key', 64)->nullable()->index('wms_ipcs_line_key_idx');

            $table->unsignedBigInteger('received_file_id')->nullable()->index('wms_ipcs_received_file_idx');
            $table->unsignedBigInteger('received_slip_id')->nullable()->index('wms_ipcs_received_slip_idx');
            $table->unsignedBigInteger('received_detail_id')->nullable()->index('wms_ipcs_received_detail_idx');
            $table->unsignedBigInteger('incoming_schedule_id')->nullable()->index('wms_ipcs_schedule_idx');
            $table->unsignedBigInteger('order_candidate_id')->nullable()->index('wms_ipcs_order_candidate_idx');
            $table->unsignedBigInteger('wms_order_jx_document_id')->nullable()->index('wms_ipcs_order_jx_doc_idx');
            $table->unsignedBigInteger('wms_order_slip_number_assignment_id')->nullable()->index('wms_ipcs_slip_assign_idx');
            $table->unsignedBigInteger('wms_jx_transmission_log_id')->nullable()->index('wms_ipcs_jx_log_idx');
            $table->unsignedBigInteger('wms_jx_eos_import_batch_id')->nullable()->index('wms_ipcs_eos_batch_idx');
            $table->unsignedBigInteger('wms_jx_eos_line_id')->nullable()->index('wms_ipcs_eos_line_idx');

            $table->unsignedBigInteger('warehouse_id')->nullable()->index('wms_ipcs_warehouse_idx');
            $table->string('warehouse_code', 32)->nullable();
            $table->unsignedBigInteger('contractor_id')->nullable()->index('wms_ipcs_contractor_idx');
            $table->string('contractor_code', 32)->nullable()->index('wms_ipcs_contractor_code_idx');
            $table->string('contractor_name')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable()->index('wms_ipcs_supplier_idx');
            $table->unsignedBigInteger('item_id')->nullable()->index('wms_ipcs_item_idx');
            $table->string('item_code', 64)->nullable()->index('wms_ipcs_item_code_idx');
            $table->string('item_name')->nullable();
            $table->string('search_code', 64)->nullable();
            $table->string('received_jan_code', 64)->nullable()->index('wms_ipcs_received_jan_idx');
            $table->string('received_item_code', 64)->nullable();

            $table->string('slip_number', 64)->nullable()->index('wms_ipcs_slip_number_idx');
            $table->string('schedule_slip_number', 64)->nullable();
            $table->unsignedInteger('line_number')->nullable();
            $table->date('order_date')->nullable()->index('wms_ipcs_order_date_idx');
            $table->date('expected_arrival_date')->nullable()->index('wms_ipcs_expected_arrival_idx');
            $table->date('received_delivery_date')->nullable()->index('wms_ipcs_received_delivery_idx');
            $table->timestamp('recorded_at')->nullable()->index('wms_ipcs_recorded_at_idx');

            $table->string('match_status', 32)->nullable()->index('wms_ipcs_match_status_idx');
            $table->string('schedule_status', 32)->nullable();
            $table->string('quantity_type', 16)->nullable();
            $table->decimal('expected_quantity', 20, 4)->nullable();
            $table->decimal('received_total_quantity', 20, 4)->nullable();
            $table->decimal('received_pack_quantity', 20, 4)->nullable();
            $table->decimal('received_case_quantity', 20, 4)->nullable();
            $table->decimal('received_piece_quantity', 20, 4)->nullable();
            $table->decimal('shipped_quantity', 20, 4)->nullable();
            $table->decimal('shortage_quantity', 20, 4)->nullable();

            $table->string('sent_price_type', 16)->nullable();
            $table->unsignedBigInteger('sent_unit_price_raw')->nullable();
            $table->decimal('sent_unit_price', 20, 4)->nullable();
            $table->decimal('sent_candidate_unit_price', 20, 4)->nullable();
            $table->decimal('master_unit_price', 20, 4)->nullable();
            $table->decimal('master_case_price', 20, 4)->nullable();
            $table->unsignedBigInteger('received_unit_price_raw')->nullable();
            $table->decimal('received_unit_price', 20, 4)->nullable();
            $table->decimal('received_amount', 20, 4)->nullable();
            $table->string('comparison_price_type', 16)->nullable();
            $table->decimal('comparison_master_price', 20, 4)->nullable();
            $table->decimal('comparison_received_price', 20, 4)->nullable();
            $table->decimal('comparison_price_diff', 20, 4)->nullable();
            $table->boolean('current_price_mismatch')->default(false)->index('wms_ipcs_price_mismatch_idx');
            $table->boolean('is_price_check_excluded')->default(false)->index('wms_ipcs_price_excluded_idx');
            $table->string('price_check_excluded_reason', 64)->nullable();

            $table->json('received_payload')->nullable();
            $table->json('schedule_payload')->nullable();
            $table->json('sent_payload')->nullable();
            $table->json('eos_payload')->nullable();
            $table->json('calculation_payload')->nullable();
            $table->timestamps();

            $table->index(['recorded_at', 'current_price_mismatch'], 'wms_ipcs_daily_mismatch_idx');
            $table->index(['contractor_id', 'item_id'], 'wms_ipcs_contractor_item_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_incoming_price_check_sources');
    }
};
