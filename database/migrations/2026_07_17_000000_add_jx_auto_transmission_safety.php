<?php

use App\Enums\AutoOrder\TransmissionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    private const DOCUMENT_INDEX = 'idx_wojd_status_contractor_order_date';

    public function up(): void
    {
        Schema::connection($this->connection)->table('wms_contractor_settings', function (Blueprint $table) {
            if (! Schema::connection($this->connection)->hasColumn('wms_contractor_settings', 'jx_transmission_time')) {
                $table->string('jx_transmission_time', 5)
                    ->nullable()
                    ->after('jx_generation_sunday_cutoff_time')
                    ->comment('JX自動送信時刻（月-土 HH:MM）');
            }

            if (! Schema::connection($this->connection)->hasColumn('wms_contractor_settings', 'jx_transmission_sunday_time')) {
                $table->string('jx_transmission_sunday_time', 5)
                    ->nullable()
                    ->after('jx_transmission_time')
                    ->comment('JX自動送信時刻（日曜 HH:MM）');
            }
        });

        if (! $this->indexExists('wms_order_jx_documents', self::DOCUMENT_INDEX)) {
            DB::connection($this->connection)->statement(sprintf(
                'ALTER TABLE `wms_order_jx_documents` ADD INDEX `%s` (`status`, `contractor_id`, `order_date`), ALGORITHM=INPLACE, LOCK=NONE',
                self::DOCUMENT_INDEX
            ));
        }

        DB::connection($this->connection)
            ->table('wms_contractor_settings')
            ->where('transmission_type', TransmissionType::JX_FINET->value)
            ->whereNull('jx_transmission_time')
            ->update([
                'jx_transmission_time' => '13:40',
                'updated_at' => now(),
            ]);

        DB::connection($this->connection)
            ->table('wms_contractor_settings')
            ->where('transmission_type', TransmissionType::JX_FINET->value)
            ->whereNull('jx_transmission_sunday_time')
            ->update([
                'jx_transmission_sunday_time' => '23:40',
                'updated_at' => now(),
            ]);

        DB::connection($this->connection)
            ->table('wms_contractor_settings')
            ->where('is_auto_transmission', true)
            ->update([
                'is_auto_transmission' => false,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('wms_contractor_settings', function (Blueprint $table) {
            foreach (['jx_transmission_sunday_time', 'jx_transmission_time'] as $column) {
                if (Schema::connection($this->connection)->hasColumn('wms_contractor_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        if ($this->indexExists('wms_order_jx_documents', self::DOCUMENT_INDEX)) {
            DB::connection($this->connection)->statement(sprintf(
                'ALTER TABLE `wms_order_jx_documents` DROP INDEX `%s`, ALGORITHM=INPLACE, LOCK=NONE',
                self::DOCUMENT_INDEX
            ));
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $database = config("database.connections.{$this->connection}.database");

        return DB::connection($this->connection)
            ->table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
