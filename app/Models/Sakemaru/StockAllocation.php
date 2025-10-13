<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class StockAllocation extends CustomModel
{
    use HasFactory;
    protected $guarded = [];
    protected $casts = [];

    public static function getDefault() : self
    {
        return self::first();
    }
}
