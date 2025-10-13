<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WmsReservation extends Model
{
    protected $table = 'wms_reservations';

    protected $fillable = [
        'client_id',
        'warehouse_id',
        'location_id',
        'real_stock_id',
        'item_id',
        'expiry_date',
        'received_at',
        'purchase_id',
        'unit_cost',
        'qty_each',
        'source_type',
        'source_id',
        'source_line_id',
        'wave_id',
        'status',
        'created_by',
    ];

    protected $casts = [
        'expiry_date' => 'date',
        'received_at' => 'datetime',
        'unit_cost' => 'decimal:4',
        'qty_each' => 'integer',
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

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    // Status constants
    public const STATUS_RESERVED = 'RESERVED';
    public const STATUS_RELEASED = 'RELEASED';
    public const STATUS_CONSUMED = 'CONSUMED';
    public const STATUS_CANCELLED = 'CANCELLED';

    // Source type constants
    public const SOURCE_EARNING = 'EARNING';
    public const SOURCE_PURCHASE = 'PURCHASE';
    public const SOURCE_REPLENISH = 'REPLENISH';
    public const SOURCE_COUNT = 'COUNT';
    public const SOURCE_MOVE = 'MOVE';

    // Scopes
    public function scopeReserved($query)
    {
        return $query->where('status', self::STATUS_RESERVED);
    }

    public function scopeForWarehouse($query, int $warehouseId)
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    public function scopeForItem($query, int $itemId)
    {
        return $query->where('item_id', $itemId);
    }
}
