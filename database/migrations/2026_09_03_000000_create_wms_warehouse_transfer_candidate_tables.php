<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HANDY起点の倉庫移動候補（wms_warehouse_transfer_candidates 系）
 *
 * - 新規テーブル追加のみ。既存テーブルは変更しない。
 * - 外部キーは付けない（アプリケーション層で整合性を担保）。
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('sakemaru');

        if (! $schema->hasTable('wms_warehouse_transfer_candidates')) {
            $schema->create('wms_warehouse_transfer_candidates', function (Blueprint $table) {
                $table->id();
                $table->string('candidate_no', 32);
                $table->unsignedBigInteger('client_id');
                $table->string('source_type', 20)->default('HANDY');
                $table->unsignedBigInteger('from_warehouse_id');
                $table->string('from_warehouse_code', 32);
                $table->string('from_warehouse_name', 255)->nullable();
                $table->unsignedBigInteger('to_warehouse_id');
                $table->string('to_warehouse_code', 32);
                $table->string('to_warehouse_name', 255)->nullable();
                $table->unsignedBigInteger('delivery_course_id')->nullable();
                $table->date('process_date');
                $table->date('delivered_date');
                $table->string('status', 20)->default('PENDING');
                $table->unsignedBigInteger('submitted_by_picker_id')->nullable();
                $table->string('submitted_device_id', 100)->nullable();
                $table->dateTime('submitted_at')->nullable();
                $table->unsignedBigInteger('confirmed_by')->nullable();
                $table->dateTime('confirmed_at')->nullable();
                $table->unsignedBigInteger('cancelled_by')->nullable();
                $table->dateTime('cancelled_at')->nullable();
                $table->string('queue_request_id', 255)->nullable();
                $table->unsignedBigInteger('stock_transfer_queue_id')->nullable();
                $table->unsignedBigInteger('stock_transfer_id')->nullable();
                $table->text('queue_error_message')->nullable();
                $table->text('memo')->nullable();
                $table->timestamps();

                $table->unique('candidate_no', 'uniq_wms_wh_transfer_candidate_no');
                $table->unique('queue_request_id', 'uniq_wms_wh_transfer_queue_request');
                $table->index(['status', 'process_date', 'delivered_date'], 'idx_wms_wh_transfer_status_dates');
                $table->index(['from_warehouse_id', 'to_warehouse_id', 'status'], 'idx_wms_wh_transfer_from_to');
                $table->index(['stock_transfer_queue_id', 'stock_transfer_id'], 'idx_wms_wh_transfer_queue');
            });
        }

        if (! $schema->hasTable('wms_warehouse_transfer_candidate_items')) {
            $schema->create('wms_warehouse_transfer_candidate_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('candidate_id');
                $table->unsignedBigInteger('item_id');
                $table->string('item_code', 64);
                $table->string('item_name', 255);
                $table->string('barcode', 255)->nullable();
                $table->unsignedBigInteger('real_stock_id')->nullable();
                $table->unsignedBigInteger('location_id')->nullable();
                $table->string('location_no', 255)->nullable();
                $table->string('stock_allocation_code', 32)->default('1');
                $table->decimal('case_quantity', 12, 3)->default(0);
                $table->decimal('piece_quantity', 12, 3)->default(0);
                $table->integer('package_quantity')->default(1);
                $table->decimal('transfer_quantity', 12, 3)->default(0);
                $table->decimal('available_quantity_at_sync', 12, 3)->nullable();
                $table->decimal('available_quantity_at_confirm', 12, 3)->nullable();
                $table->string('scanned_code', 255)->nullable();
                $table->integer('source_line_count')->default(0);
                $table->string('line_note', 255)->nullable();
                $table->integer('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['candidate_id', 'item_id', 'stock_allocation_code'], 'uniq_wms_wh_transfer_item_merge');
                $table->index(['candidate_id', 'sort_order'], 'idx_wms_wh_transfer_item_candidate');
                $table->index(['item_id', 'stock_allocation_code'], 'idx_wms_wh_transfer_item_lookup');
                $table->index('item_code', 'idx_wms_wh_transfer_item_code');
            });
        }

        if (! $schema->hasTable('wms_warehouse_transfer_candidate_item_sources')) {
            $schema->create('wms_warehouse_transfer_candidate_item_sources', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('candidate_id');
                $table->unsignedBigInteger('candidate_item_id');
                $table->unsignedBigInteger('upload_id');
                $table->string('source_request_uuid', 255);
                $table->unsignedBigInteger('real_stock_id')->nullable();
                $table->decimal('case_quantity', 12, 3)->default(0);
                $table->decimal('piece_quantity', 12, 3)->default(0);
                $table->integer('package_quantity')->default(1);
                $table->decimal('transfer_quantity', 12, 3)->default(0);
                $table->string('scanned_code', 255)->nullable();
                $table->timestamps();

                $table->unique('source_request_uuid', 'uniq_wms_wh_transfer_source_uuid');
                $table->index('candidate_id', 'idx_wms_wh_transfer_source_candidate');
                $table->index('candidate_item_id', 'idx_wms_wh_transfer_source_item');
                $table->index('upload_id', 'idx_wms_wh_transfer_source_upload');
            });
        }

        if (! $schema->hasTable('wms_warehouse_transfer_candidate_uploads')) {
            $schema->create('wms_warehouse_transfer_candidate_uploads', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('candidate_id');
                $table->string('upload_uuid', 255);
                $table->string('device_id', 100)->nullable();
                $table->unsignedBigInteger('picker_id')->nullable();
                $table->integer('item_count')->default(0);
                $table->integer('accepted_count')->default(0);
                $table->json('missing_item_ids')->nullable();
                $table->json('response_payload')->nullable();
                $table->char('payload_hash', 64);
                $table->timestamps();

                $table->unique('upload_uuid', 'uniq_wms_wh_transfer_upload_uuid');
                $table->index('candidate_id', 'idx_wms_wh_transfer_upload_candidate');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('sakemaru');
        $schema->dropIfExists('wms_warehouse_transfer_candidate_uploads');
        $schema->dropIfExists('wms_warehouse_transfer_candidate_item_sources');
        $schema->dropIfExists('wms_warehouse_transfer_candidate_items');
        $schema->dropIfExists('wms_warehouse_transfer_candidates');
    }
};
