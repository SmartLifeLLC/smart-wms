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
        Schema::connection('sakemaru')->table('wms_picking_item_results', function (Blueprint $table) {
            // Add picked_at timestamp for tracking when item was picked
            $table->timestamp('picked_at')
                ->nullable()
                ->after('status')
                ->comment('ピッキング実行日時');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_picking_item_results', function (Blueprint $table) {
            $table->dropColumn('picked_at');
        });
    }
};
