<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeBalance extends CustomModel
{
    use HasFactory;
    protected $guarded = [];
    protected $casts = [
        'amount' => 'int'
    ];

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    public function trade_type(): BelongsTo
    {
        return $this->belongsTo(TradeType::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function ledger_classification(): BelongsTo
    {
        return $this->belongsTo(LedgerClassification::class);
    }

    public function account_classification(): BelongsTo
    {
        return $this->belongsTo(AccountClassification::class);
    }
}
