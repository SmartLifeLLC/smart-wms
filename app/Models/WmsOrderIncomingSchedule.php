<?php

namespace App\Models;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\AutoOrder\OrderChannel;
use App\Enums\AutoOrder\OrderSource;
use App\Enums\AutoOrder\TransmissionType;
use App\Enums\QuantityType;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Location;
use App\Models\Sakemaru\Supplier;
use App\Models\Sakemaru\Warehouse;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 発注入庫予定
 *
 * 発注確定後の入庫予定を管理
 * 自動発注・手動発注の両方に対応
 */
class WmsOrderIncomingSchedule extends WmsModel
{
    protected $table = 'wms_order_incoming_schedules';

    protected $fillable = [
        'warehouse_id',
        'item_id',
        'item_code',
        'search_code',
        'contractor_id',
        'supplier_id',
        'location_id',
        'order_candidate_id',
        'transfer_candidate_id',
        'source_warehouse_id',
        'stock_transfer_id',
        'manual_order_number',
        'order_source',
        'order_channel',
        'slip_number',
        'expected_quantity',
        'received_quantity',
        'quantity_type',
        'order_date',
        'expected_arrival_date',
        'actual_arrival_date',
        'expiration_date',
        'status',
        'confirmed_at',
        'confirmed_by',
        'confirmed_picker_id',
        'is_receive_matched',
        'shipped_quantity',
        'unit_price',
        'case_price',
        'partner_unit_price',
        'partner_case_price',
        'price_type',
        'shortage_quantity',
        'purchase_queue_id',
        'purchase_slip_number',
        'source_incoming_schedule_id',
        'source_received_detail_id',
        'purchase_split_key',
        'note',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected $casts = [
        'order_date' => 'date',
        'expected_arrival_date' => 'date',
        'actual_arrival_date' => 'date',
        'expiration_date' => 'date',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'status' => IncomingScheduleStatus::class,
        'order_source' => OrderSource::class,
        'order_channel' => OrderChannel::class,
        'quantity_type' => QuantityType::class,
        'is_receive_matched' => 'boolean',
        'shortage_quantity' => 'integer',
        'shipped_quantity' => 'integer',
        'source_incoming_schedule_id' => 'integer',
        'source_received_detail_id' => 'integer',
        'unit_price' => 'decimal:2',
        'case_price' => 'decimal:2',
        'partner_unit_price' => 'decimal:2',
        'partner_case_price' => 'decimal:2',
    ];

    // Relationships

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function orderCandidate(): BelongsTo
    {
        return $this->belongsTo(WmsOrderCandidate::class, 'order_candidate_id');
    }

    public function transferCandidate(): BelongsTo
    {
        return $this->belongsTo(WmsStockTransferCandidate::class, 'transfer_candidate_id');
    }

    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    public function confirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function confirmedByPicker(): BelongsTo
    {
        return $this->belongsTo(WmsPicker::class, 'confirmed_picker_id');
    }

    // Scopes

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', IncomingScheduleStatus::PENDING);
    }

    public function scopePartial(Builder $query): Builder
    {
        return $query->where('status', IncomingScheduleStatus::PARTIAL);
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', IncomingScheduleStatus::CONFIRMED);
    }

    public function scopeNotCompleted(Builder $query): Builder
    {
        return $query->whereIn('status', [
            IncomingScheduleStatus::PENDING,
            IncomingScheduleStatus::PARTIAL,
        ]);
    }

    public function scopeForWarehouse(Builder $query, int $warehouseId): Builder
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    public function scopeExpectedBefore(Builder $query, string $date): Builder
    {
        return $query->where('expected_arrival_date', '<=', $date);
    }

    public function scopeFromAutoOrder(Builder $query): Builder
    {
        return $query->where('order_source', OrderSource::AUTO);
    }

    public function scopeFromManualOrder(Builder $query): Builder
    {
        return $query->where('order_source', OrderSource::MANUAL);
    }

