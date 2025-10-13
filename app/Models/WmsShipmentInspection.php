<?php

namespace App\Models;

use App\Models\Sakemaru\Earning;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WmsShipmentInspection extends WmsModel
{
    protected $table = 'wms_shipment_inspections';

    protected $fillable = [
        'earning_id',
        'warehouse_id',
        'inspection_date',
        'inspector_id',
        'status',
        'notes',
    ];

    protected $casts = [
        'inspection_date' => 'date',
    ];

    // Status constants
    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_PARTIAL = 'partial';

    // Relationships
    public function earning(): BelongsTo
    {
        return $this->belongsTo(Earning::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(WmsShipmentInspectionLine::class, 'inspection_id');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(WmsUser::class, 'inspector_id');
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
