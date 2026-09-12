<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WmsIncomingPriceCheckSource extends WmsModel
{
    protected $table = 'wms_incoming_price_check_sources';

    protected $guarded = [];

    protected $casts = [
        'recorded_at' => 'datetime',
        'order_date' => 'date',
        'expected_arrival_date' => 'date',
        'received_delivery_date' => 'date',
        'current_price_mismatch' => 'boolean',
        'is_price_check_excluded' => 'boolean',
        'received_payload' => 'array',
        'schedule_payload' => 'array',
        'sent_payload' => 'array',
        'eos_payload' => 'array',
        'calculation_payload' => 'array',
    ];

    public function receivedFile(): BelongsTo
    {
        return $this->belongsTo(WmsIncomingReceivedFile::class, 'received_file_id');
    }

    public function receivedSlip(): BelongsTo
    {
        return $this->belongsTo(WmsIncomingReceivedSlip::class, 'received_slip_id');
    }

    public function receivedDetail(): BelongsTo
    {
        return $this->belongsTo(WmsIncomingReceivedDetail::class, 'received_detail_id');
    }

    public function incomingSchedule(): BelongsTo
    {
        return $this->belongsTo(WmsOrderIncomingSchedule::class, 'incoming_schedule_id');
    }
}
