<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WmsJxOrderGenerationRun extends WmsModel
{
    public const STATUS_RUNNING = 'RUNNING';

    public const STATUS_SUCCESS = 'SUCCESS';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_SKIPPED = 'SKIPPED';

    protected $table = 'wms_jx_order_generation_runs';

    protected $fillable = [
        'representative_contractor_id',
        'target_date',
        'generation_time',
        'cutoff_time',
        'status',
        'candidate_count',
        'eligible_candidate_count',
        'adjusted_candidate_count',
        'generated_document_count',
        'generated_order_count',
        'summary',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'target_date' => 'date',
        'candidate_count' => 'integer',
        'eligible_candidate_count' => 'integer',
        'adjusted_candidate_count' => 'integer',
        'generated_document_count' => 'integer',
        'generated_order_count' => 'integer',
        'summary' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function representativeContractor(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Sakemaru\Contractor::class, 'representative_contractor_id');
    }
}
