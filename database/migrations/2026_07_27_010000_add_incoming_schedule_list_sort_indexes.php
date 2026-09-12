<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    private const INDEXES = [
        'idx_ois_status_wh_arrival_sort' => '(`status`, `warehouse_id`, `expected_arrival_date`, `item_id`, `id`)',
        'idx_ois_status_wh_order_sort' => '(`status`, `warehouse_id`, `order_date`, `item_id`, `id`)',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $index => $definition) {
            $this->addIndexIfMissing($index, $definition);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(array_keys(self::INDEXES)) as $index) {
            $this->dropIndexIfExists($index);
        }
    }

    private function addIndexIfMissing(string $index, string $definition): void
    {
        if ($this->indexExists($index)) {
            return;
        }

        DB::connection($this->connection)->statement(
            "ALTER TABLE `wms_order_incoming_schedules` ADD INDEX `{$index}` {$definition}, ALGORITHM=INPLACE, LOCK=NONE"
        );
    }

    private function dropIndexIfExists(string $index): void
    {
        if (! $this->indexExists($index)) {
            return;
        }

        DB::connection($this->connection)->statement(
            "ALTER TABLE `wms_order_incoming_schedules` DROP INDEX `{$index}`, ALGORITHM=INPLACE, LOCK=NONE"
        );
    }

    private function indexExists(string $index): bool
    {
        $database = config("database.connections.{$this->connection}.database");

        return DB::connection($this->connection)
            ->table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', 'wms_order_incoming_schedules')
            ->where('index_name', $index)
            ->exists();
    }
};
