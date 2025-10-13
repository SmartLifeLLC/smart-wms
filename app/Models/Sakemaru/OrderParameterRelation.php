<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderParameterRelation extends CustomModel
{
    protected $guarded = [];
    protected $casts = [];

    public function warehouse() : BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function item_category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class);
    }

    public function order_parameter(): BelongsTo
    {
        return $this->belongsTo(OrderParameter::class);
    }
}
