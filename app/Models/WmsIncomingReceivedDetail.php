<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WmsIncomingReceivedDetail extends WmsModel
{
    protected $table = 'wms_incoming_received_details';

    protected $fillable = [
        'received_slip_id',
        'received_file_id',
        'd_data_type',
        'd_line_number',
        'd_product_name',
        'd_jan_code',
        'd_item_code',
        'd_pack_quantity',
        'd_case_quantity',
        'd_piece_quantity',
        'd_unit_price',
        'd_total_pieces',
        'd_note',
        'd_amount',
        'total_quantity',
        'is_shortage',
        'match_status',
        'matched_item_id',
        'matched_schedule_id',
        'expected_quantity',
    ];

    protected $casts = [
        'd_line_number' => 'integer',
        'd_pack_quantity' => 'integer',
        'd_case_quantity' => 'integer',
        'd_piece_quantity' => 'integer',
        'd_unit_price' => 'integer',
        'd_amount' => 'integer',
        'total_quantity' => 'integer',
        'is_shortage' => 'boolean',
        'matched_schedule_id' => 'integer',
        'expected_quantity' => 'integer',
    ];

    public function slip(): BelongsTo
    {
        return $this->belongsTo(WmsIncomingReceivedSlip::class, 'received_slip_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(WmsIncomingReceivedFile::class, 'received_file_id');
    }

    public function matchedItem(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Sakemaru\Item::class, 'matched_item_id');
    }

    public function matchedSchedule(): BelongsTo
    {
        return $this->belongsTo(WmsOrderIncomingSchedule::class, 'matched_schedule_id');
    }
}
