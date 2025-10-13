<?php

namespace App\Models\Sakemaru;

use App\Actions\BZActions\Trades\CalculateTaxExemptPrice;
use App\Enums\EItemTaxType;
use App\Enums\Partners\EContainerTradeType;
use App\Enums\QuantityType;
use App\Enums\TaxType;
use App\Enums\TimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemPartnerPrice extends CustomModel
{
    use HasFactory;

    protected $guarded = [];
    protected $casts = [];

    protected static function boot(): void
    {
        parent::boot();
        static::saving(function ($item_partner_price) {
            $item_partner_price->fill([
                'item_code' => $item_partner_price->item->code,
                'partner_code' => $item_partner_price->partner?->code,
            ]);
        });
    }

    public function item() : BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function partner() : BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public static function currentData(int $item_id, ?int $partner_id, ?int $warehouse_id, ?string $date = null) : ?self
    {
        $date = $date ?? ClientSetting::systemDate(true)->toDateString();
        return self::query()
            ->where('item_id', $item_id)
            ->where('partner_id', $partner_id)
            ->where(function (Builder $query) use ($warehouse_id) {
                $query->where('warehouse_id', $warehouse_id)
                    ->orWhereNull('warehouse_id');
            })
            ->whereDate('start_date', '<=', $date)
            ->orderBy('start_date', 'desc')
            ->first();
    }

    public function basePriceForQuantityType(QuantityType $quantity_type): ?string
    {
        return match ($quantity_type) {
            QuantityType::PIECE => $this->unit_price,
            QuantityType::CARTON => $this->carton_price,
            QuantityType::CASE => $this->case_price,
        };
    }

    public function priceForQuantityType(QuantityType $quantity_type): ?string
    {
        $price = $this->basePriceForQuantityType($quantity_type);
        $tax_exempt_price = $this->taxExemptPriceForQuantityType($quantity_type);

        return ItemPrice::matchTaxTypeOfPrice($price, $tax_exempt_price, $this->item->current_price, $this->partner);
    }

    public function calculatePrice(QuantityType $quantity_type, EContainerTradeType $container_trade_type, bool $is_container_pickup, string $item_tax_exempt_price): ?string
    {
        $price = match ($quantity_type) {
            QuantityType::PIECE => $this->unit_price,
            QuantityType::CARTON => $this->carton_price,
            QuantityType::CASE => $this->case_price,
        };
        $tax_exempt_price = $this->taxExemptPriceForQuantityType($quantity_type);


        return ItemPrice::matchTaxTypeOfPrice($price, $tax_exempt_price, $this->item->current_price, $this->partner);
    }

    public function taxExemptPriceForQuantityType(QuantityType $quantity_type): ?string
    {
        return match ($quantity_type) {
            QuantityType::PIECE => $this->tax_exempt_unit_price,
            QuantityType::CARTON => $this->tax_exempt_carton_price,
            QuantityType::CASE => $this->tax_exempt_case_price,
        };
    }

    public function withContainerPrice(): array
    {
        return $this->calculateContainerPrice(false);
    }

    public function withoutContainerPrice(): array
    {
        return $this->calculateContainerPrice(true);
    }

    protected function calculateContainerPrice(bool $is_subtract = false): array {
        return [
            'unit_price' => calculateTaxExemptPrice($this['unit_price'], $this['tax_exempt_unit_price'], $is_subtract),
            'case_price' => calculateTaxExemptPrice($this['case_price'], $this['tax_exempt_case_price'], $is_subtract),
            'carton_price' => calculateTaxExemptPrice($this['carton_price'], $this['tax_exempt_carton_price'], $is_subtract),
            'crate_price' => calculateTaxExemptPrice($this['crate_price'], $this['tax_exempt_crate_price'], $is_subtract),
        ];
    }
}
