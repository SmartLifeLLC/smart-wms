<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_order_incoming_schedules
            MODIFY COLUMN status ENUM('PENDING','PARTIAL','CONFIRMED','TRANSMITTED','CANCELLED','PARTIAL_CANCELLED','DELETED')
                NOT NULL DEFAULT 'PENDING'
        ");
    }

    public function down(): void
    {
        DB::connection('sakemaru')->table('wms_order_incoming_schedules')
            ->where('status', 'DELETED')
            ->update(['status' => 'CANCELLED']);

        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_order_incoming_schedules
            MODIFY COLUMN status ENUM('PENDING','PARTIAL','CONFIRMED','TRANSMITTED','CANCELLED','PARTIAL_CANCELLED')
                NOT NULL DEFAULT 'PENDING'
        ");
    }
};
