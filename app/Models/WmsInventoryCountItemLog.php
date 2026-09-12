<?php

namespace App\Models;

use App\Models\Sakemaru\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WmsInventoryCountItemLog extends WmsModel
{
    public const DEVICE_WEB = 'WEB';

    public const DEVICE_WEB_AUTO_ZERO = 'WEB_AUTO_ZERO';

    public $timestamps = false;

    protected $fillable = [
        'inventory_count_item_id',
        'device_id',
        'user_id',
        'count_round',
        'old_quantity',
        'new_quantity',
        'request_uuid',
        'created_at',
    ];

    protected $casts = [
        'old_quantity' => 'decimal:3',
        'new_quantity' => 'decimal:3',
        'created_at' => 'datetime',
    ];

    public function countItem(): BelongsTo
    {
        return $this->belongsTo(WmsInventoryCountItem::class, 'inventory_count_item_id');
    }

    public function picker(): BelongsTo
    {
        return $this->belongsTo(WmsPicker::class, 'user_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function getActorNameAttribute(): string
    {
        if ($this->device_id === self::DEVICE_WEB) {
            return $this->user?->name ? "WEB: {$this->user->name}" : 'WEB';
        }

        if ($this->device_id === self::DEVICE_WEB_AUTO_ZERO) {
            return $this->user?->name ? "未0: {$this->user->name}" : '未0';
        }

        return $this->picker?->display_name
            ?? $this->user?->name
            ?? ($this->device_id ? "HANDY: {$this->device_id}" : '不明');
    }
}
