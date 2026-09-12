<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasColumn('wms_incoming_received_details', 'matched_schedule_id')) {
            return;
        }

        Schema::connection($this->connection)->table('wms_incoming_received_details', function (Blueprint $table) {
            $table->unsignedBigInteger('matched_schedule_id')
                ->nullable()
                ->after('matched_item_id')
                ->index('wms_ird_matched_schedule_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasColumn('wms_incoming_received_details', 'matched_schedule_id')) {
            return;
        }

        Schema::connection($this->connection)->table('wms_incoming_received_details', function (Blueprint $table) {
            $table->dropIndex('wms_ird_matched_schedule_idx');
            $table->dropColumn('matched_schedule_id');
        });
    }
};
