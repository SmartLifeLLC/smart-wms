<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add shortage_qty column
        Schema::connection('sakemaru')->table('wms_reservations', function (Blueprint $table) {
            $table->integer('shortage_qty')->nullable(false)->default(0)->after('qty_each')
                ->comment('不足数（引当できなかった数量）');
        });

        // Modify status enum to include SHORTAGE and PARTIAL
        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_reservations
            MODIFY COLUMN status ENUM('RESERVED', 'PARTIAL', 'SHORTAGE', 'RELEASED', 'CONSUMED', 'CANCELLED')
            DEFAULT 'RESERVED'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Update existing PARTIAL and SHORTAGE records to RESERVED before modifying enum
        DB::connection('sakemaru')->statement("
            UPDATE wms_reservations
            SET status = 'RESERVED'
            WHERE status IN ('PARTIAL', 'SHORTAGE')
        ");

        // Revert status enum to original values
        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_reservations
            MODIFY COLUMN status ENUM('RESERVED', 'RELEASED', 'CONSUMED', 'CANCELLED')
            DEFAULT 'RESERVED'
        ");

        // Drop shortage_qty column
        Schema::connection('sakemaru')->table('wms_reservations', function (Blueprint $table) {
            $table->dropColumn('shortage_qty');
        });
    }
};
