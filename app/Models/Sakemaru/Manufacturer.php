<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Manufacturer extends CustomModel
{
    use HasFactory;
    protected $guarded = [];
    protected $casts = [
        'code' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }
}
