<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WmsPickingArea extends Model
{
    protected $connection = 'sakemaru';
    protected $table = 'wms_picking_areas';

    protected $fillable = [
        'warehouse_id',
        'code',
        'name',
        'display_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'display_order' => 'integer',
    ];

    /**
     * このピッキングエリアに属するロケーション
     */
    public function wmsLocations(): HasMany
    {
        return $this->hasMany(WmsLocation::class, 'wms_picking_area_id');
    }
}
