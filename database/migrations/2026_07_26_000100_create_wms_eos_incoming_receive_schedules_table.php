<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('wms_eos_incoming_receive_schedules')) {
            return;
        }

        Schema::connection($this->connection)->create('wms_eos_incoming_receive_schedules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('setting_id')->index('wms_eirs_schedule_setting_idx');
            $table->string('schedule_type', 32)->default('DAILY')->index('wms_eirs_schedule_type_idx');
            $table->unsignedTinyInteger('day_of_week')->default(0)->comment('0=日, 1=月, ..., 6=土。DAILYは0固定');
            $table->unsignedTinyInteger('slot_no')->default(1)->comment('同一曜日内の枠番号');
            $table->time('receive_time')->nullable();
            $table->boolean('is_enabled')->default(true)->index('wms_eirs_schedule_enabled_idx');
            $table->boolean('auto_purchase_transmission_enabled')->default(true);
            $table->timestamps();

            $table->unique(
                ['setting_id', 'schedule_type', 'day_of_week', 'slot_no'],
                'wms_eirs_schedule_unique'
            );
            $table->index(
                ['setting_id', 'is_enabled', 'schedule_type', 'day_of_week', 'receive_time'],
                'wms_eirs_schedule_due_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_eos_incoming_receive_schedules');
    }
};
