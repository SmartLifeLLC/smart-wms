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
    protected $casts = [
        'expiration_date' => 'date',
        'received_at' => 'datetime',
        'wms_reserved_qty' => 'integer',
        'wms_picking_qty' => 'integer',
        'wms_lock_version' => 'integer',
    ];

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

    // WMS Scopes
    public function scopeFefoFifo($query)
    {
        return $query
            ->orderByRaw('CASE WHEN expiration_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expiration_date', 'asc')
            ->orderBy('received_at', 'asc')
            ->orderBy('id', 'asc');
    }

    public function scopeAvailableForWms($query)
    {
        return $query->whereRaw('current_quantity > (wms_reserved_qty + wms_picking_qty)');
    }
}
