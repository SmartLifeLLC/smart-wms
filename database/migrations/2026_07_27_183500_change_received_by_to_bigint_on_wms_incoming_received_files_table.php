<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::connection('sakemaru')->statement(
            'ALTER TABLE wms_incoming_received_files MODIFY received_by BIGINT UNSIGNED NULL'
        );
    }

    public function down(): void
    {
        DB::connection('sakemaru')->statement(
            'ALTER TABLE wms_incoming_received_files MODIFY received_by INT NULL'
        );
    }
};
