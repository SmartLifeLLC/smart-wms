<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    private const PICKING_TASK_INDEXES = [
        'idx_wpt_ship_course_wave_id' => ['shipment_date', 'delivery_course_id', 'wave_id', 'id'],
    ];

    private const SHORTAGE_INDEXES = [
        'idx_wms_shortages_source_pick_sync' => ['source_pick_result_id', 'is_synced', 'shortage_qty'],
    ];

    public function up(): void
    {
        $this->addIndexes('wms_picking_tasks', self::PICKING_TASK_INDEXES);
        $this->addIndexes('wms_shortages', self::SHORTAGE_INDEXES);
    }

    public function down(): void
    {
        $this->dropIndexes('wms_shortages', array_keys(self::SHORTAGE_INDEXES));
        $this->dropIndexes('wms_picking_tasks', array_keys(self::PICKING_TASK_INDEXES));
    }

    /**
     * @param  array<string, list<string>>  $indexes
     */
    private function addIndexes(string $table, array $indexes): void
    {
        $clauses = [];

        foreach ($indexes as $index => $columns) {
            if ($this->indexExists($table, $index)) {
                continue;
            }

            $clauses[] = sprintf(
                'ADD INDEX `%s` (%s)',
                $index,
                collect($columns)->map(fn (string $column): string => "`{$column}`")->implode(', ')
            );
        }

        if ($clauses === []) {
            return;
        }

        $this->statement(sprintf(
            'ALTER TABLE `%s` %s, ALGORITHM=INPLACE, LOCK=NONE',
            $table,
            implode(', ', $clauses)
        ));
    }

    /**
     * @param  list<string>  $indexes
     */
    private function dropIndexes(string $table, array $indexes): void
    {
        $clauses = [];

        foreach ($indexes as $index) {
            if (! $this->indexExists($table, $index)) {
                continue;
            }

            $clauses[] = sprintf('DROP INDEX `%s`', $index);
        }

        if ($clauses === []) {
            return;
        }

        $this->statement(sprintf(
            'ALTER TABLE `%s` %s, ALGORITHM=INPLACE, LOCK=NONE',
            $table,
            implode(', ', $clauses)
        ));
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

    private function statement(string $sql): void
    {
        DB::connection($this->connection)->statement($sql);
    }
};
