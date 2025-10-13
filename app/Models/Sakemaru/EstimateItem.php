<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateItem extends CustomModel
{
    use HasFactory;

    protected $guarded = [];
    protected $casts = [];

    public function estimate() : BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    public function item() : BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
