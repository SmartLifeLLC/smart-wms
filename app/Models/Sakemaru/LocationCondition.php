<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationCondition extends CustomModel
{
    use hasFactory;
    protected $guarded = [];
    protected $casts = [];
}
