<?php

namespace App\Models;

use App\Models\Sakemaru\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WmsLocation extends Model
{
    protected $connection = 'sakemaru';

    protected $table = 'wms_locations';

    protected $fillable = [
        'location_id',
        'picking_unit_type',
        'walking_order',
        'zone_code',
        'aisle',
        'rack',
        'level',
    ];

    protected $casts = [
        'walking_order' => 'integer',
    ];

    /**
     * Relationship to core Location model
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'id');
    }

    /**
     * Get formatted location display
     */
    public function getLocationDisplayAttribute(): string
    {
        $parts = array_filter([
            $this->aisle ? "通路:{$this->aisle}" : null,
            $this->rack ? "棚:{$this->rack}" : null,
            $this->level ? "段:{$this->level}" : null,
        ]);

        return $parts ? implode(' / ', $parts) : '未設定';
    }
}
