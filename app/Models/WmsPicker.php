<?php

namespace App\Models;

use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

class WmsPicker extends Model
{
    use HasApiTokens;

    protected $connection = 'sakemaru';

    protected $table = 'wms_pickers';

    protected $fillable = [
        'code',
        'name',
        'password',
        'default_warehouse_id',
        'is_active',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * デフォルト倉庫
     */
    public function defaultWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'default_warehouse_id');
    }

    /**
     * このピッカーが担当しているタスク
     */
    public function pickingTasks(): HasMany
    {
        return $this->hasMany(WmsPickingTask::class, 'picker_id');
    }

    /**
     * スコープ：有効なピッカーのみ
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * ピッカーの表示名を取得
     */
    public function getDisplayNameAttribute(): string
    {
        return "[{$this->code}] {$this->name}";
    }
}
