<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('wms_order_slip_number_assignments')) {
            return;
        }

        Schema::connection($this->connection)->create('wms_order_slip_number_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wms_order_jx_document_id')->nullable()->comment('JX発注ドキュメントID');
            $table->string('document_type', 32)->default('EOS_ORDER')->comment('採番用途');
            $table->char('slip_number', 11)->comment('旧EOS伝票番号');
            $table->char('store_code', 2)->comment('旧EOS店舗CD2桁');
            $table->unsignedTinyInteger('year_code')->comment('旧EOS年度コード');
            $table->unsignedInteger('sequence_no')->comment('店舗年度別連番');
            $table->unsignedInteger('b_record_sequence')->nullable()->comment('JXファイル内Bレコード順');
            $table->string('status', 20)->default('ACTIVE')->comment('ACTIVE, TRANSMITTED, CANCELLED');
            $table->json('order_candidate_ids')->nullable()->comment('同一Bレコード配下の発注候補ID');
            $table->timestamps();

            $table->unique('slip_number', 'wms_osna_slip_unique');
            $table->index(['wms_order_jx_document_id', 'status'], 'wms_osna_doc_status_idx');
            $table->index(['document_type', 'store_code', 'year_code', 'sequence_no'], 'wms_osna_type_store_year_seq_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_order_slip_number_assignments');
    }
};
