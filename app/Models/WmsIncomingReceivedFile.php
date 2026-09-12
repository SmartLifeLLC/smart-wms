<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class WmsIncomingReceivedFile extends WmsModel
{
    protected $table = 'wms_incoming_received_files';

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_MATCHED = 'MATCHED';

    public const STATUS_APPLIED = 'APPLIED';

    public const STATUS_ERROR = 'ERROR';

    public const STATUS_SKIPPED = 'SKIPPED';

    public const TERMINAL_STATUSES = [
        self::STATUS_APPLIED,
        self::STATUS_SKIPPED,
    ];

    protected $fillable = [
        'contractor_id',
        'filename',
        'raw_file_path',
        'raw_file_size',
        'raw_sha256',
        'received_message_id',
        'get_request_path',
        'get_response_path',
        'confirm_status',
        'confirmed_at',
        'confirm_request_path',
        'confirm_response_path',
        'confirm_error_message',
        'format_type',
        'status',
        'a_data_type',
        'a_send_receive_type',
        'a_created_date',
        'a_created_time',
        'a_record_count',
        'a_slip_count',
        'a_company_name',
        'has_finet_wrapper',
        'finet_sender_code',
        'finet_sender_name',
        'finet_record_count',
        'parsed_slip_count',
        'parsed_detail_count',
        'error_message',
        'received_by',
    ];

    protected $casts = [
        'has_finet_wrapper' => 'boolean',
        'a_record_count' => 'integer',
        'a_slip_count' => 'integer',
        'finet_record_count' => 'integer',
        'parsed_slip_count' => 'integer',
        'parsed_detail_count' => 'integer',
        'raw_file_size' => 'integer',
        'confirmed_at' => 'datetime',
    ];

    public static function onlyExistingColumns(array $attributes): array
    {
        static $columns = null;

        if ($columns === null) {
            $model = new static;
            $columns = array_flip(Schema::connection($model->getConnectionName())->getColumnListing($model->getTable()));
        }

        return array_intersect_key($attributes, $columns);
    }

    public function slips(): HasMany
    {
        return $this->hasMany(WmsIncomingReceivedSlip::class, 'received_file_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(WmsIncomingReceivedDetail::class, 'received_file_id');
    }

    public function contractor()
    {
        return $this->belongsTo(\App\Models\Sakemaru\Contractor::class);
    }

    public function isWorkflowTerminal(): bool
    {
        return in_array((string) $this->status, self::TERMINAL_STATUSES, true);
    }

    public function isSkipped(): bool
    {
        return (string) $this->status === self::STATUS_SKIPPED;
    }
}
