<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sakemaru')->create('wms_jx_eos_import_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wms_jx_transmission_log_id')->index();
            $table->unsignedBigInteger('jx_setting_id')->nullable()->index();
            $table->unsignedInteger('import_version')->default(1);
            $table->string('importer_version', 32)->default('2026-06-08');
            $table->string('status', 32)->default('importing')->index();
            $table->boolean('is_current')->default(false)->index();
            $table->string('source_disk', 32)->default('s3');
            $table->string('source_file_path', 512)->nullable();
            $table->string('source_message_id', 100)->nullable()->index();
            $table->string('source_document_type', 10)->nullable()->index();
            $table->string('finet_code', 16)->nullable()->index();
            $table->unsignedBigInteger('detected_jx_setting_id')->nullable()->index();
            $table->unsignedBigInteger('detected_contractor_id')->nullable()->index();
            $table->string('detected_contractor_code', 32)->nullable()->index();
            $table->string('file_sha256', 64)->nullable()->index();
            $table->unsignedInteger('file_size')->default(0);
            $table->unsignedInteger('record_count')->default(0);
            $table->unsignedInteger('wrapper_record_count')->nullable();
            $table->unsignedInteger('document_count')->default(0);
            $table->unsignedInteger('slip_count')->default(0);
            $table->unsignedInteger('line_count')->default(0);
            $table->unsignedBigInteger('imported_by')->nullable()->index();
            $table->string('imported_by_name')->nullable();
            $table->timestamp('imported_at')->nullable()->index();
            $table->timestamp('superseded_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('stats_json')->nullable();
            $table->timestamps();

            $table->unique(['wms_jx_transmission_log_id', 'import_version'], 'uniq_wms_jx_eos_batch_version');
            $table->index(['wms_jx_transmission_log_id', 'is_current', 'status'], 'idx_wms_jx_eos_batch_current');
            $table->index(['file_sha256', 'status'], 'idx_wms_jx_eos_batch_file_status');
        });

        Schema::connection('sakemaru')->create('wms_jx_eos_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('import_batch_id')->index();
            $table->unsignedBigInteger('wms_jx_transmission_log_id')->index();
            $table->unsignedInteger('source_record_no');
            $table->unsignedInteger('document_sequence');
            $table->string('data_type', 10)->nullable();
            $table->date('processing_date')->nullable()->index();
            $table->string('processing_time', 16)->nullable();
            $table->string('sender_code', 32)->nullable();
            $table->string('receiver_code', 32)->nullable();
            $table->unsignedInteger('declared_record_count')->nullable();
            $table->unsignedInteger('declared_slip_count')->nullable();
            $table->string('company_name')->nullable();
            $table->string('raw_record_hash', 64)->index();
            $table->longText('raw_record_base64')->nullable();
            $table->timestamps();

            $table->unique(['import_batch_id', 'source_record_no'], 'uniq_wms_jx_eos_doc_batch_record');
            $table->unique(['import_batch_id', 'document_sequence'], 'uniq_wms_jx_eos_doc_batch_seq');
        });

        Schema::connection('sakemaru')->create('wms_jx_eos_slips', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('import_batch_id')->index();
            $table->unsignedBigInteger('document_id')->nullable()->index();
            $table->unsignedBigInteger('wms_jx_transmission_log_id')->index();
            $table->unsignedInteger('source_record_no');
            $table->unsignedInteger('slip_sequence');
            $table->string('slip_number', 32)->nullable()->index();
            $table->string('data_type', 10)->nullable();
            $table->string('shop_code', 32)->nullable()->index();
            $table->string('category_code', 32)->nullable();
            $table->string('slip_type', 32)->nullable();
            $table->date('order_date')->nullable()->index();
            $table->date('delivery_date')->nullable()->index();
            $table->string('delivery_route', 32)->nullable();
            $table->string('contractor_code', 32)->nullable()->index();
            $table->string('shop_name')->nullable();
            $table->string('delivery_place')->nullable();
            $table->text('note')->nullable();
            $table->string('direct_type', 32)->nullable();
            $table->unsignedInteger('detail_count')->default(0);
            $table->string('raw_record_hash', 64)->index();
            $table->longText('raw_record_base64')->nullable();
            $table->timestamps();

            $table->unique(['import_batch_id', 'source_record_no'], 'uniq_wms_jx_eos_slip_batch_record');
            $table->index(['import_batch_id', 'slip_number'], 'idx_wms_jx_eos_slip_batch_slip');
        });

        Schema::connection('sakemaru')->create('wms_jx_eos_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('import_batch_id')->index();
            $table->unsignedBigInteger('document_id')->nullable()->index();
            $table->unsignedBigInteger('slip_id')->nullable()->index();
            $table->unsignedBigInteger('wms_jx_transmission_log_id')->index();
            $table->unsignedInteger('source_record_no');
            $table->unsignedInteger('line_sequence');
            $table->unsignedInteger('line_number')->nullable();
            $table->string('data_type', 10)->nullable();
            $table->string('product_name')->nullable();
            $table->string('jan_code', 32)->nullable()->index();
            $table->string('item_code', 32)->nullable()->index();
            $table->unsignedInteger('pack_quantity')->default(0);
            $table->unsignedInteger('case_quantity')->default(0);
            $table->unsignedInteger('piece_quantity')->default(0);
            $table->unsignedInteger('total_quantity')->default(0);
            $table->unsignedBigInteger('unit_price_raw')->default(0);
            $table->decimal('unit_price', 20, 2)->default(0);
            $table->decimal('amount', 20, 2)->default(0);
            $table->boolean('is_shortage')->default(false)->index();
            $table->string('line_hash', 64)->index();
            $table->string('raw_record_hash', 64)->index();
            $table->longText('raw_record_base64')->nullable();
            $table->timestamps();

            $table->unique(['import_batch_id', 'source_record_no'], 'uniq_wms_jx_eos_line_batch_record');
            $table->unique(['import_batch_id', 'line_hash'], 'uniq_wms_jx_eos_line_batch_hash');
            $table->index(['import_batch_id', 'jan_code'], 'idx_wms_jx_eos_line_batch_jan');
            $table->index(['slip_id', 'line_number'], 'idx_wms_jx_eos_line_slip_line');
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_jx_eos_lines');
        Schema::connection('sakemaru')->dropIfExists('wms_jx_eos_slips');
        Schema::connection('sakemaru')->dropIfExists('wms_jx_eos_documents');
        Schema::connection('sakemaru')->dropIfExists('wms_jx_eos_import_batches');
    }
};
