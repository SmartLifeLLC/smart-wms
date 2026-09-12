<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WmsJxEosDocument extends WmsModel
{
    protected $table = 'wms_jx_eos_documents';

    protected $guarded = [];

    protected $casts = [
        'processing_date' => 'date',
    ];

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(WmsJxEosImportBatch::class, 'import_batch_id');
    }

    public function transmissionLog(): BelongsTo
    {
        return $this->belongsTo(WmsJxTransmissionLog::class, 'wms_jx_transmission_log_id');
    }

    public function slips(): HasMany
    {
        return $this->hasMany(WmsJxEosSlip::class, 'document_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(WmsJxEosLine::class, 'document_id');
    }
}
