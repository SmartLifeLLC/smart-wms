<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Item extends Model
{
    protected $fillable = [
        'name',
        'code',
        'sku',
    ];

    public function realStocks(): HasMany
    {
        return $this->hasMany(RealStock::class);
    }

    public function wmsReservations(): HasMany
    {
        return $this->hasMany(WmsReservation::class);
    }
}
