<?php

namespace App\Models;

use App\Models\Sakemaru\Item;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WmsShipmentInspectionLine extends WmsModel
{
    protected $table = 'wms_shipment_inspection_lines';

    protected $fillable = [
        'inspection_id',
        'earning_line_id',
        'item_id',
        'expected_qty',
        'actual_qty',
        'shortage_qty',
        'notes',
    ];

    protected $casts = [
        'expected_qty' => 'integer',
        'actual_qty' => 'integer',
        'shortage_qty' => 'integer',
    ];

    // Relationships
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(WmsShipmentInspection::class, 'inspection_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    // Computed attributes
    public function getVarianceAttribute(): int
    {
        return $this->actual_qty - $this->expected_qty;
    }

    public function getHasVarianceAttribute(): bool
    {
        return $this->actual_qty !== $this->expected_qty;
    }

    public function getHasShortageAttribute(): bool
    {
        return $this->shortage_qty > 0;
    }
}
