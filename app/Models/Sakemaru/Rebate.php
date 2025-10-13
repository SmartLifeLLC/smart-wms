<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rebate extends CustomModel
{
    protected $guarded = [];
    protected $casts = [];

    public function billing_partner() : BelongsTo
    {
        return $this->belongsTo(Partner::class, 'billing_partner_id');
    }
    public function manufacturer() : BelongsTO
    {
        return $this->belongsTo(Manufacturer::class, 'manufacturer_id');
    }
    public function store_partner() : BelongsTo
    {
        return $this->belongsTo(Partner::class, 'store_partner_id');
    }
    public function buyers() : BelongsToMany
    {
        return $this->belongsToMany(Buyer::class);
    }
    public function suppliers() : BelongsToMany
    {
        return $this->belongsToMany(Supplier::class);
    }
    public function warehouses() : BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class);
    }
    public function rebate_prices() : HasMany
    {
        return $this->hasMany(RebatePrice::class);
    }

    public function origin_rebate_type() : BelongsTo
    {
        return $this->belongsTo(RebateType::class, 'rebate_type_id');
    }

    /**
     * 特定日に利用するリベート価格設定を取得
     * @param int $item_id
     * @param string $date
     * @return RebatePrice|null
     */
    public function getRebatePrice(int $item_id, string $date) : ?RebatePrice
    {
        return $this->rebate_prices
            ->where('item_id', $item_id)
            ->where('start_date', '<=', $date)
            ->sortByDesc('start_date')
            ->first();
    }
}
