<?php

namespace App\Models;

use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WmsJxEosImportBatch extends WmsModel
{
    protected $table = 'wms_jx_eos_import_batches';

    public const STATUS_IMPORTING = 'importing';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SUPERSEDED = 'superseded';

    protected $guarded = [];

    protected $casts = [
        'is_current' => 'boolean',
        'stats_json' => 'array',
        'imported_at' => 'datetime',
        'superseded_at' => 'datetime',
    ];

    public static function statusLabels(): array
    {
        return [
            self::STATUS_IMPORTING => '取込中',
            self::STATUS_SUCCEEDED => '取込済',
            self::STATUS_FAILED => '失敗',
            self::STATUS_SUPERSEDED => '旧版',
        ];
    }

    public function transmissionLog(): BelongsTo
    {
        return $this->belongsTo(WmsJxTransmissionLog::class, 'wms_jx_transmission_log_id');
    }

    public function jxSetting(): BelongsTo
    {
        return $this->belongsTo(WmsOrderJxSetting::class, 'jx_setting_id');
    }

    public function detectedJxSetting(): BelongsTo
    {
        return $this->belongsTo(WmsOrderJxSetting::class, 'detected_jx_setting_id');
    }

    public function detectedContractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class, 'detected_contractor_id');
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(WmsJxEosDocument::class, 'import_batch_id');
    }

    public function slips(): HasMany
    {
        return $this->hasMany(WmsJxEosSlip::class, 'import_batch_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(WmsJxEosLine::class, 'import_batch_id');
    }

    public function getStatusLabelAttribute(): string
    {
        return self::statusLabels()[$this->status] ?? $this->status;
    }
}
