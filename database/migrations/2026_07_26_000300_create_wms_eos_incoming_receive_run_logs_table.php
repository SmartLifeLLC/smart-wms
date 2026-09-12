<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('wms_eos_incoming_receive_run_logs')) {
            return;
        }

        Schema::connection($this->connection)->create('wms_eos_incoming_receive_run_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('run_id')->index('wms_eirrl_run_idx');
            $table->string('level', 16)->default('info')->index('wms_eirrl_level_idx');
            $table->string('step', 64)->index('wms_eirrl_step_idx');
            $table->text('message');
            $table->unsignedBigInteger('jx_transmission_log_id')->nullable()->index('wms_eirrl_jx_log_idx');
            $table->unsignedBigInteger('incoming_received_file_id')->nullable()->index('wms_eirrl_file_idx');
            $table->unsignedBigInteger('incoming_schedule_id')->nullable()->index('wms_eirrl_schedule_idx');
            $table->unsignedBigInteger('purchase_queue_id')->nullable()->index('wms_eirrl_queue_idx');
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['run_id', 'step', 'level'], 'wms_eirrl_run_step_level_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_eos_incoming_receive_run_logs');
    }
};
