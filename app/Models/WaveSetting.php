<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WaveSetting extends Model
{
    protected $connection = 'sakemaru';

    protected $table = 'wms_wave_settings';

    protected $fillable = [
        'warehouse_id',
        'delivery_course_id',
        'picking_start_time',
        'picking_deadline_time',
        'creator_id',
        'last_updater_id',
    ];

    protected $casts = [
        'picking_start_time' => 'datetime:H:i:s',
        'picking_deadline_time' => 'datetime:H:i:s',
    ];

    public function waves(): HasMany
    {
        return $this->hasMany(Wave::class, 'wms_wave_setting_id');
    }
}
