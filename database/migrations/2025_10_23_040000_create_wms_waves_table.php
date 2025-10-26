<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru'; // Specify your connection name here

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->create('wms_waves', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wms_wave_setting_id');
            $table->string('wave_no', 40)->comment('W###-C###-YYYYMMDD-id');
            $table->date('shipping_date')->comment('= earnings.delivered_date');
            $table->enum('status', ['PENDING', 'PICKING', 'SHORTAGE', 'COMPLETED', 'CLOSED'])->default('PENDING');
            $table->timestamps();

            // Unique constraint to prevent duplicate waves
            $table->unique(['wms_wave_setting_id', 'shipping_date']);
            $table->unique('wave_no');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_waves');
    }
};
