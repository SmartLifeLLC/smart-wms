<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WmsIncomingReceivedSlip extends WmsModel
{
    protected $table = 'wms_incoming_received_slips';

    protected $fillable = [
        'received_file_id',
        'slip_number',
        'match_status',
        'b_data_type',
        'b_shop_code',
        'b_category_code',
        'b_slip_type',
        'b_order_date',
        'b_delivery_date',
        'b_delivery_route',
        'b_contractor_code',
        'b_shop_name',
        'b_delivery_place',
        'b_note',
        'b_direct_type',
        'matched_schedule_id',
        'detail_count',
        'shortage_count',
    ];

    protected $casts = [
        'detail_count' => 'integer',
        'shortage_count' => 'integer',
    ];

    public function file(): BelongsTo
    {
        return $this->belongsTo(WmsIncomingReceivedFile::class, 'received_file_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(WmsIncomingReceivedDetail::class, 'received_slip_id');
    }

    public function importErrors(): HasMany
    {
        return $this->hasMany(WmsIncomingImportError::class, 'received_slip_id');
    }

    public function matchedSchedule(): BelongsTo
    {
        return $this->belongsTo(WmsOrderIncomingSchedule::class, 'matched_schedule_id');
    }
}