    public function scopeFromTransfer(Builder $query): Builder
    {
        return $query->where('order_source', OrderSource::TRANSFER);
    }

    public function scopeForPurchaseTransmission(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query
            ->withoutTransferSource()
            ->whereNotExists(function ($subQuery) use ($table) {
                $subQuery
                    ->selectRaw('1')
                    ->from('wms_contractor_settings as purchase_transmission_settings')
                    ->whereColumn('purchase_transmission_settings.contractor_id', "{$table}.contractor_id")
                    ->where('purchase_transmission_settings.transmission_type', TransmissionType::INTERNAL->value);
            });
    }

    public function scopeReadyForPurchaseTransmission(Builder $query, ?int $warehouseId = null): Builder
    {
        return $query
            ->confirmed()
            ->forPurchaseTransmission()
            ->whereNull('purchase_queue_id')
            ->when($warehouseId !== null, fn (Builder $query) => $query->forWarehouse($warehouseId));
    }

    public function scopeWithoutTransferSource(Builder $query): Builder
    {
        return $query
            ->whereIn('order_source', [
                OrderSource::AUTO->value,
                OrderSource::MANUAL->value,
                OrderSource::RECEIVED->value,
                OrderSource::APP_UNPLANNED->value,
            ])
            ->whereNull('transfer_candidate_id')
            ->whereNull('source_warehouse_id')
            ->whereNull('stock_transfer_id');
    }

