<?php

namespace App\Models\Sakemaru;


use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderSuggestion extends CustomModel
{
    use HasFactory;

    protected $guarded = [];
    protected $casts = [];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderSuggestionItem::class);
    }
}
