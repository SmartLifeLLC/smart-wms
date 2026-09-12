<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WmsOrderSlipNumberAssignment extends WmsModel
{
    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_TRANSMITTED = 'TRANSMITTED';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected $table = 'wms_order_slip_number_assignments';

    protected $fillable = [
        'wms_order_jx_document_id',
        'document_type',
        'slip_number',
        'store_code',
        'year_code',
        'sequence_no',
        'b_record_sequence',
        'status',
        'order_candidate_ids',
    ];

    protected $casts = [
        'wms_order_jx_document_id' => 'integer',
        'year_code' => 'integer',
        'sequence_no' => 'integer',
        'b_record_sequence' => 'integer',
        'order_candidate_ids' => 'array',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(WmsOrderJxDocument::class, 'wms_order_jx_document_id');
    }
}
