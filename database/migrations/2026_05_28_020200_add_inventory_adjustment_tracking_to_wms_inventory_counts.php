<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_inventory_counts', function (Blueprint $table) {
            if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_counts', 'inventory_adjustment_request_id')) {
                $table->string('inventory_adjustment_request_id')->nullable()->after('stock_adjustment_error_message')->comment('実棚変更リクエストID');
            }
            if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_counts', 'inventory_adjustment_queue_id')) {
                $table->unsignedBigInteger('inventory_adjustment_queue_id')->nullable()->after('inventory_adjustment_request_id')->comment('実棚変更キューID');
            }
            if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_counts', 'inventory_adjustment_id')) {
                $table->unsignedBigInteger('inventory_adjustment_id')->nullable()->after('inventory_adjustment_queue_id')->comment('作成された実棚変更ID');
            }
            if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_counts', 'inventory_adjustment_created_at')) {
                $table->timestamp('inventory_adjustment_created_at')->nullable()->after('inventory_adjustment_id')->comment('実棚変更作成日時');
            }
            if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_counts', 'inventory_adjustment_error_message')) {
                $table->text('inventory_adjustment_error_message')->nullable()->after('inventory_adjustment_created_at')->comment('実棚変更作成エラー');
            }
        });

        $this->addIndexIfMissing('inventory_adjustment_request_id', 'idx_wms_ic_inventory_adjustment_request');
        $this->addIndexIfMissing('inventory_adjustment_queue_id', 'idx_wms_ic_inventory_adjustment_queue');
        $this->addIndexIfMissing('inventory_adjustment_id', 'idx_wms_ic_inventory_adjustment');
    }

    public function down(): void
    {
        $this->dropIndexIfExists('idx_wms_ic_inventory_adjustment_request');
        $this->dropIndexIfExists('idx_wms_ic_inventory_adjustment_queue');
        $this->dropIndexIfExists('idx_wms_ic_inventory_adjustment');

        Schema::connection('sakemaru')->table('wms_inventory_counts', function (Blueprint $table) {
            foreach ([
                'inventory_adjustment_error_message',
                'inventory_adjustment_created_at',
                'inventory_adjustment_id',
                'inventory_adjustment_queue_id',
                'inventory_adjustment_request_id',
            ] as $column) {
                if (Schema::connection('sakemaru')->hasColumn('wms_inventory_counts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function addIndexIfMissing(string $column, string $index): void
    {
        if ($this->hasIndex($index)) {
            return;
        }

        Schema::connection('sakemaru')->table('wms_inventory_counts', function (Blueprint $table) use ($column, $index) {
            $table->index($column, $index);
        });
    }

    private function dropIndexIfExists(string $index): void
    {
        if (! $this->hasIndex($index)) {
            return;
        }

        Schema::connection('sakemaru')->table('wms_inventory_counts', function (Blueprint $table) use ($index) {
            $table->dropIndex($index);
        });
    }

    private function hasIndex(string $index): bool
    {
        return ! empty(DB::connection('sakemaru')->select(
            'SHOW INDEX FROM wms_inventory_counts WHERE Key_name = ?',
            [$index]
        ));
    }
};
