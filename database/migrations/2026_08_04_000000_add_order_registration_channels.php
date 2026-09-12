<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::connection($this->connection)->table('wms_order_candidates', function (Blueprint $table): void {
            if (! Schema::connection($this->connection)->hasColumn('wms_order_candidates', 'order_channel')) {
                $table->string('order_channel', 16)
                    ->nullable()
                    ->after('origin_type')
                    ->comment('発注区分: EOS/FAX。NULLは旧発注フロー');
            }

            if (! Schema::connection($this->connection)->hasColumn('wms_order_candidates', 'entry_source')) {
                $table->string('entry_source', 32)
                    ->nullable()
                    ->after('order_channel')
                    ->comment('新外部発注の生成元: SALES_HISTORY/SEARCH');
            }
        });

        Schema::connection($this->connection)->table('wms_order_data_files', function (Blueprint $table): void {
            if (! Schema::connection($this->connection)->hasColumn('wms_order_data_files', 'order_channel')) {
                $table->string('order_channel', 32)
                    ->nullable()
                    ->after('candidate_ids')
                    ->comment('発注データ種別: EOS/FAX/JX_CONFIRMATION。NULLは旧データ');
            }

            if (! Schema::connection($this->connection)->hasColumn('wms_order_data_files', 'show_eos_stamp')) {
                $table->boolean('show_eos_stamp')
                    ->default(false)
                    ->after('order_channel')
                    ->comment('発注書PDFにEOS発注スタンプを表示する');
            }
        });

        Schema::connection($this->connection)->table('wms_order_incoming_schedules', function (Blueprint $table): void {
            if (! Schema::connection($this->connection)->hasColumn('wms_order_incoming_schedules', 'order_channel')) {
                $table->string('order_channel', 16)
                    ->nullable()
                    ->after('order_source')
                    ->comment('発注区分: EOS/FAX。NULLは旧発注/入荷予定');
            }
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('wms_order_incoming_schedules', function (Blueprint $table): void {
            if (Schema::connection($this->connection)->hasColumn('wms_order_incoming_schedules', 'order_channel')) {
                $table->dropColumn('order_channel');
            }
        });

        Schema::connection($this->connection)->table('wms_order_data_files', function (Blueprint $table): void {
            if (Schema::connection($this->connection)->hasColumn('wms_order_data_files', 'show_eos_stamp')) {
                $table->dropColumn('show_eos_stamp');
            }

            if (Schema::connection($this->connection)->hasColumn('wms_order_data_files', 'order_channel')) {
                $table->dropColumn('order_channel');
            }
        });

        Schema::connection($this->connection)->table('wms_order_candidates', function (Blueprint $table): void {
            if (Schema::connection($this->connection)->hasColumn('wms_order_candidates', 'entry_source')) {
                $table->dropColumn('entry_source');
            }

            if (Schema::connection($this->connection)->hasColumn('wms_order_candidates', 'order_channel')) {
                $table->dropColumn('order_channel');
            }
        });
    }
};
