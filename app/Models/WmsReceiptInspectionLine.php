<?php

namespace App\Models;

use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Location;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WmsReceiptInspectionLine extends WmsModel
{
    protected $table = 'wms_receipt_inspection_lines';

    protected $fillable = [
        'inspection_id',
        'item_id',
        'location_id',
        'lot_no',
        'expiration_date',
        'expected_qty',
        'actual_qty',
        'unit_cost',
        'notes',
    ];

    protected $casts = [
        'expiration_date' => 'date',
        'expected_qty' => 'integer',
        'actual_qty' => 'integer',
        'unit_cost' => 'decimal:4',
    ];

    // Relationships
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(WmsReceiptInspection::class, 'inspection_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
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
}
