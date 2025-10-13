<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    protected $fillable = [
        'warehouse_id',
        'code',
        'aisle',
        'bay',
        'level',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function realStocks(): HasMany
    {
        return $this->hasMany(RealStock::class);
    }

    public function wmsReservations(): HasMany
    {
        return $this->hasMany(WmsReservation::class);
    }
}
