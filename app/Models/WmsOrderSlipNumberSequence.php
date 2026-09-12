<?php

namespace App\Models;

class WmsOrderSlipNumberSequence extends WmsModel
{
    protected $table = 'wms_order_slip_number_sequences';

    protected $fillable = [
        'document_type',
        'warehouse_id',
        'store_code',
        'year_code',
        'current_sequence',
    ];

    protected $casts = [
        'warehouse_id' => 'integer',
        'year_code' => 'integer',
        'current_sequence' => 'integer',
    ];
}
