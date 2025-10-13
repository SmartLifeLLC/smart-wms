<?php

namespace App\Models\Sakemaru;

use App\Enums\EAutofillPriceType;
use App\Enums\EItemTaxType;
use App\Enums\EPurchasePriceType;
use App\Enums\Partners\EContainerTradeType;
use App\Enums\QuantityType;
use App\Enums\TaxRate;
use App\Enums\TaxType;
use App\Enums\TimeZone;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemPrice extends CustomModel
{
    use HasFactory;

    protected $guarded = [];
    protected $casts = [];

    public function item() : BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    // todo 削除予定 Item.phpのcurrent_priceを推奨
    public static function currentData(int $item_id) : ?ItemPrice
    {
        return self::where('item_id', $item_id)
            ->whereDate('start_date', '<=', TimeZone::TOKYO->now()->toDateString())
            ->orderBy('start_date', 'desc')
            ->first();
    }

    public function priceForQuantityType(QuantityType $quantity_type, bool $is_purchase_price, ?Partner $partner = null, bool $is_container_pickup = false, bool $is_cost_price = false): ?string
    {
        // 通常価格 (本体価格＋保証非課税)
        if ($is_cost_price) {
            $price = $this->costPriceForQuantityType($quantity_type);
        } else if ($is_purchase_price) {
            $price = $this->purchasePriceForQuantityType($quantity_type);
        } else {
            $price = $this->salePriceForQuantityType($quantity_type);
        }
        $tax_exempt_price = $this->taxExemptPriceForQuantityType($quantity_type);

        return self::matchTaxTypeOfPrice($price, $tax_exempt_price, $this, $partner, $is_container_pickup);
    }

    public static function matchTaxTypeOfPrice(?string $price, ?string $tax_exempt_price, ?ItemPrice $item_price, ?Partner $partner, bool $is_container_pickup = false): ?string
    {
        $base_price = abs($price) > 0 ? $price - $tax_exempt_price : 0;     // 本体価格

        // 取引先情報
        if(is_null($partner)) { return $price; }     // 取引先が指定されていない
        $detail = $partner->getDetail();

        if(is_null($detail)) { return $price; }     // 該当する取引先情報がない
        $container_trade_type = EContainerTradeType::tryFrom($detail->container_trade_type);        // 中身区分(通常売/中身売)
        $container_fee = match ($container_trade_type) {       // 容器価格
            EContainerTradeType::NORMAL => $tax_exempt_price,
            EContainerTradeType::CONTENTS_ONLY => 0,
            EContainerTradeType::CONTAINER_CONTENTS_ONLY => $is_container_pickup ? 0 : $tax_exempt_price,
        };

        $item_tax_type = EItemTaxType::tryFrom($item_price?->type)?->taxType(); // 商品の税区分
        $partner_tax_type = TaxType::tryFrom($detail->display_tax_type); // 取引先の税額表示
        if(is_null($item_tax_type) || $item_tax_type->isSameAs($partner_tax_type)) {
            // 商品の税区分と取引先の税区分が一致 or 商品に税区分の指定がない
            return number_format($base_price + $container_fee, 2, '.', '');
        } else {
            // 商品の税区分と取引先の税区分が不一致 → 再計算
            $tax_rate = $item_price->taxRate();

            if(TaxType::PRE_TAX->isSameAs($partner_tax_type)) {
                // 外税 → 内税
                return number_format((($base_price * (100 + $tax_rate->percent())) / 100) + $container_fee, 2, '.', '');
            } else {
                // 内税 → 外税
                return number_format((($base_price * 100 / (100 + $tax_rate->percent()))) + $container_fee, 2, '.', '');
            }
        }
    }

    // 仕入単価
    public function purchaseUnitPrice(): Attribute
    {
        return Attribute::make(
            get: function () {
                return match ($this->item->purchase_price_type) {
                    EPurchasePriceType::PRODUCER->value => $this->producer_unit_price,
                    EPurchasePriceType::COST->value => $this->cost_unit_price,
                    EPurchasePriceType::WHOLESALE->value => $this->wholesale_unit_price,
                    default => null,
                };
            }
        );
    }

    public function purchaseCasePrice(): Attribute
    {
        return Attribute::make(
            get: function () {
                return match ($this->item->purchase_price_type) {
                    EPurchasePriceType::PRODUCER->value => $this->producer_case_price,
                    EPurchasePriceType::COST->value => $this->cost_case_price,
                    EPurchasePriceType::WHOLESALE->value => $this->wholesale_case_price,
                    default => null,
                };
            }
        );
    }

    // 仕入単価
    private function purchasePriceForQuantityType(QuantityType $quantity_type): ?string
    {
        return match ($quantity_type) {
            QuantityType::PIECE => $this->purchase_unit_price,
            QuantityType::CARTON => $this->purchase_carton_price,
            QuantityType::CASE => $this->purchase_case_price,
        };
    }

    // 売上単価
    public function salePriceForQuantityType(QuantityType $quantity_type): ?string
    {
        return match ($quantity_type) {
            QuantityType::PIECE => $this->sale_unit_price,
            QuantityType::CARTON => $this->sale_carton_price,
            QuantityType::CASE => $this->sale_case_price,
        };
    }

    // 原価単価
    private function costPriceForQuantityType(QuantityType $quantity_type): ?string
    {
        return match ($quantity_type) {
            QuantityType::PIECE => $this->cost_unit_price,
            QuantityType::CARTON => $this->cost_carton_price,
            QuantityType::CASE => $this->cost_case_price,
        };
    }
    public function taxExemptPriceForQuantityType(QuantityType $quantity_type, EContainerTradeType|string|null $container_trade_type = null, bool $is_container_pickup = false): ?string
    {
        if(!is_null($container_trade_type)) {
            if(is_string($container_trade_type)) {
                $container_trade_type = EContainerTradeType::tryFrom($container_trade_type);
            }
            $excludes_exempt_price = $container_trade_type->isContentsOnly($is_container_pickup);
            if($excludes_exempt_price) {
                return 0;
            }
        }

        return match ($quantity_type) {
            QuantityType::PIECE => $this->tax_exempt_unit_price,
            QuantityType::CARTON => $this->tax_exempt_carton_price,
            QuantityType::CASE => $this->tax_exempt_case_price,
        };
    }

    public function taxRate(): ?TaxRate {
        $item_tax_rate= EItemTaxType::tryFrom($this->type);
        if(is_null($item_tax_rate)) { return null; }
        return match ($item_tax_rate) {
            EItemTaxType::EXEMPT => TaxRate::EXEMPT,
            EItemTaxType::PRE_TAX_PERCENT_10,
            EItemTaxType::POST_TAX_PERCENT_10 => TaxRate::PERCENT_10,
            EItemTaxType::PRE_TAX_PERCENT_8,
            EItemTaxType::POST_TAX_PERCENT_8 => TaxRate::PERCENT_8,
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

    public function calculateContainerPrice(bool $is_subtract = false): array
    {
        $calculate = function ($price, $tax_exempt_price, $is_subtract)
        {
            if(!(abs($price) > 0)) {
                return $price;
            }

            if($is_subtract) {
                $ret = $price - $tax_exempt_price;
            } else {
                $ret = $price + $tax_exempt_price;
            }

            return number_format($ret, 2, '.', '');
        };

        return [
            'start_date' => $this->start_date,

            'producer_unit_price' => $calculate($this->producer_unit_price, $this->tax_exempt_unit_price, $is_subtract),
            'cost_unit_price' => $calculate($this->cost_unit_price, $this->tax_exempt_unit_price, $is_subtract),
            'wholesale_unit_price' => $calculate($this->wholesale_unit_price, $this->tax_exempt_unit_price, $is_subtract),
            'sale_unit_price' => $calculate($this->sale_unit_price, $this->tax_exempt_unit_price, $is_subtract),
            'sub_unit_price' => $calculate($this->sub_unit_price, $this->tax_exempt_unit_price, $is_subtract),
            'retail_unit_price' => $calculate($this->retail_unit_price, $this->tax_exempt_unit_price, $is_subtract),
            'tax_exempt_unit_price' => $this->tax_exempt_unit_price,

            'producer_case_price' => $calculate($this->producer_case_price, $this->tax_exempt_case_price, $is_subtract),
            'cost_case_price' => $calculate($this->cost_case_price, $this->tax_exempt_case_price, $is_subtract),
            'wholesale_case_price' => $calculate($this->wholesale_case_price, $this->tax_exempt_case_price, $is_subtract),
            'sale_case_price' => $calculate($this->sale_case_price, $this->tax_exempt_case_price, $is_subtract),
            'sub_case_price' => $calculate($this->sub_case_price, $this->tax_exempt_case_price, $is_subtract),
            'retail_case_price' => $calculate($this->retail_case_price, $this->tax_exempt_case_price, $is_subtract),
            'tax_exempt_case_price' => $this->tax_exempt_case_price,

            'producer_crate_price' => $calculate($this->producer_crate_price, $this->tax_exempt_crate_price, $is_subtract),
            'sale_crate_price' => $calculate($this->sale_crate_price, $this->tax_exempt_crate_price, $is_subtract),
            'sub_crate_price' => $calculate($this->sub_crate_price, $this->tax_exempt_crate_price, $is_subtract),
            'retail_crate_price' => $calculate($this->retail_crate_price, $this->tax_exempt_crate_price, $is_subtract),
            'tax_exempt_crate_price' => $this->tax_exempt_crate_price,

            'type' => $this->type,
            'updated_at' => $this->updated_at,
            'creator_name' => $this->creator_name,
        ];
    }
}
