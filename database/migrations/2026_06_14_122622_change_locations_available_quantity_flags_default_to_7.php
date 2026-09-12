<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        DB::connection('sakemaru')->statement(
            'ALTER TABLE locations ALTER COLUMN available_quantity_flags SET DEFAULT 7'
        );
    }

    public function down(): void
    {
        DB::connection('sakemaru')->statement(
            'ALTER TABLE locations ALTER COLUMN available_quantity_flags SET DEFAULT 8'
        );
    }
};
