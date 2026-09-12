<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 倉庫移動候補 HANDY送信行（監査ログ・行単位の冪等キー）
 */
class WmsWarehouseTransferCandidateItemSource extends WmsModel
{
    protected $table = 'wms_warehouse_transfer_candidate_item_sources';

    protected $fillable = [
        'candidate_id',
        'candidate_item_id',
        'upload_id',
        'source_request_uuid',
        'real_stock_id',
        'case_quantity',
        'piece_quantity',
        'package_quantity',
        'transfer_quantity',
        'scanned_code',
    ];

    protected $casts = [
        'case_quantity' => 'decimal:3',
        'piece_quantity' => 'decimal:3',
        'package_quantity' => 'integer',
        'transfer_quantity' => 'decimal:3',
    ];

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(WmsWarehouseTransferCandidate::class, 'candidate_id');
    }

    public function candidateItem(): BelongsTo
    {
        return $this->belongsTo(WmsWarehouseTransferCandidateItem::class, 'candidate_item_id');
    }

    public function upload(): BelongsTo
    {
        return $this->belongsTo(WmsWarehouseTransferCandidateUpload::class, 'upload_id');
    }
}
