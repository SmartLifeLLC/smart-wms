<?php

namespace App\Models;

use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WmsPickingItemResult extends Model
{
    protected $connection = 'sakemaru';

    protected $table = 'wms_picking_item_results';

    protected $fillable = [
        'picking_task_id',
        'trade_item_id',
        'item_id',
        'real_stock_id',
        'location_id',
        'walking_order',
        'ordered_qty',
        'ordered_qty_type',
        'planned_qty',
        'planned_qty_type',
        'picked_qty',
        'picked_qty_type',
        'shortage_qty',
        'status',
        'picker_id',
        'picked_at',
    ];

    protected $casts = [
        'walking_order' => 'integer',
        'ordered_qty' => 'decimal:2',
        'planned_qty' => 'decimal:2',
        'picked_qty' => 'decimal:2',
        'shortage_qty' => 'decimal:2',
        'picked_at' => 'datetime',
    ];

    /**
     * このピッキング明細が属するタスク
     */
    public function pickingTask(): BelongsTo
    {
        return $this->belongsTo(WmsPickingTask::class, 'picking_task_id');
    }

    /**
     * このピッキング明細の商品
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    /**
     * このピッキング明細のロケーション
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /**
     * スコープ：ピッキング順序でソート
     */
    public function scopeOrderedForPicking($query)
    {
        return $query->orderBy('walking_order', 'asc')
                     ->orderBy('item_id', 'asc');
    }

    /**
     * スコープ：未完了のアイテム
     */
    public function scopePending($query)
    {
        return $query->where('status', 'PICKING');
    }

    /**
     * Get item name with code
     */
    public function getItemNameWithCodeAttribute(): string
    {
        $item = $this->item;
        if (!$item) {
            return "Item {$this->item_id}";
        }
        return "[{$item->code}] {$item->name}";
    }

    /**
     * Get location display
     */
    public function getLocationDisplayAttribute(): string
    {
        $location = $this->location;
        if (!$location) {
            return "-";
        }
        return trim("{$location->code1} {$location->code2} {$location->code3}");
    }

    /**
     * Check if item is completed
     */
    public function isCompleted(): bool
    {
        return in_array($this->status, ['COMPLETED', 'SHORTAGE']);
    }

    /**
     * Check if item has shortage
     */
    public function hasShortage(): bool
    {
        return $this->shortage_qty > 0;
    }

    /**
     * Get quantity type display text
     */
    public static function getQuantityTypeLabel(string $type): string
    {
        return match ($type) {
            'CASE' => 'ケース',
            'PIECE' => 'バラ',
            default => $type,
        };
    }

    /**
     * Get ordered quantity type display
     */
    public function getOrderedQtyTypeDisplayAttribute(): string
    {
        return self::getQuantityTypeLabel($this->ordered_qty_type ?? 'PIECE');
    }

    /**
     * Get planned quantity type display
     */
    public function getPlannedQtyTypeDisplayAttribute(): string
    {
        return self::getQuantityTypeLabel($this->planned_qty_type ?? 'PIECE');
    }

    /**
     * Get picked quantity type display
     */
    public function getPickedQtyTypeDisplayAttribute(): string
    {
        return self::getQuantityTypeLabel($this->picked_qty_type ?? 'PIECE');
    }
}
