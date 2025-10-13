<?php

namespace App\Models\Sakemaru;

use App\Models\Sakemaru\ClientCalendar;

use App\Models\Sakemaru\WarehouseContractor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends CustomModel
{
    use HasFactory;
    protected $guarded = [];
    protected $casts = [];

    public function client_calendar(): BelongsTo
    {
        return $this->belongsTo(ClientCalendar::class);
    }

    public function warehouse_contractors() : HasMany
    {
        return $this->hasMany(WarehouseContractor::class);
    }
}
