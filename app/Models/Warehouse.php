<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    protected $fillable = [
        'name',
        'code',
    ];

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
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
