<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeType extends CustomModel
{
    use HasFactory;
    protected $guarded = [];
    protected $casts = [];

    public static function getDefault() : self
    {
        return self::first();
    }
}
