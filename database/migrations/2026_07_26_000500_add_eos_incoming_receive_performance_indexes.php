<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    private const INDEXES = [
        'wms_order_incoming_schedules' => [
            'idx_ois_shortage_completion' => '(`status`, `order_source`, `purchase_queue_id`, `expected_arrival_date`, `id`)',
        ],
        'item_search_information' => [
            'idx_isi_search_string_item' => '(`search_string`, `item_id`)',
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
