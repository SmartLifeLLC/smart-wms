<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WmsInventoryCountRescueData extends Model
{
    protected $connection = 'sakemaru';

    protected $table = 'wms_inventory_count_rescue_data';

    protected $fillable = [
        'upload_uuid',
        'original_count_id',
        'original_count_no',
        'count_round',
        'device_id',
        'user_id',
        'warehouse_id',
        'items',
        'item_count',
        'status',
        'processed_count_id',
        'note',
    ];

    protected $casts = [
        'items' => 'array',
        'item_count' => 'integer',
        'original_count_id' => 'integer',
        'count_round' => 'integer',
        'user_id' => 'integer',
        'warehouse_id' => 'integer',
        'processed_count_id' => 'integer',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_REJECTED = 'rejected';
}
