<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * Run the migrations.
     *
     * Drop wms_stock_allocations table as it is not used in the WMS system.
     * Stock allocations are managed by the core system's stock_allocations table.
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_stock_allocations');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Recreate the table if rollback is needed
        Schema::connection('sakemaru')->create('wms_stock_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('warehouse_id');
            $table->string('name');
            $table->timestamps();
        });
    }
};
