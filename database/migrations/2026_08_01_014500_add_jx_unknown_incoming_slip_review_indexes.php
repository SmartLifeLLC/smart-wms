<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    private const INDEXES = [
        'wms_incoming_import_errors' => [
            'idx_wir_err_slip_review' => '(`received_slip_id`, `error_code`, `is_resolved`)',
            'idx_wir_err_detail_review' => '(`received_detail_id`, `error_code`, `is_resolved`)',
        ],
        'wms_incoming_received_details' => [
            'idx_wir_det_slip_item_status' => '(`received_slip_id`, `matched_item_id`, `match_status`)',
        ],
        'wms_incoming_received_slips' => [
            'idx_wir_slip_shop_status_id' => '(`b_shop_code`, `match_status`, `id`)',
            'idx_wir_slip_file_status_id' => '(`received_file_id`, `match_status`, `id`)',
        ],
        'wms_incoming_received_files' => [
            'idx_wir_file_format_contractor' => '(`format_type`, `contractor_id`, `id`)',
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $index => $definition) {
                $this->addIndexIfMissing($table, $index, $definition);
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::INDEXES) as $table => $indexes) {
            foreach (array_reverse(array_keys($indexes)) as $index) {
                $this->dropIndexIfExists($table, $index);
            }
        }
    }

    private function addIndexIfMissing(string $table, string $index, string $definition): void
    {
        if ($this->indexExists($table, $index)) {
            return;
        }

        DB::connection($this->connection)->statement(
            "ALTER TABLE `{$table}` ADD INDEX `{$index}` {$definition}, ALGORITHM=INPLACE, LOCK=NONE"
        );
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        if (! $this->indexExists($table, $index)) {
            return;
        }

        DB::connection($this->connection)->statement(
            "ALTER TABLE `{$table}` DROP INDEX `{$index}`, ALGORITHM=INPLACE, LOCK=NONE"
        );
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
