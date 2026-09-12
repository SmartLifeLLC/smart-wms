<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'wms_jx_transmission_logs';

    private const INDEX = 'idx_wms_jx_logs_eos_import_target';

    public function up(): void
    {
        if ($this->indexExists()) {
            return;
        }

        Schema::connection('sakemaru')->table(self::TABLE, function (Blueprint $table) {
            $table->index(
                ['direction', 'operation_type', 'status', 'transmitted_at'],
                self::INDEX
            );
        });
    }

    public function down(): void
    {
        if (! $this->indexExists()) {
            return;
        }

        Schema::connection('sakemaru')->table(self::TABLE, function (Blueprint $table) {
            $table->dropIndex(self::INDEX);
        });
    }

    private function indexExists(): bool
    {
        return DB::connection('sakemaru')->selectOne(
            <<<'SQL'
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND index_name = ?
            LIMIT 1
            SQL,
            [self::TABLE, self::INDEX],
        ) !== null;
    }
};
