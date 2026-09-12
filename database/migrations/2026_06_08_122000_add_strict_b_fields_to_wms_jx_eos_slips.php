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

        if (! Schema::connection('sakemaru')->hasColumn('wms_jx_eos_slips', 'warehouse_code')) {
            Schema::connection('sakemaru')->table('wms_jx_eos_slips', function (Blueprint $table) {
                $table->string('warehouse_code', 32)->nullable()->after('shop_code')->index();
            });
        }

        if (! Schema::connection('sakemaru')->hasColumn('wms_jx_eos_slips', 'warehouse_name')) {
            Schema::connection('sakemaru')->table('wms_jx_eos_slips', function (Blueprint $table) {
                $table->string('warehouse_name')->nullable()->after('warehouse_code');
            });
        }

        if (! Schema::connection('sakemaru')->hasColumn('wms_jx_eos_slips', 'slip_type_label')) {
            Schema::connection('sakemaru')->table('wms_jx_eos_slips', function (Blueprint $table) {
                $table->string('slip_type_label', 64)->nullable()->after('slip_type');
            });
        }

        if (! Schema::connection('sakemaru')->hasColumn('wms_jx_eos_slips', 'is_return_slip')) {
            Schema::connection('sakemaru')->table('wms_jx_eos_slips', function (Blueprint $table) {
                $table->boolean('is_return_slip')->nullable()->after('slip_type_label')->index();
            });
        }

        if (! Schema::connection('sakemaru')->hasColumn('wms_jx_eos_slips', 'is_shipment_slip')) {
            Schema::connection('sakemaru')->table('wms_jx_eos_slips', function (Blueprint $table) {
                $table->boolean('is_shipment_slip')->nullable()->after('is_return_slip')->index();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::connection('sakemaru')->hasTable('wms_jx_eos_slips')) {
            return;
        }

        Schema::connection('sakemaru')->table('wms_jx_eos_slips', function (Blueprint $table) {
            if (Schema::connection('sakemaru')->hasColumn('wms_jx_eos_slips', 'is_shipment_slip')) {
                $table->dropIndex(['is_shipment_slip']);
                $table->dropColumn('is_shipment_slip');
            }

            if (Schema::connection('sakemaru')->hasColumn('wms_jx_eos_slips', 'is_return_slip')) {
                $table->dropIndex(['is_return_slip']);
                $table->dropColumn('is_return_slip');
            }

            if (Schema::connection('sakemaru')->hasColumn('wms_jx_eos_slips', 'slip_type_label')) {
                $table->dropColumn('slip_type_label');
            }

            if (Schema::connection('sakemaru')->hasColumn('wms_jx_eos_slips', 'warehouse_name')) {
                $table->dropColumn('warehouse_name');
            }

            if (Schema::connection('sakemaru')->hasColumn('wms_jx_eos_slips', 'warehouse_code')) {
                $table->dropIndex(['warehouse_code']);
                $table->dropColumn('warehouse_code');
            }

            if (Schema::connection('sakemaru')->hasColumn('wms_jx_eos_slips', 'order_type_label')) {
                $table->dropColumn('order_type_label');
            }
        });
    }
};
