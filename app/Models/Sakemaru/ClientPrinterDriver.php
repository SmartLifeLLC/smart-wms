<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * クライアントプリンタードライバー
 *
 * 基幹システムから参照されるプリンター設定（読み取り専用）
 */
class ClientPrinterDriver extends Model
{
    protected $connection = 'sakemaru';

    protected $table = 'client_printer_drivers';

    protected $fillable = [
        'code',
        'client_id',
        'warehouse_id',
        'printer_client_uuid',
        'printer_index',
        'name',
        'user_name',
        'printer_key',
        'can_print_continuously',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'can_print_continuously' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    // Relationships
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function courseSettings(): HasMany
    {
        return $this->hasMany(ClientPrinterCourseSetting::class, 'printer_driver_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForWarehouse($query, int $warehouseId)
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    // Accessors
    public function getDisplayNameAttribute(): string
    {
        $name = $this->name ?? 'プリンター';
        $index = $this->printer_index ? "#{$this->printer_index}" : '';

        return "{$name}{$index}";
    }
}
