<?php

namespace App\Models\Sakemaru;

use App\Enums\QuantityType;

use App\Enums\TaxRate;
use App\Enums\TaxType;
use App\Enums\TradeCategory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

class TradeItem extends CustomModel
{
    use HasFactory;

    protected $guarded = [];
    protected $casts = [
        'amount' => 'int',
        'container_amount' => 'int',
        'content_amount' => 'int',
    ];

    public static array $generated_cols = [
        'content_amount', 'has_shortage', 'shortage_status', 'total_piece_quantity'
    ];

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function stock_allocation(): BelongsTo
    {
        return $this->belongsTo(StockAllocation::class);
    }

    public function trade_type(): BelongsTo
    {
        return $this->belongsTo(TradeType::class);
    }
    public function rebate_calculations() : BelongsToMany
    {
        return $this->belongsToMany(RebateCalculation::class);
    }
    public function rebate_prices() : BelongsToMany
    {
        return $this->belongsToMany(RebatePrice::class)
            ->withPivot('rebate_amount', 'rebate_recreation_amount')
            ->withTimestamps();
    }

    public function pieceOrderQuantity() : Attribute
    {
        return new Attribute(function () {
            return QuantityType::PIECE->isSameAs($this->order_quantity_type) ? $this->order_quantity : 0;
        });
    }

    public function caseOrderQuantity() : Attribute
    {
        return new Attribute(function () {
            return QuantityType::CASE->isSameAs($this->order_quantity_type) ? $this->order_quantity : 0;
        });
    }

    public function pieceQuantity() : Attribute
    {
        return new Attribute(function () {
            return QuantityType::PIECE->isSameAs($this->quantity_type) ? $this->quantity : 0;
        });
    }

    public function caseQuantity() : Attribute
    {
        return new Attribute(function () {
            return QuantityType::CASE->isSameAs($this->quantity_type) ? $this->quantity : 0;
        });
    }


    public function taxExemptPrice(QuantityType $quantity_type, int $quantity) : float
    {
        $item_price = $this->item?->item_price;
        $price = $item_price->{$quantity_type->taxExemptPriceCol()} ?? 0;
        return $price * $quantity;
    }

    public function totalNumberOfUnits(): ?int {
        return $this->total_piece_quantity;
//        $capacity = $this->item?->capacityOfQuantityType($this->quantity_type);
//        if(is_null($capacity)) { return null; }
//        return $this->quantity * $capacity;
    }

    // 仮税額
    public function estimatedTaxPrice(): float
    {
        switch ($this->tax_type) {
            case TaxType::PRE_TAX;
                return $this->amount - $this->tax_excluded_amount;
            default:
                $amount = $this->is_tax_exempt_container ? $this->content_amount : $this->amount;
                return TaxRate::from($this->tax_rate)->calculate($amount);
        }
    }

    public static function calculateTotalTradeItemAmount(Collection $trade_items): int
    {
        return $trade_items->sum('amount') - $trade_items->sum(function ($item) {
                return $item->is_container_included ? $item->container_amount : 0;
            });
    }

    public static function calculateTotalTradeItemQuantity(Collection $trade_items): int
    {
        return $trade_items->sum(function ($purchase_item) {
            return match ($purchase_item->quantity_type) {
                QuantityType::PIECE->value => $purchase_item->quantity,
                QuantityType::CASE->value => $purchase_item->quantity * $purchase_item->capacity_case,
                QuantityType::CARTON->value => $purchase_item->quantity * $purchase_item->capacity_carton,
            };
        });
    }

    public static function hasDuplicatedTradeItem(int $partner_id, int $item_id, ?string $process_date, ?string $delivered_date, ?int $current_id, TradeCategory $trade_category) : bool
    {
        $base_table = match ($trade_category) {
            TradeCategory::EARNING => 'earnings',
            default => null,
        };
        return TradeItem::query()
            ->leftJoin('trades', 'trades.id', 'trade_items.trade_id')
            ->leftJoin('earnings', 'earnings.trade_id', 'trades.id')
            ->where('trades.partner_id', $partner_id)
            ->where('trade_items.item_id', $item_id)
            ->where('process_date', $process_date)
            ->where('trades.is_active', true)
            ->when($base_table, function ($query, $base_table) use ($current_id, $delivered_date) {
                return $query
                    ->where("{$base_table}.delivered_date", $delivered_date)
                    ->where("{$base_table}.id", '!=', $current_id);
            })
            ->exists();
    }
}
