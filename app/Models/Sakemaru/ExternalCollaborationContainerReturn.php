<?php

namespace App\Models\Sakemaru;


use App\Traits\QuantityTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;


class ExternalCollaborationContainerReturn extends CustomModel
{
    use QuantityTrait;

    protected $guarded = [];
    protected $casts = [
        'log' => 'json',
    ];
    protected bool $is_active_activate = false;

    public function partner() : BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function warehouse() : BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function item() : BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    public function stock_allocation() : BelongsTo
    {
        return $this->belongsTo(StockAllocation::class);
    }

    public function external_collaboration_data() : BelongsTo
    {
        return $this->belongsTo(ExternalCollaborationData::class);
    }
}
