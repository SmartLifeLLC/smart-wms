<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Floor extends CustomModel
{
    use HasFactory;
    protected $guarded = [];
    protected $casts = [];

    public function warehouse() : belongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
