<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('wms_eos_incoming_receive_runs')) {
            return;
        }

        Schema::connection($this->connection)->create('wms_eos_incoming_receive_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_key', 128)->unique('wms_eirr_run_key_unique');
            $table->unsignedBigInteger('setting_id')->nullable()->index('wms_eirr_setting_idx');
            $table->unsignedBigInteger('schedule_id')->nullable()->index('wms_eirr_schedule_idx');
            $table->date('execution_date')->index('wms_eirr_execution_date_idx');
            $table->time('scheduled_time')->nullable();
            $table->string('trigger_type', 32)->default('scheduled')->index('wms_eirr_trigger_idx');
            $table->string('status', 32)->default('QUEUED')->index('wms_eirr_status_idx');
            $table->timestamp('started_at')->nullable()->index('wms_eirr_started_idx');
            $table->timestamp('finished_at')->nullable()->index('wms_eirr_finished_idx');

            $table->unsignedInteger('active_jx_setting_count')->default(0);
            $table->unsignedInteger('received_jx_document_count')->default(0);
            $table->unsignedInteger('target_jx_log_count')->default(0);
            $table->unsignedInteger('eos_imported_count')->default(0);
            $table->unsignedInteger('incoming_matched_count')->default(0);
            $table->unsignedInteger('incoming_unmatched_count')->default(0);
            $table->unsignedInteger('incoming_confirmed_schedule_count')->default(0);
            $table->unsignedInteger('purchase_queue_count')->default(0);
            $table->unsignedInteger('purchase_transmitted_schedule_count')->default(0);
            $table->unsignedInteger('purchase_skipped_warehouse91_count')->default(0);
            $table->unsignedInteger('purchase_skipped_not_eos_sent_count')->default(0);
            $table->unsignedInteger('unknown_slip_count')->default(0);
            $table->unsignedInteger('shortage_completed_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->text('error_summary')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['execution_date', 'scheduled_time', 'trigger_type'], 'wms_eirr_time_trigger_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_eos_incoming_receive_runs');
    }
};
