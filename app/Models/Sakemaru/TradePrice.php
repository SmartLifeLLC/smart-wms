<?php

namespace App\Models\Sakemaru;

use App\Enums\Partners\EFraction;
use App\Enums\TaxType;
use App\ValueObjects\PriceBreakdownVO;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradePrice extends CustomModel
{
    use HasFactory;
    protected $guarded = [];
    protected $casts = [];

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    public function taxType() : TaxType
    {
        return TaxType::from($this->tax_type);
    }
    public function taxFraction() : EFraction
    {
        return EFraction::from($this->tax_fraction);
    }


    public static function prepareFromPriceBreakdown(PriceBreakdownVO $price_breakdown) : array
    {
        return [
            'tax_type' => $price_breakdown->tax_type,
            'tax_fraction' => $price_breakdown->tax_fraction,
            'subtotal_tax_exempt_container' => $price_breakdown->subtotal_tax_exempt_container,
            'subtotal_0_percent' => $price_breakdown->subtotal_0_percent,
            'subtotal_8_percent' => $price_breakdown->subtotal_8_percent,
            'subtotal_10_percent' => $price_breakdown->subtotal_10_percent,
            'tax_8_percent' => $price_breakdown->tax_8_percent,
            'tax_10_percent' => $price_breakdown->tax_10_percent,
            'total_0_percent' => $price_breakdown->total_0_percent,
            'total_8_percent' => $price_breakdown->total_8_percent,
            'total_10_percent' => $price_breakdown->total_10_percent
        ];
    }
}
