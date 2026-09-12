<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('sakemaru')->hasTable('wms_incoming_app_inspection_batches')) {
            Schema::connection('sakemaru')->create('wms_incoming_app_inspection_batches', function (Blueprint $table) {
                $table->id();
                $table->string('client_batch_uuid', 80)->unique('uidx_wiaib_client_batch_uuid');
                $table->unsignedBigInteger('warehouse_id')->index('idx_wiaib_warehouse_id');
                $table->date('inspection_date')->index('idx_wiaib_inspection_date');
                $table->dateTime('inspected_at')->nullable();
                $table->unsignedBigInteger('inspected_by')->nullable()->index('idx_wiaib_inspected_by');
                $table->unsignedBigInteger('picker_id')->nullable()->index('idx_wiaib_picker_id');
                $table->string('device_id', 80)->nullable();
                $table->string('app_version', 40)->nullable();
                $table->string('status', 30)->default('RECEIVED')->index('idx_wiaib_status');
                $table->unsignedInteger('total_detail_count')->default(0);
                $table->unsignedInteger('success_count')->default(0);
                $table->unsignedInteger('history_only_count')->default(0);
                $table->unsignedInteger('review_count')->default(0);
                $table->unsignedInteger('error_count')->default(0);
                $table->string('payload_hash', 64)->nullable();
                $table->text('note')->nullable();
                $table->timestamps();

                $table->index(['warehouse_id', 'inspection_date'], 'idx_wiaib_wh_date');
            });
        }

        if (! Schema::connection('sakemaru')->hasTable('wms_incoming_app_inspection_details')) {
            Schema::connection('sakemaru')->create('wms_incoming_app_inspection_details', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('batch_id')->index('idx_wiaid_batch_id');
                $table->string('client_line_uuid', 80);
                $table->unsignedBigInteger('warehouse_id')->index('idx_wiaid_warehouse_id');
                $table->unsignedBigInteger('incoming_schedule_id')->nullable()->index('idx_wiaid_incoming_schedule_id');
                $table->unsignedBigInteger('linked_confirmed_schedule_id')->nullable()->index('idx_wiaid_linked_confirmed_schedule_id');
                $table->unsignedBigInteger('created_schedule_id')->nullable()->index('idx_wiaid_created_schedule_id');
                $table->unsignedBigInteger('item_id')->nullable()->index('idx_wiaid_item_id');
                $table->string('item_code', 32)->nullable()->index('idx_wiaid_item_code');
                $table->string('item_name', 255)->nullable();
                $table->string('scanned_code', 64)->nullable()->index('idx_wiaid_scanned_code');
                $table->string('slip_number', 32)->nullable()->index('idx_wiaid_slip_number');
                $table->unsignedBigInteger('contractor_id')->nullable()->index('idx_wiaid_contractor_id');
                $table->unsignedBigInteger('supplier_id')->nullable();
                $table->unsignedBigInteger('location_id')->nullable();
                $table->string('inspection_policy', 40)->default('NEEDS_REVIEW')->index('idx_wiaid_policy');
                $table->string('result_status', 40)->default('NEEDS_REVIEW')->index('idx_wiaid_result_status');
                $table->text('review_reason')->nullable();
                $table->integer('expected_piece_quantity')->nullable();
                $table->integer('inspected_case_quantity')->default(0);
                $table->integer('inspected_piece_quantity')->default(0);
                $table->integer('inspected_total_piece_quantity')->default(0);
                $table->integer('applied_piece_quantity')->default(0);
                $table->integer('shortage_piece_quantity')->default(0);
                $table->integer('capacity_case')->nullable();
                $table->date('expiration_date')->nullable();
                $table->dateTime('inspected_at')->nullable()->index('idx_wiaid_inspected_at');
                $table->json('raw_payload')->nullable();
                $table->timestamps();

                $table->unique(['batch_id', 'client_line_uuid'], 'uidx_wiaid_batch_line');
                $table->index(['warehouse_id', 'inspected_at', 'result_status'], 'idx_wiaid_wh_time_result');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_incoming_app_inspection_details');
        Schema::connection('sakemaru')->dropIfExists('wms_incoming_app_inspection_batches');
    }
};
