<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RebateDeposit extends CustomModel
{
    protected $guarded = [];
    protected $casts = [];

    public function trade(): belongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    public function supplier(): belongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
    public function account_classification() : belongsTo
    {
        return $this->belongsTo(AccountClassification::class);
    }

    public function rebate_bill(): BelongsTo
    {
        return $this->belongsTo(RebateBill::class);
    }
}
