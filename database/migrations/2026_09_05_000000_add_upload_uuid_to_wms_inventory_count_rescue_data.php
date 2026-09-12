<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 棚卸し退避送信（rescue）の冪等キー
 *
 * HANDY が同じ退避データを再送しても rescue 行が重複しないよう、
 * 送信単位の upload_uuid を nullable unique で持つ。
 */
return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        $schema = Schema::connection('sakemaru');

        if (! $schema->hasTable('wms_inventory_count_rescue_data')
            || $schema->hasColumn('wms_inventory_count_rescue_data', 'upload_uuid')) {
            return;
        }

        $schema->table('wms_inventory_count_rescue_data', function (Blueprint $table) {
            $table->string('upload_uuid', 255)->nullable()->after('id');
            $table->unique('upload_uuid', 'uniq_wms_inventory_count_rescue_upload_uuid');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection('sakemaru');

        if (! $schema->hasColumn('wms_inventory_count_rescue_data', 'upload_uuid')) {
            return;
        }

        $schema->table('wms_inventory_count_rescue_data', function (Blueprint $table) {
            $table->dropUnique('uniq_wms_inventory_count_rescue_upload_uuid');
            $table->dropColumn('upload_uuid');
        });
    }
};
