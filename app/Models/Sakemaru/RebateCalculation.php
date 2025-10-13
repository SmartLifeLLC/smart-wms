<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class RebateCalculation extends CustomModel
{
    use HasFactory;

    protected $guarded = [];
    protected $casts = [
        'calculated_piece_price' => 'int',
        'calculated_case_price' => 'int',
        'calculated_price' => 'int',
        'recreated_price' => 'int',
        'amount_piece' => 'int',
        'amount_case' => 'int',
    ];

    public function client() : BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
    public function partner() : BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
    public function rebate() : BelongsTo
    {
        return $this->belongsTo(Rebate::class);
    }
    public function rebate_price() : BelongsTo
    {
        return $this->belongsTo(RebatePrice::class);
    }
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
    public function trade_items() : BelongsToMany
    {
        return $this->belongsToMany(TradeItem::class)->withTimestamps();
    }

    /**
     * 廃止想定（1対1で紐づく）
     * @return BelongsToMany
     */
    public function rebate_prices() : BelongsToMany
    {
        return $this->belongsToMany(RebatePrice::class)
            ->withPivot(
                'quantity_case', 'quantity_piece', 'amount_case', 'amount_piece', 'volume_case', 'volume_piece',
                'price_case', 'price_piece', 'calculation_method', 'calculation_amount',
                'calculated_case_price', 'calculated_piece_price', 'calculated_price'
            )
            ->withTimestamps();
    }
}
