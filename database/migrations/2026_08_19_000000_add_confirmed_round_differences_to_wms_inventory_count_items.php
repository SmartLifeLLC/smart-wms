<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    private const TABLE = 'wms_inventory_count_items';

    public function up(): void
    {
        $existingColumns = Schema::connection($this->connection)->getColumnListing(self::TABLE);
        $definitions = collect($this->columns())
            ->reject(fn (array $definition, string $column): bool => in_array($column, $existingColumns, true))
            ->map(fn (array $definition, string $column): string => sprintf(
                'ADD COLUMN `%s` %s NULL COMMENT %s',
                $column,
                $definition['type'],
                DB::connection($this->connection)->getPdo()->quote($definition['comment']),
            ))
            ->values()
            ->all();

        if ($definitions !== []) {
            DB::connection($this->connection)->statement('ALTER TABLE `'.self::TABLE.'` '.implode(', ', $definitions));
        }
    }

    public function down(): void
    {
        $existingColumns = Schema::connection($this->connection)->getColumnListing(self::TABLE);
        $drops = collect(array_keys($this->columns()))
            ->filter(fn (string $column): bool => in_array($column, $existingColumns, true))
            ->map(fn (string $column): string => "DROP COLUMN `{$column}`")
            ->values()
            ->all();

        if ($drops !== []) {
            DB::connection($this->connection)->statement('ALTER TABLE `'.self::TABLE.'` '.implode(', ', $drops));
        }
    }

    private function columns(): array
    {
        return [
            'first_count_confirmed_system_quantity' => ['type' => 'DECIMAL(15, 3)', 'comment' => '1回目確定時理論在庫数量'],
            'first_count_confirmed_difference_quantity' => ['type' => 'DECIMAL(15, 3)', 'comment' => '1回目確定時差異数量'],
            'first_count_confirmed_difference_amount' => ['type' => 'DECIMAL(15, 2)', 'comment' => '1回目確定時差異金額'],
            'second_count_confirmed_system_quantity' => ['type' => 'DECIMAL(15, 3)', 'comment' => '2回目確定時理論在庫数量'],
            'second_count_confirmed_difference_quantity' => ['type' => 'DECIMAL(15, 3)', 'comment' => '2回目確定時差異数量'],
            'second_count_confirmed_difference_amount' => ['type' => 'DECIMAL(15, 2)', 'comment' => '2回目確定時差異金額'],
            'final_count_confirmed_system_quantity' => ['type' => 'DECIMAL(15, 3)', 'comment' => '3回目確定時理論在庫数量'],
            'final_count_confirmed_difference_quantity' => ['type' => 'DECIMAL(15, 3)', 'comment' => '3回目確定時差異数量'],
            'final_count_confirmed_difference_amount' => ['type' => 'DECIMAL(15, 2)', 'comment' => '3回目確定時差異金額'],
        ];
    }
};
