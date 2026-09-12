<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 倉庫移動候補 HANDY送信バッチ（送信単位の冪等キー）
 */
class WmsWarehouseTransferCandidateUpload extends WmsModel
{
    protected $table = 'wms_warehouse_transfer_candidate_uploads';

    protected $fillable = [
        'candidate_id',
        'upload_uuid',
        'device_id',
        'picker_id',
        'item_count',
        'accepted_count',
        'missing_item_ids',
        'response_payload',
        'payload_hash',
    ];

    protected $casts = [
        'item_count' => 'integer',
        'accepted_count' => 'integer',
        'missing_item_ids' => 'array',
        'response_payload' => 'array',
    ];

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(WmsWarehouseTransferCandidate::class, 'candidate_id');
    }

    public function picker(): BelongsTo
    {
        return $this->belongsTo(WmsPicker::class, 'picker_id');
    }
}
