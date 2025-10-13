<?php

namespace App\Models\Sakemaru;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ClosingDaily extends CustomModel
{
    use HasFactory;

    protected $guarded = [];
    protected $casts = [];

    public function previousClosing(): null|self
    {
        $prev_closing_daily = ClosingDaily::whereDate('closing_date', $this->closing_date)
            ->where('number', '<', $this->number)
            ->first();
        $prev_closing_daily ??= ClosingDaily::whereDate('closing_date', '<', $this->closing_date)
            ->orderBy('closing_date', 'desc')
            ->orderBy('number', 'desc')
            ->first();

        return $prev_closing_daily ? self::cast($prev_closing_daily) : null;
    }

    public static function lastClosing(?string $base_date = null): ?self
    {
        $closing =  self::query()
            ->when($base_date, function ($query, $base_date) {
                $query->where('closing_date', '<=', $base_date);
            })
            ->orderBy('closing_date', 'desc')
            ->orderBy('number', 'desc')
            ->first();
        return $closing ? self::cast($closing) : null;
    }

    public static function closingEndOfMonth(int $year, int $month) : ?self
    {
        $date = Carbon::createFromDate($year, $month, 1)->endOfMonth()->toDateString();
        $closing = ClosingDaily::whereDate('closing_date', '<=', $date)
            ->orderBy('closing_date', 'desc')
            ->orderBy('number', 'desc')
            ->first();

        return $closing ? ClosingDaily::cast($closing) : null;
    }


    public function stock_overviews(): HasMany
    {
        return $this->hasMany(DailyStockOverview::class);
    }
}
