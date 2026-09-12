<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::connection('sakemaru')->create('wms_inventory_count_rescue_data', function (Blueprint $table) {
            $table->id();
            $table->integer('original_count_id')->index();
            $table->string('original_count_no', 100);
            $table->integer('count_round');
            $table->string('device_id', 100)->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('warehouse_id')->nullable();
            $table->json('items');
            $table->integer('item_count');
            $table->string('status', 30)->default('pending');
            $table->integer('processed_count_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_inventory_count_rescue_data');
    }
};
