<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAvailable extends Model
{
    protected $table = 'wms_v_stock_available';

    public $timestamps = false;

    // This is a database view, so no insert/update/delete
    public $incrementing = false;

    protected $casts = [
        'expiration_date' => 'date',
        'received_at' => 'datetime',
        'unit_cost' => 'decimal:4',
        'current_quantity' => 'integer',
        'available_for_wms' => 'integer',
        'wms_reserved_qty' => 'integer',
        'wms_picking_qty' => 'integer',
    ];

    // Relationships
    public function realStock(): BelongsTo
    {
        return $this->belongsTo(RealStock::class, 'real_stock_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    // Scopes
    public function scopeAvailable($query)
    {
        return $query->where('available_for_wms', '>', 0);
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
            ->orderBy('real_stock_id', 'asc');
    }
}
