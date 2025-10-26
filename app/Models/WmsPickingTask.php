<?php

namespace App\Models;

use App\Models\Sakemaru\Earning;
use App\Models\Sakemaru\Trade;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WmsPickingTask extends Model
{
    protected $connection = 'sakemaru';

    protected $table = 'wms_picking_tasks';

    protected $fillable = [
        'wave_id',
        'wms_picking_area_id',
        'warehouse_id',
        'earning_id',
        'trade_id',
        'status',
        'task_type',
        'picker_id',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * このタスクが属するウェーブ
     */
    public function wave(): BelongsTo
    {
        return $this->belongsTo(Wave::class, 'wave_id');
    }

    /**
     * このタスクが属するピッキングエリア
     */
    public function pickingArea(): BelongsTo
    {
        return $this->belongsTo(WmsPickingArea::class, 'wms_picking_area_id');
    }

    /**
     * このタスクが属する倉庫
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * このタスクが属する出荷伝票
     */
    public function earning(): BelongsTo
    {
        return $this->belongsTo(Earning::class, 'earning_id');
    }

    /**
     * このタスクが属する取引
     */
    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class, 'trade_id');
    }

    /**
     * このタスクのピッキング明細
     */
    public function pickingItemResults(): HasMany
    {
        return $this->hasMany(WmsPickingItemResult::class, 'picking_task_id');
    }

    /**
     * ピッカー（担当者）
     */
    public function picker(): BelongsTo
    {
        return $this->belongsTo(WmsPicker::class, 'picker_id');
    }

    /**
     * スコープ：未割当タスク
     */
    public function scopeUnassigned($query)
    {
        return $query->whereNull('picker_id');
    }

    /**
     * スコープ：進行中ステータス
     */
    public function scopeInProgress($query)
    {
        return $query->whereIn('status', ['PENDING', 'PICKING']);
    }

    /**
     * Get display-friendly wave code
     */
    public function getWaveCodeAttribute(): string
    {
        return $this->wave->wave_code ?? "Wave {$this->wave_id}";
    }

    /**
     * Get earning number
     */
    public function getEarningNumberAttribute(): string
    {
        return $this->earning->earning_no ?? "E{$this->earning_id}";
    }

    /**
     * Get total item count for this task
     */
    public function getItemCountAttribute(): int
    {
        return $this->pickingItemResults()->count();
    }
}
