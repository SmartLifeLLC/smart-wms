<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_inventory_counts', function (Blueprint $table) {
            if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_counts', 'inventory_adjustment_request_id')) {
                $table->string('inventory_adjustment_request_id')->nullable()->after('confirmed_by')->comment('実棚変更リクエストID');
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
            if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_counts', 'inventory_adjustment_request_ids')) {
                $table->json('inventory_adjustment_request_ids')->nullable()->after('inventory_adjustment_error_message')->comment('実棚変更リクエストID一覧');
            }
            if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_counts', 'inventory_adjustment_queue_ids')) {
                $table->json('inventory_adjustment_queue_ids')->nullable()->after('inventory_adjustment_request_ids')->comment('実棚変更キューID一覧');
            }
            if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_counts', 'inventory_adjustment_queue_count')) {
                $table->unsignedInteger('inventory_adjustment_queue_count')->default(0)->after('inventory_adjustment_queue_ids')->comment('実棚変更キュー作成数');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_inventory_counts', function (Blueprint $table) {
            foreach ([
                'inventory_adjustment_queue_count',
                'inventory_adjustment_queue_ids',
                'inventory_adjustment_request_ids',
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
};
