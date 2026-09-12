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

        if (! Schema::connection('sakemaru')->hasColumn('wms_jx_eos_slips', 'order_type_label')) {
            Schema::connection('sakemaru')->table('wms_jx_eos_slips', function (Blueprint $table) {
                $table->string('order_type_label', 64)->nullable()->after('order_type');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::connection('sakemaru')->hasTable('wms_jx_eos_slips')) {
            return;
        }

        Schema::connection('sakemaru')->table('wms_jx_eos_slips', function (Blueprint $table) {
            if (Schema::connection('sakemaru')->hasColumn('wms_jx_eos_slips', 'order_type_label')) {
                $table->dropColumn('order_type_label');
            }
        });
    }
};
