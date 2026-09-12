<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('wms_order_slip_number_sequences')) {
            return;
        }

        Schema::connection($this->connection)->create('wms_order_slip_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 32)->default('EOS_ORDER')->comment('採番用途');
            $table->unsignedBigInteger('warehouse_id')->nullable()->comment('対応倉庫ID');
            $table->char('store_code', 2)->comment('旧EOS店舗CD2桁');
            $table->unsignedTinyInteger('year_code')->comment('旧EOS年度コード');
            $table->unsignedInteger('current_sequence')->default(0)->comment('採番済み最大連番');
            $table->timestamps();

            $table->unique(['document_type', 'store_code', 'year_code'], 'wms_osns_type_store_year_unique');
            $table->index('warehouse_id', 'wms_osns_warehouse_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_order_slip_number_sequences');
    }
};
