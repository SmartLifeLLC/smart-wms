<?php

namespace App\Models;

use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WmsReceiptInspection extends WmsModel
{
    protected $table = 'wms_receipt_inspections';

    protected $fillable = [
        'purchase_id',
        'inspection_no',
        'warehouse_id',
        'status',
        'inspected_by',
        'inspected_at',
        'notes',
    ];

    protected $casts = [
        'inspected_at' => 'datetime',
    ];

    // Status constants
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_CANCELLED = 'CANCELLED';

    // Relationships
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Sakemaru\Purchase::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(WmsReceiptInspectionLine::class, 'inspection_id');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(WmsUser::class, 'inspected_by');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', self::STATUS_IN_PROGRESS);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeForWarehouse($query, int $warehouseId)
    {
        return $query->where('warehouse_id', $warehouseId);
    }
}
