<?php

namespace App\Models;

use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WmsIncomingAppInspectionBatch extends WmsModel
{
    protected $table = 'wms_incoming_app_inspection_batches';

    public const STATUS_RECEIVED = 'RECEIVED';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_PARTIAL_FAILED = 'PARTIAL_FAILED';

    protected $fillable = [
        'client_batch_uuid',
        'warehouse_id',
        'inspection_date',
        'inspected_at',
        'inspected_by',
        'picker_id',
        'device_id',
        'app_version',
        'status',
        'total_detail_count',
        'success_count',
        'history_only_count',
        'review_count',
        'error_count',
        'payload_hash',
        'note',
    ];

    protected $casts = [
        'inspection_date' => 'date',
        'inspected_at' => 'datetime',
        'total_detail_count' => 'integer',
        'success_count' => 'integer',
        'history_only_count' => 'integer',
        'review_count' => 'integer',
        'error_count' => 'integer',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    public function picker(): BelongsTo
    {
        return $this->belongsTo(WmsPicker::class, 'picker_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(WmsIncomingAppInspectionDetail::class, 'batch_id');
    }
}
