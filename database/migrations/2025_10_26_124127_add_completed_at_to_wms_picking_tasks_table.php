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
        Schema::connection('sakemaru')->table('wms_picking_tasks', function (Blueprint $table) {
            // Add started_at and completed_at timestamps
            if (!Schema::connection('sakemaru')->hasColumn('wms_picking_tasks', 'started_at')) {
                $table->timestamp('started_at')
                    ->nullable()
                    ->after('picker_id')
                    ->comment('ピッキング開始日時');
            }

            if (!Schema::connection('sakemaru')->hasColumn('wms_picking_tasks', 'completed_at')) {
                $table->timestamp('completed_at')
                    ->nullable()
                    ->after('picker_id')
                    ->comment('ピッキング完了日時');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_picking_tasks', function (Blueprint $table) {
            if (Schema::connection('sakemaru')->hasColumn('wms_picking_tasks', 'started_at')) {
                $table->dropColumn('started_at');
            }
            if (Schema::connection('sakemaru')->hasColumn('wms_picking_tasks', 'completed_at')) {
                $table->dropColumn('completed_at');
            }
        });
    }
};