    public function scopeWithTransferSource(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->where('order_source', OrderSource::TRANSFER->value)
                ->orWhereNotNull('transfer_candidate_id')
                ->orWhereNotNull('source_warehouse_id')
                ->orWhereNotNull('stock_transfer_id');
        });
    }

    public function scopeReadyForIncomingTransmission(Builder $query, ?int $warehouseId = null): Builder
    {
        return $query
            ->confirmed()
            ->withoutTransferSource()
            ->whereNull('purchase_queue_id')
            ->when($warehouseId !== null, fn (Builder $query) => $query->forWarehouse($warehouseId));
    }

    public function scopeEosSent(Builder $query): Builder
    {
        return $query->whereRaw(static::eosSentConditionSql($query->getModel()->getTable()));
    }

    public function scopeNotEosSent(Builder $query): Builder
    {
        return $query->whereRaw('NOT '.static::eosSentConditionSql($query->getModel()->getTable()));
    }

    public static function eosSentConditionSql(string $table = 'wms_order_incoming_schedules'): string
    {
        $scheduleTable = static::rawTableReference($table);
        $activeStatus = WmsOrderSlipNumberAssignment::STATUS_ACTIVE;
        $transmittedStatus = WmsOrderSlipNumberAssignment::STATUS_TRANSMITTED;

        return <<<SQL
            (
                {$scheduleTable}.`source_received_detail_id` IS NOT NULL
                OR {$scheduleTable}.`source_incoming_schedule_id` IS NOT NULL
                OR (
                    {$scheduleTable}.`order_candidate_id` IS NOT NULL
                    AND EXISTS (
                        SELECT 1
                        FROM `wms_order_candidates` AS eos_order_candidates
                        WHERE eos_order_candidates.`id` = {$scheduleTable}.`order_candidate_id`
                            AND eos_order_candidates.`wms_order_jx_document_id` IS NOT NULL
                    )
                )
                OR EXISTS (
                    SELECT 1
                    FROM `wms_order_slip_number_assignments` AS eos_slip_assignments
                    LEFT JOIN `warehouses` AS eos_warehouses
                        ON eos_warehouses.`id` = {$scheduleTable}.`warehouse_id`
                    WHERE eos_slip_assignments.`status` IN ('{$activeStatus}', '{$transmittedStatus}')
                        AND TRIM(COALESCE({$scheduleTable}.`slip_number`, '')) <> ''
                        AND eos_slip_assignments.`slip_number` = TRIM({$scheduleTable}.`slip_number`)
                        AND eos_warehouses.`id` IS NOT NULL
                        AND (
                            TRIM(COALESCE(eos_warehouses.`code`, '')) = ''
                            OR eos_slip_assignments.`store_code` = LPAD(
                                CASE
                                    WHEN TRIM(LEADING '0' FROM TRIM(CAST(eos_warehouses.`code` AS CHAR))) = '' THEN '0'
                                    ELSE TRIM(LEADING '0' FROM TRIM(CAST(eos_warehouses.`code` AS CHAR)))
                                END,
                                2,
                                '0'
                            )
                        )
                )
                OR (
                    {$scheduleTable}.`order_candidate_id` IS NOT NULL
                    AND {$scheduleTable}.`order_candidate_id` IN (
                        SELECT CAST(eos_candidate_ids.`candidate_id` AS UNSIGNED)
                        FROM `wms_order_slip_number_assignments` AS eos_candidate_assignments
                        JOIN JSON_TABLE(
                            eos_candidate_assignments.`order_candidate_ids`,
                            '$[*]' COLUMNS (`candidate_id` BIGINT PATH '$')
                        ) AS eos_candidate_ids
                        WHERE eos_candidate_assignments.`status` IN ('{$activeStatus}', '{$transmittedStatus}')
                            AND eos_candidate_assignments.`order_candidate_ids` IS NOT NULL
                    )
                )
            )
        SQL;
    }

    private static function rawTableReference(string $table): string
    {
        if (str_contains($table, '`')) {
            return $table;
        }

        return '`'.str_replace('`', '``', $table).'`';
    }

    // Accessors

    /**
     * 残り入庫数量
     */
    public function getRemainingQuantityAttribute(): int
    {
        return max(0, $this->expected_quantity - $this->received_quantity);
    }

    /**
     * 入庫完了かどうか
     */
    public function getIsFullyReceivedAttribute(): bool
    {
        return $this->received_quantity >= $this->expected_quantity;
    }

    public function getExpectedPieceQuantityAttribute(): int
    {
        return $this->quantityAsPieces($this->expected_quantity);
    }

    public function getReceivedPieceQuantityAttribute(): int
    {
        return $this->quantityAsPieces($this->received_quantity);
    }

    public function quantityAsPieces(?int $quantity): int
    {
        $quantity = (int) ($quantity ?? 0);
        $quantityType = $this->quantity_type instanceof QuantityType
            ? $this->quantity_type
            : QuantityType::tryFrom((string) $this->quantity_type);

        return match ($quantityType) {
            QuantityType::CASE => $quantity * max(1, (int) ($this->item?->capacity_case ?? 1)),
            QuantityType::CARTON => $quantity * max(1, (int) ($this->item?->capacity_carton ?? 1)),
            default => $quantity,
        };
    }

    public function isEosSent(): bool
    {
        if ($this->source_received_detail_id || $this->source_incoming_schedule_id) {
            return true;
        }

        if ($this->hasActiveSlipNumberAssignment()) {
            return true;
        }

        if (! $this->order_candidate_id) {
            return false;
        }

        if ($this->relationLoaded('orderCandidate') && $this->orderCandidate?->wms_order_jx_document_id) {
            return true;
        }

        if (! $this->relationLoaded('orderCandidate')
            && $this->orderCandidate()
                ->whereNotNull('wms_order_jx_document_id')
                ->exists()) {
            return true;
        }

        return WmsOrderSlipNumberAssignment::query()
            ->whereIn('status', [
                WmsOrderSlipNumberAssignment::STATUS_ACTIVE,
                WmsOrderSlipNumberAssignment::STATUS_TRANSMITTED,
            ])
            ->whereJsonContains('order_candidate_ids', (int) $this->order_candidate_id)
            ->exists();
    }

    public function isUnassignedJxReceived(): bool
    {
        $orderSource = $this->order_source instanceof OrderSource
            ? $this->order_source
            : OrderSource::tryFrom((string) $this->order_source);

        return $orderSource === OrderSource::RECEIVED
            && str_starts_with((string) $this->purchase_split_key, 'UNASSIGNED_JX_SLIP_');
    }

    private function hasActiveSlipNumberAssignment(): bool
    {
        $slipNumber = trim((string) $this->slip_number);

        if ($slipNumber === '') {
            return false;
        }

        $storeCode = $this->legacyStoreCode();

        return WmsOrderSlipNumberAssignment::query()
            ->whereIn('status', [
                WmsOrderSlipNumberAssignment::STATUS_ACTIVE,
                WmsOrderSlipNumberAssignment::STATUS_TRANSMITTED,
            ])
            ->where('slip_number', $slipNumber)
            ->when(
                $storeCode !== null,
                fn (Builder $query): Builder => $query->where('store_code', $storeCode)
            )
            ->exists();
    }

    private function legacyStoreCode(): ?string
    {
        if ($this->relationLoaded('warehouse')) {
            $code = $this->warehouse?->code;
        } elseif ($this->warehouse_id) {
            $code = $this->warehouse()->value('code');
        } else {
            return null;
        }

        $code = trim((string) $code);

        if ($code === '') {
            return null;
        }

        $code = ltrim($code, '0');

        return str_pad($code === '' ? '0' : $code, 2, '0', STR_PAD_LEFT);
    }

    // Methods

    /**
     * 伝票番号を採番
     *
     * フォーマット: {YYMMDD}{連番5桁} = 11桁数字のみ
     * 例: 26030500001
     * JX Bレコードの伝票番号フィールド（11バイト）にそのまま格納可能
     *
     * @param  string|null  $orderDate  発注日（Y-m-d形式）。nullの場合は今日
     */
    public static function generateSlipNumber(?string $orderDate = null): string
    {
        $date = $orderDate ?? now()->format('Y-m-d');
        $dateStr = Carbon::parse($date)->format('ymd');

        $maxSlip = self::where('slip_number', 'like', $dateStr.'%')
            ->where('slip_number', 'REGEXP', '^[0-9]{11}$')
            ->orderByRaw('CAST(SUBSTRING(slip_number, 7) AS UNSIGNED) DESC')
            ->value('slip_number');

        $nextSeq = $maxSlip ? (int) substr($maxSlip, 6) + 1 : 1;

        return self::formatSlipNumber($date, $nextSeq);
    }

    public static function formatSlipNumber(string $orderDate, int $sequence): string
    {
        $dateStr = Carbon::parse($orderDate)->format('ymd');

        if ($sequence < 1 || $sequence > 99999) {
            throw new \RuntimeException("伝票番号の当日採番上限を超えました: {$dateStr}");
        }

        return $dateStr.str_pad($sequence, 5, '0', STR_PAD_LEFT);
    }

    /**
     * 入庫数量を追加
     */
    public function addReceivedQuantity(int $quantity): void
    {
        $this->received_quantity += $quantity;

        if ($this->received_quantity >= $this->expected_quantity) {
            $this->status = IncomingScheduleStatus::CONFIRMED;
        } elseif ($this->received_quantity > 0) {
            $this->status = IncomingScheduleStatus::PARTIAL;
        }

        $this->save();
    }

    /**
     * 入庫確定
     */
    public function confirm(int $confirmedBy, ?string $actualDate = null, ?int $pickerId = null): void
    {
        $this->update([
            'status' => IncomingScheduleStatus::CONFIRMED,
            'confirmed_at' => now(),
            'confirmed_by' => $pickerId ? null : $confirmedBy,
            'confirmed_picker_id' => $pickerId,
            'actual_arrival_date' => $actualDate ?? now()->format('Y-m-d'),
            'received_quantity' => $this->expected_quantity,
        ]);
    }

    /**
     * キャンセル
     */
    public function cancel(): void
    {
        $this->update([
            'status' => IncomingScheduleStatus::CANCELLED,
        ]);
    }
}
