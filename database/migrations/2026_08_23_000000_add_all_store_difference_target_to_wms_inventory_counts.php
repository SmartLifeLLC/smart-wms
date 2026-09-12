<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::connection($this->connection)->table('wms_inventory_counts', function (Blueprint $table): void {
            if (! Schema::connection($this->connection)->hasColumn('wms_inventory_counts', 'is_all_store_difference_target')) {
                $table
                    ->boolean('is_all_store_difference_target')
                    ->default(false)
                    ->after('memo')
                    ->comment('全店差異表出力対象フラグ');
            }
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('wms_inventory_counts', function (Blueprint $table): void {
            if (Schema::connection($this->connection)->hasColumn('wms_inventory_counts', 'is_all_store_difference_target')) {
                $table->dropColumn('is_all_store_difference_target');
            }
        });
    }
};
