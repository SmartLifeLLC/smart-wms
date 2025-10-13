<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemConnection extends CustomModel
{
    protected $guarded = [];
    protected $casts = [];

    public function partner() : belongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function item() : belongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
