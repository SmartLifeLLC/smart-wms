<?php

namespace App\Models;

use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Location;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WmsIncomingAppInspectionDetail extends WmsModel
{
    protected $table = 'wms_incoming_app_inspection_details';

    public const POLICY_APP_CONFIRM_ALLOWED = 'APP_CONFIRM_ALLOWED';

    public const POLICY_EOS_HISTORY_ONLY = 'EOS_HISTORY_ONLY';

    public const POLICY_EOS_ALREADY_CONFIRMED = 'EOS_ALREADY_CONFIRMED';

    public const POLICY_TRANSFER_WEB_ONLY = 'TRANSFER_WEB_ONLY';

    public const POLICY_PURCHASE_TRANSMITTED_LOCKED = 'PURCHASE_TRANSMITTED_LOCKED';

    public const POLICY_NEEDS_REVIEW = 'NEEDS_REVIEW';

    public const RESULT_HISTORY_ONLY = 'HISTORY_ONLY';

    public const RESULT_CONFIRMED = 'CONFIRMED';

    public const RESULT_APP_UNPLANNED_CREATED = 'APP_UNPLANNED_CREATED';

    public const RESULT_EOS_ALREADY_CONFIRMED = 'EOS_ALREADY_CONFIRMED';

    public const RESULT_NEEDS_REVIEW = 'NEEDS_REVIEW';

    public const RESULT_ERROR = 'ERROR';

    protected $fillable = [
        'batch_id',
        'client_line_uuid',
        'warehouse_id',
        'incoming_schedule_id',
        'linked_confirmed_schedule_id',
        'created_schedule_id',
        'item_id',
        'item_code',
        'item_name',
        'scanned_code',
        'slip_number',
        'contractor_id',
        'supplier_id',
        'location_id',
        'inspection_policy',
        'result_status',
        'review_reason',
        'expected_piece_quantity',
        'inspected_case_quantity',
        'inspected_piece_quantity',
        'inspected_total_piece_quantity',
        'applied_piece_quantity',
        'shortage_piece_quantity',
        'capacity_case',
        'expiration_date',
        'inspected_at',
        'raw_payload',
    ];

    protected $casts = [
        'expected_piece_quantity' => 'integer',
        'inspected_case_quantity' => 'integer',
        'inspected_piece_quantity' => 'integer',
        'inspected_total_piece_quantity' => 'integer',
        'applied_piece_quantity' => 'integer',
        'shortage_piece_quantity' => 'integer',
        'capacity_case' => 'integer',
        'expiration_date' => 'date',
        'inspected_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(WmsIncomingAppInspectionBatch::class, 'batch_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function incomingSchedule(): BelongsTo
    {
        return $this->belongsTo(WmsOrderIncomingSchedule::class, 'incoming_schedule_id');
    }

    public function linkedConfirmedSchedule(): BelongsTo
    {
        return $this->belongsTo(WmsOrderIncomingSchedule::class, 'linked_confirmed_schedule_id');
    }

    public function createdSchedule(): BelongsTo
    {
        return $this->belongsTo(WmsOrderIncomingSchedule::class, 'created_schedule_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
