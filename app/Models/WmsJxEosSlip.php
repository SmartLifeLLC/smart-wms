<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WmsJxEosSlip extends WmsModel
{
    protected $table = 'wms_jx_eos_slips';

    protected $guarded = [];

    protected $casts = [
        'order_date' => 'date',
        'delivery_date' => 'date',
        'is_return_slip' => 'boolean',
        'is_shipment_slip' => 'boolean',
    ];

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(WmsJxEosImportBatch::class, 'import_batch_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(WmsJxEosDocument::class, 'document_id');
    }

    public function transmissionLog(): BelongsTo
    {
        return $this->belongsTo(WmsJxTransmissionLog::class, 'wms_jx_transmission_log_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(WmsJxEosLine::class, 'slip_id');
    }
}
