<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RebatePrice extends CustomModel
{
    protected $guarded = [];
    protected $casts = [];

    public function rebate() : belongsTo
    {
        return $this->belongsTo(Rebate::class);
    }
    public function item() : belongsTo
    {
        return $this->belongsTo(Item::class);
    }
    public function trade_items() : BelongsToMany
    {
        return $this->belongsToMany(TradeItem::class);
    }
    public function rebate_calculations() : HasMany
    {
        return $this->hasMany(RebateCalculation::class);
    }
}
