<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpecificSlipPrintRequestQueue extends Model
{
    protected $connection = 'sakemaru';

    protected $table = 'print_specific_slip_request_queue';

    protected $fillable = [
        'client_id',
        'warehouse_id',
        'slip_type_id',
        'earning_ids',
        'status',
        'requested_by',
        'log_pdf_export_id',
        'idempotency_key',
        'error_message',
        'processed_at',
    ];

    protected $casts = [
        'earning_ids' => 'array',
        'processed_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';
}
