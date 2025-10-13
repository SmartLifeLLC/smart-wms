<?php

use Illuminate\Support\Facades\Schema;

if (!function_exists('hasColumn')) {
    /**
     * Check if a table has a specific column
     *
     * @param string $table
     * @param string $column
     * @return bool
     */
    function hasColumn(string $table, string $column): bool
    {
        return Schema::hasColumn($table, $column);
    }
}
