<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WmsJxEosLine extends WmsModel
{
    protected $table = 'wms_jx_eos_lines';

    protected $guarded = [];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'amount' => 'decimal:2',
        'is_shortage' => 'boolean',
    ];

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(WmsJxEosImportBatch::class, 'import_batch_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(WmsJxEosDocument::class, 'document_id');
    }

    public function slip(): BelongsTo
    {
        return $this->belongsTo(WmsJxEosSlip::class, 'slip_id');
    }

    public function transmissionLog(): BelongsTo
    {
        return $this->belongsTo(WmsJxTransmissionLog::class, 'wms_jx_transmission_log_id');
    }

    public function getRawRecordTextAttribute(): string
    {
        $raw = base64_decode((string) $this->raw_record_base64, true);

        if ($raw === false || $raw === '') {
            return '';
        }

        $text = mb_convert_encoding($raw, 'UTF-8', 'SJIS-win');

        return $text !== false ? $text : $raw;
    }
}
