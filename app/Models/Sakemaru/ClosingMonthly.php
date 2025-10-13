<?php

namespace App\Models\Sakemaru;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class ClosingMonthly extends CustomModel
{
    use HasFactory;

    protected $guarded = [];
    protected $casts = [];

    public function trades(): HasMany
    {
        return $this->HasMany(Trade::class);
    }

    public function balance_overviews(): MorphMany
    {
        return $this->morphMany(ClosingBalanceOverview::class, 'closing');
    }

    public function stock_overviews(): HasMany
    {
        return $this->hasMany(MonthlyStockOverview::class);
    }

    public function previousClosing(): null|self
    {
        return ClosingMonthly::whereDate('closing_date', '<', $this->closing_date)
            ->orderBy('closing_date', 'desc')->first();
    }

    public static function lastClosing(?string $base_date = null): ?self
    {
        $closing = ClosingMonthly::query()
            ->when($base_date, function ($query, $base_date) {
                $query->where('closing_date', '<', $base_date);
            })
            ->orderBy('closing_date', 'desc')
            ->first();
        return $closing ? ClosingMonthly::cast($closing) : null;
    }
}
