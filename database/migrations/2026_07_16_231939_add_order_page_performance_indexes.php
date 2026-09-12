<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    private const ORDER_CANDIDATE_INDEXES = [
        'idx_woc_jx_document_id' => ['wms_order_jx_document_id'],
        'idx_woc_status_modified_at' => ['status', 'modified_at'],
        'idx_woc_status_modified_by_modified_at' => ['status', 'modified_by', 'modified_at'],
        'idx_woc_status_jx_modified_at' => ['status', 'wms_order_jx_document_id', 'modified_at'],
    ];

    private const ORDER_JX_DOCUMENT_INDEXES = [
        'idx_wojd_status_created_at' => ['status', 'created_at'],
        'idx_wojd_status_created_by_created_at' => ['status', 'created_by', 'created_at'],
        'idx_wojd_status_contractor_created_at' => ['status', 'contractor_id', 'created_at'],
    ];

    private const ORDER_DATA_FILE_INDEXES = [
        'idx_wodf_batch_wh_contractor_arrival' => ['batch_code', 'warehouse_id', 'contractor_id', 'expected_arrival_date'],
    ];

    public function up(): void
    {
        $this->addIndexes('wms_order_candidates', self::ORDER_CANDIDATE_INDEXES);
        $this->addIndexes('wms_order_jx_documents', self::ORDER_JX_DOCUMENT_INDEXES);
        $this->addIndexes('wms_order_data_files', self::ORDER_DATA_FILE_INDEXES);
    }

    public function down(): void
    {
        $this->dropIndexes('wms_order_data_files', array_keys(self::ORDER_DATA_FILE_INDEXES));
        $this->dropIndexes('wms_order_jx_documents', array_keys(self::ORDER_JX_DOCUMENT_INDEXES));
        $this->dropIndexes('wms_order_candidates', array_keys(self::ORDER_CANDIDATE_INDEXES));
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
