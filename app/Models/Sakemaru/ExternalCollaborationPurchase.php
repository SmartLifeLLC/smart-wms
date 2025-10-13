<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalCollaborationPurchase extends CustomModel
{
    protected bool $is_active_activate = false;
    protected $guarded = [];
    protected $casts = [
        'log' => 'json',
    ];

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function stock_allocation(): BelongsTo
    {
        return $this->belongsTo(StockAllocation::class);
    }

    public function trade() : BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }
}
