<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MonthlyStockOverview extends CustomModel
{
    use HasFactory;
    protected $guarded = [];
    protected $casts = [];

    public function closing_monthly(): BelongsTo
    {
        return $this->belongsTo(ClosingMonthly::class);
    }
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
