<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RealStock extends Model
{
    protected $table = 'real_stocks';

    protected $fillable = [
        'client_id',
        'warehouse_id',
        'stock_allocation_id',
        'location_id',
        'item_id',
        'lot_no',
        'expiration_date',
        'received_at',
        'purchase_id',
        'price',
        'current_quantity',
        'available_quantity',
        'wms_reserved_qty',
        'wms_picking_qty',
        'wms_lock_version',
    ];

    protected $casts = [
        'expiration_date' => 'date',
        'received_at' => 'datetime',
        'price' => 'decimal:4',
        'current_quantity' => 'integer',
        'available_quantity' => 'integer',
        'wms_reserved_qty' => 'integer',
        'wms_picking_qty' => 'integer',
        'wms_lock_version' => 'integer',
    ];

    // Relationships
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function wmsReservations(): HasMany
    {
        return $this->hasMany(WmsReservation::class, 'real_stock_id');
    }

    // Calculate available for WMS
    public function getAvailableForWmsAttribute(): int
    {
        return max(0, $this->available_quantity - ($this->wms_reserved_qty + $this->wms_picking_qty));
    }

    // Scopes
    public function scopeAvailableForWms($query)
    {
        return $query->whereRaw('available_quantity - (wms_reserved_qty + wms_picking_qty) > 0');
    }

    public function scopeForWarehouse($query, int $warehouseId)
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    public function scopeForItem($query, int $itemId)
    {
        return $query->where('item_id', $itemId);
    }

    /**
     * Sort by FEFO (First Expiry First Out) then FIFO (First In First Out)
     */
    public function scopeFefoFifo($query)
    {
        return $query
            ->orderByRaw('CASE WHEN expiration_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expiration_date', 'asc')
            ->orderBy('received_at', 'asc')
            ->orderBy('id', 'asc');
    }
}
