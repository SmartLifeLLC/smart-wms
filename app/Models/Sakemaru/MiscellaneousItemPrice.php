<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MiscellaneousItemPrice extends CustomModel
{

    protected $guarded = [];
    protected $casts = [];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
