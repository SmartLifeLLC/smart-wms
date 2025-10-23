<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Remove client_id from wms_reservations
        Schema::table('wms_reservations', function (Blueprint $table) {
            if (Schema::hasColumn('wms_reservations', 'client_id')) {
                $table->dropColumn('client_id');
            }
        });

        // Remove client_id from wms_stock_allocations
        Schema::table('wms_stock_allocations', function (Blueprint $table) {
            if (Schema::hasColumn('wms_stock_allocations', 'client_id')) {
                $table->dropColumn('client_id');
            }
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Add client_id back
        Schema::table('wms_reservations', function (Blueprint $table) {
            $table->unsignedBigInteger('client_id')->after('id');
        });

        Schema::table('wms_stock_allocations', function (Blueprint $table) {
            $table->unsignedBigInteger('client_id')->after('id');
        });


    }
};
