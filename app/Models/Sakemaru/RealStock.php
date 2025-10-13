<?php

namespace App\Models\Sakemaru;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RealStock extends CustomModel
{
    use HasFactory;

    protected $guarded = [];
    protected $casts = [];

    public function stock_allocation(): belongsTo
    {
        return $this->belongsTo(StockAllocation::class);
    }

    public function warehouse(): belongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function floor(): belongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    public function location(): belongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function item(): belongsTo
    {
        return $this->belongsTo(Item::class);
    }


}
