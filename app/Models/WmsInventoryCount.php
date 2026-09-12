<?php

namespace App\Models;

use App\Models\Sakemaru\User;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class WmsInventoryCount extends WmsModel
{
    const STATUS_DRAFT = 'draft';

    const STATUS_COUNTING = 'counting';

    const STATUS_CHECKED = 'checked';

    const STATUS_CURRENT_STOCK_SAVED = 'current_stock_saved';

    const STATUS_CONFIRMED = 'confirmed';

    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'count_no',
        'client_id',
        'warehouse_id',
        'warehouse_code',
        'warehouse_name',
        'count_date',
        'status',
        'current_count_round',
        'lock_mode',
        'handy_reception',
        'snapshot_taken_at',
        'started_at',
        'current_stock_saved_at',
        'ending_stock_taken_at',
        'stock_movement_from_at',
        'stock_movement_calculated_at',
        'confirmed_at',
        'confirmed_by',
        'stock_adjustment_request_id',
        'stock_adjustment_queue_id',
        'stock_adjustment_id',
        'stock_adjustment_created_at',
        'stock_adjustment_error_message',
        'inventory_adjustment_request_id',
        'inventory_adjustment_request_ids',
        'inventory_adjustment_queue_id',
        'inventory_adjustment_queue_ids',
        'inventory_adjustment_queue_count',
        'inventory_adjustment_id',
        'inventory_adjustment_created_at',
        'inventory_adjustment_error_message',
        'first_count_confirmed_at',
        'first_count_confirmed_by',
        'second_count_confirmed_at',
        'second_count_confirmed_by',
        'final_count_confirmed_at',
        'final_count_confirmed_by',
        'memo',
        'is_all_store_difference_target',
        'created_by',
    ];

    protected $casts = [
        'count_date' => 'date',
        'current_count_round' => 'integer',
        'lock_mode' => 'boolean',
        'handy_reception' => 'boolean',
        'snapshot_taken_at' => 'datetime',
        'started_at' => 'datetime',
        'current_stock_saved_at' => 'datetime',
        'ending_stock_taken_at' => 'datetime',
        'stock_movement_from_at' => 'datetime',
        'stock_movement_calculated_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'stock_adjustment_created_at' => 'datetime',
        'inventory_adjustment_request_ids' => 'array',
        'inventory_adjustment_queue_ids' => 'array',
        'inventory_adjustment_queue_count' => 'integer',
        'inventory_adjustment_created_at' => 'datetime',
        'first_count_confirmed_at' => 'datetime',
        'second_count_confirmed_at' => 'datetime',
        'final_count_confirmed_at' => 'datetime',
        'is_all_store_difference_target' => 'boolean',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(WmsInventoryCountItem::class, 'inventory_count_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function confirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function generateCountNo(string $countDate): string
    {
        return 'IC-'.date('Ymd', strtotime($countDate)).'-'.Str::upper(Str::random(8));
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public static function applyDisplayStatusFilter(Builder $query, array $statuses): Builder
    {
        $statuses = array_values(array_unique(array_filter($statuses, fn ($status) => filled($status))));

        if ($statuses === []) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($statuses) {
            foreach ($statuses as $status) {
                if ($status === self::STATUS_CURRENT_STOCK_SAVED) {
                    $query->orWhere(function (Builder $savedQuery) {
                        $savedQuery
                            ->where('status', self::STATUS_COUNTING)
                            ->whereNotNull('current_stock_saved_at');
                    });

                    continue;
                }

                if ($status === self::STATUS_COUNTING) {
                    $query->orWhere(function (Builder $countingQuery) {
                        $countingQuery
                            ->where('status', self::STATUS_COUNTING)
                            ->whereNull('current_stock_saved_at');
                    });

                    continue;
                }

                $query->orWhere('status', $status);
            }
        });
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [
            self::STATUS_DRAFT,
            self::STATUS_COUNTING,
            self::STATUS_CHECKED,
        ]);
    }

    public static function statusFilterOptions(): array
    {
        return [
            self::STATUS_DRAFT => '下書き',
            self::STATUS_COUNTING => 'カウント中',
            self::STATUS_CURRENT_STOCK_SAVED => '現状保存',
            self::STATUS_CHECKED => '差異確認済',
            self::STATUS_CONFIRMED => '確定済',
            self::STATUS_CANCELLED => '取消',
        ];
    }

    public static function defaultStatusFilterValues(): array
    {
        return [];
    }

    public function getDisplayStatusAttribute(): string
    {
        if ($this->isCurrentStockSaved()) {
            return self::STATUS_CURRENT_STOCK_SAVED;
        }

        return $this->status;
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->display_status) {
            self::STATUS_DRAFT => '下書き',
            self::STATUS_COUNTING => 'カウント中',
            self::STATUS_CURRENT_STOCK_SAVED => '現状保存',
            self::STATUS_CHECKED => '差異確認済',
            self::STATUS_CONFIRMED => '確定済',
            self::STATUS_CANCELLED => '取消',
            default => $this->display_status,
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->display_status) {
            self::STATUS_DRAFT => 'gray',
            self::STATUS_COUNTING => 'info',
            self::STATUS_CURRENT_STOCK_SAVED => 'success',
            self::STATUS_CHECKED => 'warning',
            self::STATUS_CONFIRMED => 'success',
            self::STATUS_CANCELLED => 'danger',
            default => 'gray',
        };
    }

    public function isCurrentStockSaved(): bool
    {
        return $this->status === self::STATUS_COUNTING
            && $this->current_stock_saved_at !== null;
    }

    public function canSaveCurrentStock(): bool
    {
        return in_array($this->status, [
            self::STATUS_DRAFT,
            self::STATUS_COUNTING,
        ], true) && ! $this->isCurrentStockSaved();
    }

    public function canResumeCurrentStockSaved(): bool
    {
        return $this->isCurrentStockSaved();
    }

    public function canRefreshSystemQuantities(): bool
    {
        return ! $this->isCurrentStockSaved() && ! in_array($this->status, [
            self::STATUS_CONFIRMED,
            self::STATUS_CANCELLED,
        ], true);
    }

    public function canCalculatePostCountMovements(): bool
    {
        return ! $this->isCurrentStockSaved() && ! in_array($this->status, [
            self::STATUS_CONFIRMED,
            self::STATUS_CANCELLED,
        ], true);
    }

    public function enableHandyReception(): void
    {
        static::where('warehouse_id', $this->warehouse_id)
            ->where('id', '!=', $this->id)
            ->where('handy_reception', true)
            ->update(['handy_reception' => false]);

        $this->update(['handy_reception' => true]);
    }

    public function disableHandyReception(): void
    {
        $this->update(['handy_reception' => false]);
    }

    public function canToggleHandyReception(): bool
    {
        return in_array($this->status, [
            self::STATUS_DRAFT,
            self::STATUS_COUNTING,
        ], true);
    }
}
