<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('sakemaru')->hasTable('wms_jx_eos_slips')) {
            return;
        }

        if (! Schema::connection('sakemaru')->hasColumn('wms_jx_eos_slips', 'maker_direct_delivery_type')) {
            Schema::connection('sakemaru')->table('wms_jx_eos_slips', function (Blueprint $table) {
                $table->string('maker_direct_delivery_type', 8)->nullable()->after('note');
            });
        }

        if (! Schema::connection('sakemaru')->hasColumn('wms_jx_eos_slips', 'order_number')) {
            Schema::connection('sakemaru')->table('wms_jx_eos_slips', function (Blueprint $table) {
                $table->string('order_number', 32)->nullable()->after('maker_direct_delivery_type')->index();
            });
        }

        if (! Schema::connection('sakemaru')->hasColumn('wms_jx_eos_slips', 'order_type')) {
            Schema::connection('sakemaru')->table('wms_jx_eos_slips', function (Blueprint $table) {
                $table->string('order_type', 8)->nullable()->after('order_number')->index();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::connection('sakemaru')->hasTable('wms_jx_eos_slips')) {
            return;
        }

        Schema::connection('sakemaru')->table('wms_jx_eos_slips', function (Blueprint $table) {
            if (Schema::connection('sakemaru')->hasColumn('wms_jx_eos_slips', 'order_type')) {
                $table->dropIndex(['order_type']);
                $table->dropColumn('order_type');
            }

            if (Schema::connection('sakemaru')->hasColumn('wms_jx_eos_slips', 'order_number')) {
                $table->dropIndex(['order_number']);
                $table->dropColumn('order_number');
            }

            if (Schema::connection('sakemaru')->hasColumn('wms_jx_eos_slips', 'maker_direct_delivery_type')) {
                $table->dropColumn('maker_direct_delivery_type');
            }
        });
    }
};
