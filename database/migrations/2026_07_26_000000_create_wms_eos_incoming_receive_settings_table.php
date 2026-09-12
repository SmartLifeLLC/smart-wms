<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('wms_eos_incoming_receive_settings')) {
            return;
        }

        Schema::connection($this->connection)->create('wms_eos_incoming_receive_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('EOSデータ受信設定');
            $table->boolean('is_enabled')->default(false)->index('wms_eirs_enabled_idx');
            $table->unsignedTinyInteger('shortage_completion_days')->default(14);
            $table->string('exclude_purchase_warehouse_code', 16)->default('91');
            $table->string('unknown_slip_policy', 32)->default('REVIEW_ONLY');
            $table->timestamp('last_run_at')->nullable()->index('wms_eirs_last_run_idx');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_eos_incoming_receive_settings');
    }
};
