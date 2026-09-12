<?php

namespace App\Models;

use App\Enums\WarehouseTransferCandidateStatus;
use App\Models\Sakemaru\DeliveryCourse;
use App\Models\Sakemaru\StockTransferQueue;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 倉庫移動候補（HANDY / Web 起点の任意倉庫間移動）
 */
class WmsWarehouseTransferCandidate extends WmsModel
{
    public const SOURCE_HANDY = 'HANDY';

    public const SOURCE_WEB = 'WEB';

    protected $table = 'wms_warehouse_transfer_candidates';

    protected $fillable = [
        'candidate_no',
        'client_id',
        'source_type',
        'from_warehouse_id',
        'from_warehouse_code',
        'from_warehouse_name',
        'to_warehouse_id',
        'to_warehouse_code',
        'to_warehouse_name',
        'delivery_course_id',
        'process_date',
        'delivered_date',
        'status',
        'submitted_by_picker_id',
        'submitted_device_id',
        'submitted_at',
        'confirmed_by',
        'confirmed_at',
        'cancelled_by',
        'cancelled_at',
        'queue_request_id',
        'stock_transfer_queue_id',
        'stock_transfer_id',
        'queue_error_message',
        'memo',
    ];

    protected $casts = [
        'process_date' => 'date',
        'delivered_date' => 'date',
        'submitted_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'status' => WarehouseTransferCandidateStatus::class,
    ];

    public function items(): HasMany
    {
        return $this->hasMany(WmsWarehouseTransferCandidateItem::class, 'candidate_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function itemSources(): HasMany
    {
        return $this->hasMany(WmsWarehouseTransferCandidateItemSource::class, 'candidate_id');
    }

    public function uploads(): HasMany
    {
        return $this->hasMany(WmsWarehouseTransferCandidateUpload::class, 'candidate_id');
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function deliveryCourse(): BelongsTo
    {
        return $this->belongsTo(DeliveryCourse::class, 'delivery_course_id');
    }

    public function submittedByPicker(): BelongsTo
    {
        return $this->belongsTo(WmsPicker::class, 'submitted_by_picker_id');
    }

    public function confirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function stockTransferQueue(): BelongsTo
    {
        return $this->belongsTo(StockTransferQueue::class, 'stock_transfer_queue_id');
    }

    public function isPending(): bool
    {
        return $this->status === WarehouseTransferCandidateStatus::PENDING;
    }

    public function isEditable(): bool
    {
        return $this->status?->isEditable() ?? false;
    }

    public function getStatusLabelAttribute(): string
    {
        return $this->status?->label() ?? '-';
    }

    public function getQueueRequestIdForCandidate(): string
    {
        return "wms-warehouse-transfer-{$this->id}";
    }

    /**
     * queue 状態のラベル（一覧/詳細表示用）
     */
    public function queueStatusLabel(?object $queue): string
    {
        if (! $queue) {
            return '未投入';
        }

        return match ($queue->status) {
            'BEFORE' => 'queue待ち',
            'PROCESSING' => '処理中',
            'FINISHED' => ((int) ($queue->is_success ?? 0)) === 1 ? '伝票作成済' : '伝票作成失敗',
            default => (string) $queue->status,
        };
    }
}
