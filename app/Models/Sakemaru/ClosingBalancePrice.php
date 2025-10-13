<?php

namespace App\Models\Sakemaru;
use App\ValueObjects\PriceBreakdownVO;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClosingBalancePrice extends CustomModel
{
    protected $guarded = [];
    protected $casts = [];

    public function closing_balance_overview(): BelongsTo
    {
        return $this->belongsTo(ClosingBalanceOverview::class);
    }

    public static function prepareFromPriceBreakdown(PriceBreakdownVO $price_data, int $closing_balance_overview_id, bool $is_returned) : array
    {
        return [
            'closing_balance_overview_id' => $closing_balance_overview_id,
            'is_returned' => $is_returned,
            'subtotal_0_percent' => $price_data->subtotal_0_percent,
            'subtotal_8_percent' => $price_data->subtotal_8_percent,
            'subtotal_10_percent' => $price_data->subtotal_10_percent,
            'tax_8_percent' => $price_data->tax_8_percent,
            'tax_10_percent' => $price_data->tax_10_percent,
            'total_0_percent' => $price_data->total_0_percent,
            'total_8_percent' => $price_data->total_8_percent,
            'total_10_percent' => $price_data->total_10_percent,
            'subtotal' => $price_data->subtotal,
            'tax' => $price_data->tax,
            'total' => $price_data->total,
        ];
    }

    public static function createFromPriceBreakdown(PriceBreakdownVO $price_data, int $closing_balance_overview_id, bool $is_returned) : self
    {
        return ClosingBalancePrice::create(self::prepareFromPriceBreakdown($price_data, $closing_balance_overview_id, $is_returned));
    }
}
