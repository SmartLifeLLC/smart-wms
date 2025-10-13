<?php

namespace App\Models\Sakemaru;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ClosingRebate extends CustomModel
{

    protected $guarded = [];
    protected $casts = [];

    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class);
    }

    public function rebate_calculations(): HasMany
    {
        return $this->hasMany(RebateCalculation::class);
    }

    public function balance_overviews(): MorphMany
    {
        return $this->morphMany(ClosingBalanceOverview::class, 'closing');
    }

    public function previousClosing(): null|self
    {
        return ClosingRebate::whereDate('closing_date', '<', $this->closing_date)
            ->orderBy('closing_date', 'desc')->first();
    }

    public static function lastClosing(): ?self
    {
        $closing = ClosingRebate::orderBy('closing_date', 'desc')->first();
        return $closing ? ClosingRebate::cast($closing) : null;
    }
}
