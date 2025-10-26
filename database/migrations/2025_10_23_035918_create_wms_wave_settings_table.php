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
        Schema::connection('sakemaru')->create('wms_wave_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('delivery_course_id');
            $table->time('picking_start_time')->nullable();
            $table->time('picking_deadline_time')->nullable();
            $table->unsignedBigInteger('creator_id');
            $table->unsignedBigInteger('last_updater_id');
            $table->timestamps();

            // Unique constraint: one setting per warehouse-course combination
            $table->unique(['warehouse_id', 'delivery_course_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_wave_settings');
    }
};
