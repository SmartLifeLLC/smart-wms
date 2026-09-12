<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'wms_incoming_received_files';

    private const MESSAGE_INDEX = 'idx_wms_in_recv_files_msg_format';

    private const HASH_INDEX = 'idx_wms_in_recv_files_sha_format';

    public function up(): void
    {
        Schema::connection('sakemaru')->table(self::TABLE, function (Blueprint $table) {
            if (! $this->indexExists(self::MESSAGE_INDEX)) {
                $table->index(['received_message_id', 'format_type'], self::MESSAGE_INDEX);
            }

            if (! $this->indexExists(self::HASH_INDEX)) {
                $table->index(['raw_sha256', 'format_type'], self::HASH_INDEX);
            }
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->table(self::TABLE, function (Blueprint $table) {
            if ($this->indexExists(self::MESSAGE_INDEX)) {
                $table->dropIndex(self::MESSAGE_INDEX);
            }

            if ($this->indexExists(self::HASH_INDEX)) {
                $table->dropIndex(self::HASH_INDEX);
            }
        });
    }

    private function indexExists(string $indexName): bool
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
            [self::TABLE, $indexName],
        ) !== null;
    }
};
