<?php

namespace App\Models;

use App\Models\Sakemaru\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WmsInventoryCountItem extends WmsModel
{
    protected $fillable = [
        'inventory_count_id',
        'real_stock_id',
        'item_id',
        'item_code',
        'item_name',
        'barcode',
        'location_id',
        'floor_id',
        'floor_name',
        'location_code1',
        'location_code2',
        'location_code3',
        'location_no',
        'lot_id',
        'lot_no',
        'expiration_date',
        'received_at',
        'system_quantity',
        'post_count_movement_quantity',
        'ending_system_quantity',
        'first_count_quantity',
        'first_count_actor_name',
        'second_count_quantity',
        'second_count_actor_name',
        'final_count_quantity',
        'final_count_actor_name',
        'difference_quantity',
        'cost_price',
        'difference_amount',
        'first_count_confirmed_system_quantity',
        'first_count_confirmed_difference_quantity',
        'first_count_confirmed_difference_amount',
        'second_count_confirmed_system_quantity',
        'second_count_confirmed_difference_quantity',
        'second_count_confirmed_difference_amount',
        'final_count_confirmed_system_quantity',
        'final_count_confirmed_difference_quantity',
        'final_count_confirmed_difference_amount',
        'input_count',
        'last_counted_at',
    ];

    protected $casts = [
        'expiration_date' => 'date',
        'received_at' => 'datetime',
        'system_quantity' => 'integer',
        'post_count_movement_quantity' => 'integer',
        'ending_system_quantity' => 'integer',
        'first_count_quantity' => 'integer',
        'second_count_quantity' => 'integer',
        'final_count_quantity' => 'integer',
        'difference_quantity' => 'integer',
        'cost_price' => 'decimal:4',
        'difference_amount' => 'decimal:2',
        'first_count_confirmed_system_quantity' => 'integer',
        'first_count_confirmed_difference_quantity' => 'integer',
        'first_count_confirmed_difference_amount' => 'decimal:2',
        'second_count_confirmed_system_quantity' => 'integer',
        'second_count_confirmed_difference_quantity' => 'integer',
        'second_count_confirmed_difference_amount' => 'decimal:2',
        'final_count_confirmed_system_quantity' => 'integer',
        'final_count_confirmed_difference_quantity' => 'integer',
        'final_count_confirmed_difference_amount' => 'decimal:2',
        'last_counted_at' => 'datetime',
    ];

    public function inventoryCount(): BelongsTo
    {
        return $this->belongsTo(WmsInventoryCount::class, 'inventory_count_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(WmsInventoryCountItemLog::class, 'inventory_count_item_id');
    }

    public function latestLog()
    {
        return $this->hasOne(WmsInventoryCountItemLog::class, 'inventory_count_item_id')->latestOfMany();
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function scopeWithoutOwnedSetItems(Builder $query): Builder
    {
        if (! Schema::connection('sakemaru')->hasTable('item_sets')
            || ! Schema::connection('sakemaru')->hasColumn('items', 'item_set_id')
        ) {
            return $query;
        }

        $table = $this->getTable();

        return $query->whereNotExists(function ($query) use ($table): void {
            $query
                ->select(DB::raw(1))
                ->from('items as owned_set_items')
                ->join('item_sets as owned_item_sets', function ($join): void {
                    $join
                        ->on('owned_item_sets.id', '=', 'owned_set_items.item_set_id')
                        ->where('owned_item_sets.is_active', true)
                        ->where('owned_item_sets.set_type', 'OWNED');
                })
                ->whereColumn('owned_set_items.id', "{$table}.item_id");
        });
    }

    public function scopeManagedStockItems(Builder $query): Builder
    {
        if (! Schema::connection('sakemaru')->hasColumn('items', 'is_managed_stock')) {
            return $query;
        }

        $table = $this->getTable();

        return $query->whereNotExists(function ($query) use ($table): void {
            $query
                ->select(DB::raw(1))
                ->from('items as unmanaged_stock_items')
                ->whereColumn('unmanaged_stock_items.id', "{$table}.item_id")
                ->where('unmanaged_stock_items.is_managed_stock', false);
        });
    }

    public function scopeUnmanagedStockItems(Builder $query): Builder
    {
        if (! Schema::connection('sakemaru')->hasColumn('items', 'is_managed_stock')) {
            return $query->whereRaw('1 = 0');
        }

        $table = $this->getTable();

        return $query->whereExists(function ($query) use ($table): void {
            $query
                ->select(DB::raw(1))
                ->from('items as unmanaged_stock_items')
                ->whereColumn('unmanaged_stock_items.id', "{$table}.item_id")
                ->where('unmanaged_stock_items.is_managed_stock', false);
        });
    }

    public function calculateDifference(): ?float
    {
        $finalQty = $this->final_count_quantity
            ?? $this->second_count_quantity
            ?? $this->first_count_quantity;

        if ($finalQty === null) {
            return null;
        }

        return (float) $finalQty - (float) $this->system_quantity;
    }

    public function roundDifference(int $round): ?float
    {
        $confirmedDifference = $this->confirmedRoundDifference($round);
        if ($confirmedDifference !== null) {
            return $confirmedDifference;
        }

        $quantity = $this->roundQuantity($round);

        if ($quantity === null) {
            return null;
        }

        $baseQuantity = $this->ending_system_quantity ?? $this->system_quantity;

        return (float) $quantity - (float) $baseQuantity;
    }

    public function roundQuantity(int $round): ?int
    {
        return match ($round) {
            1 => $this->first_count_quantity,
            2 => $this->second_count_quantity ?? $this->first_count_quantity,
            3 => $this->final_count_quantity,
            default => null,
        };
    }

    public function confirmedRoundSystemQuantity(int $round): ?float
    {
        $column = match ($round) {
            1 => 'first_count_confirmed_system_quantity',
            2 => 'second_count_confirmed_system_quantity',
            3 => 'final_count_confirmed_system_quantity',
            default => null,
        };

        if ($column === null || $this->getAttribute($column) === null) {
            return null;
        }

        return (float) $this->getAttribute($column);
    }

    public function confirmedRoundDifference(int $round): ?float
    {
        $column = match ($round) {
            1 => 'first_count_confirmed_difference_quantity',
            2 => 'second_count_confirmed_difference_quantity',
            3 => 'final_count_confirmed_difference_quantity',
            default => null,
        };

        if ($column === null || $this->getAttribute($column) === null) {
            return null;
        }

        return (float) $this->getAttribute($column);
    }
}
