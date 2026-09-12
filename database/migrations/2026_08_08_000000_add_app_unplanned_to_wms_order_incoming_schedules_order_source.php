<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('sakemaru')->hasColumn('wms_order_incoming_schedules', 'order_source')) {
            return;
        }

        DB::connection('sakemaru')->statement(
            "ALTER TABLE wms_order_incoming_schedules MODIFY COLUMN order_source ENUM('AUTO', 'MANUAL', 'TRANSFER', 'RECEIVED', 'APP_UNPLANNED') DEFAULT 'MANUAL'"
        );
    }

    public function down(): void
    {
        if (! Schema::connection('sakemaru')->hasColumn('wms_order_incoming_schedules', 'order_source')) {
            return;
        }

        DB::connection('sakemaru')
            ->table('wms_order_incoming_schedules')
            ->where('order_source', 'APP_UNPLANNED')
            ->update(['order_source' => 'MANUAL']);

        DB::connection('sakemaru')->statement(
            "ALTER TABLE wms_order_incoming_schedules MODIFY COLUMN order_source ENUM('AUTO', 'MANUAL', 'TRANSFER', 'RECEIVED') DEFAULT 'MANUAL'"
        );
    }
};
