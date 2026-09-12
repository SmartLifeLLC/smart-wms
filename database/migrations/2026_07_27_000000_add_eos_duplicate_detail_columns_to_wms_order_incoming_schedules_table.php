<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    private const TABLE = 'wms_order_incoming_schedules';

    public function up(): void
    {
        $this->addColumns();
        $this->addIndexIfMissing('idx_ois_source_incoming_schedule_id', '(`source_incoming_schedule_id`)');
        $this->addIndexIfMissing('idx_ois_purchase_split_key', '(`purchase_split_key`)');
        $this->addUniqueIndexIfMissing('uidx_ois_source_received_detail_id', '(`source_received_detail_id`)');
    }

    public function down(): void
    {
        $this->dropIndexIfExists('uidx_ois_source_received_detail_id');
        $this->dropIndexIfExists('idx_ois_purchase_split_key');
        $this->dropIndexIfExists('idx_ois_source_incoming_schedule_id');

        foreach (['purchase_split_key', 'source_received_detail_id', 'source_incoming_schedule_id'] as $column) {
            if ($this->columnExists($column)) {
                DB::connection($this->connection)->statement(sprintf(
                    'ALTER TABLE `%s` DROP COLUMN `%s`, ALGORITHM=INPLACE, LOCK=NONE',
                    self::TABLE,
                    $column,
                ));
            }
        }
    }

    private function addColumns(): void
    {
        $definitions = [];

        if (! $this->columnExists('source_incoming_schedule_id')) {
            $definitions[] = 'ADD COLUMN `source_incoming_schedule_id` BIGINT UNSIGNED NULL COMMENT \'EOS重複明細から作成した元入荷予定ID\' AFTER `purchase_slip_number`';
        }

        if (! $this->columnExists('source_received_detail_id')) {
            $definitions[] = 'ADD COLUMN `source_received_detail_id` BIGINT UNSIGNED NULL COMMENT \'EOS重複明細から作成した元受信明細ID\' AFTER `source_incoming_schedule_id`';
        }

        if (! $this->columnExists('purchase_split_key')) {
            $definitions[] = 'ADD COLUMN `purchase_split_key` VARCHAR(80) NULL COMMENT \'同一伝票番号の仕入キュー分割キー\' AFTER `source_received_detail_id`';
        }

        if ($definitions === []) {
            return;
        }

        DB::connection($this->connection)->statement(sprintf(
            'ALTER TABLE `%s` %s, ALGORITHM=INPLACE, LOCK=NONE',
            self::TABLE,
            implode(', ', $definitions),
        ));
    }

    private function addIndexIfMissing(string $index, string $definition): void
    {
        if ($this->indexExists($index)) {
            return;
        }

        DB::connection($this->connection)->statement(sprintf(
            'ALTER TABLE `%s` ADD INDEX `%s` %s, ALGORITHM=INPLACE, LOCK=NONE',
            self::TABLE,
            $index,
            $definition,
        ));
    }

    private function addUniqueIndexIfMissing(string $index, string $definition): void
    {
        if ($this->indexExists($index)) {
            return;
        }

        DB::connection($this->connection)->statement(sprintf(
            'ALTER TABLE `%s` ADD UNIQUE INDEX `%s` %s, ALGORITHM=INPLACE, LOCK=NONE',
            self::TABLE,
            $index,
            $definition,
        ));
    }

    private function dropIndexIfExists(string $index): void
    {
        if (! $this->indexExists($index)) {
            return;
        }

        DB::connection($this->connection)->statement(sprintf(
            'ALTER TABLE `%s` DROP INDEX `%s`, ALGORITHM=INPLACE, LOCK=NONE',
            self::TABLE,
            $index,
        ));
    }

    private function columnExists(string $column): bool
    {
        $database = config("database.connections.{$this->connection}.database");

        return DB::connection($this->connection)
            ->table('information_schema.columns')
            ->where('table_schema', $database)
            ->where('table_name', self::TABLE)
            ->where('column_name', $column)
            ->exists();
    }

    private function indexExists(string $index): bool
    {
        $database = config("database.connections.{$this->connection}.database");

        return DB::connection($this->connection)
            ->table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', self::TABLE)
            ->where('index_name', $index)
            ->exists();
    }
};
