<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderSuggestionItem extends CustomModel
{
    use HasFactory;

    protected $guarded = [];
    protected $casts = [];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function stock_allocation(): BelongsTo
    {
        return $this->belongsTo(StockAllocation::class);
    }
}
