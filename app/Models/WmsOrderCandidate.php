<?php

namespace App\Models;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\LotStatus;
use App\Enums\AutoOrder\OrderChannel;
use App\Enums\AutoOrder\OrderEntrySource;
use App\Enums\AutoOrder\OriginType;
use App\Enums\QuantityType;
use App\Models\Concerns\HasOptimisticLock;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\DeliveryCourse;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\ItemContractor;
use App\Models\Sakemaru\Supplier;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 発注候補
 */
class WmsOrderCandidate extends WmsModel
{
    use HasOptimisticLock;

    protected $table = 'wms_order_candidates';

    /**
     * 手動でセットされた計算ログ（N+1対策）
     */
    protected ?WmsOrderCalculationLog $preloadedCalculationLog = null;

    protected bool $calculationLogPreloaded = false;

    /**
     * 手動でセットされたItemContractor（N+1対策）
     */
    protected ?ItemContractor $preloadedItemContractor = null;

    protected bool $itemContractorPreloaded = false;

    protected $fillable = [
        'batch_code',
        'warehouse_id',
        'item_id',
        'item_code',
        'search_code',
        'contractor_id',
        'supplier_id',
        'purchase_unit_price',
        'delivery_course_id',
        'ordering_code',
        'self_shortage_qty',
        'satellite_demand_qty',
        'incoming_quantity_override',
        'demand_breakdown',
        'origin_warehouse_ids',
        'suggested_quantity',
        'order_quantity',
        'current_effective_stock',
        'incoming_quantity',
        'safety_stock',
        'calculated_shortage_qty',
        'purchase_unit',
        'quantity_type',
        'expected_arrival_date',
        'original_arrival_date',
        'status',
        'lot_status',
        'lot_rule_id',
        'lot_exception_id',
        'lot_before_qty',
        'lot_after_qty',
        'lot_fee_type',
        'lot_fee_amount',
        'is_manually_modified',
        'modified_by',
        'modified_at',
        'origin_type',
        'order_channel',
        'entry_source',
        'exclusion_reason',
        'transmission_status',
        'transmitted_at',
        'wms_order_jx_document_id',
        'lock_version',
    ];

    protected $casts = [
        'expected_arrival_date' => 'date',
        'original_arrival_date' => 'date',
        'modified_at' => 'datetime',
        'transmitted_at' => 'datetime',
        'status' => CandidateStatus::class,
        'lot_status' => LotStatus::class,
        'quantity_type' => QuantityType::class,
        'is_manually_modified' => 'boolean',
        'origin_type' => OriginType::class,
        'order_channel' => OrderChannel::class,
        'entry_source' => OrderEntrySource::class,
        'lot_fee_amount' => 'decimal:2',
        'demand_breakdown' => 'array',
        'current_effective_stock' => 'integer',
        'incoming_quantity' => 'integer',
        'safety_stock' => 'integer',
        'calculated_shortage_qty' => 'integer',
        'purchase_unit' => 'integer',
        'purchase_unit_price' => 'decimal:2',
    ];

    /**
     * ケース数量アクセサ（テーブルのインライン編集用）
     */
    protected function caseQuantity(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->quantity_type === QuantityType::CASE ? $this->order_quantity : 0,
            set: fn ($value) => [
                'order_quantity' => (int) $value,
                'quantity_type' => QuantityType::CASE->value,
            ],
        );
    }

    /**
     * バラ数量アクセサ（テーブルのインライン編集用）
     */
    protected function pieceQuantity(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->quantity_type === QuantityType::PIECE ? $this->order_quantity : 0,
            set: fn ($value) => [
                'order_quantity' => (int) $value,
                'quantity_type' => QuantityType::PIECE->value,
            ],
        );
    }

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

    public function deliveryCourse(): BelongsTo
    {
        return $this->belongsTo(DeliveryCourse::class);
    }

    public function jxDocument(): BelongsTo
    {
        return $this->belongsTo(WmsOrderJxDocument::class, 'wms_order_jx_document_id');
    }

    /**
     * 計算ログを事前にセット（N+1対策）
     */
    public function setPreloadedCalculationLog(?WmsOrderCalculationLog $log): self
    {
        $this->preloadedCalculationLog = $log;
        $this->calculationLogPreloaded = true;

        return $this;
    }

    /**
     * ItemContractorを事前にセット（N+1対策）
     */
    public function setPreloadedItemContractor(?ItemContractor $itemContractor): self
    {
        $this->preloadedItemContractor = $itemContractor;
        $this->itemContractorPreloaded = true;

        return $this;
    }

    /**
     * ItemContractorを取得（プリロード済みの場合はキャッシュを使用）
     */
    public function getItemContractorCachedAttribute(): ?ItemContractor
    {
        // プリロード済みの場合はキャッシュを返す
        if ($this->itemContractorPreloaded) {
            return $this->preloadedItemContractor;
        }

        // プリロードされていない場合はクエリを実行
        return ItemContractor::with('supplier.partner')
            ->where('item_id', $this->item_id)
            ->where('warehouse_id', $this->warehouse_id)
            ->first();
    }

    /**
     * 計算ログを取得（プリロード済みの場合はキャッシュを使用）
     */
    public function getCalculationLogAttribute(): ?WmsOrderCalculationLog
    {
        // プリロード済みの場合はキャッシュを返す
        if ($this->calculationLogPreloaded) {
            return $this->preloadedCalculationLog;
        }

        // プリロードされていない場合はクエリを実行
        return WmsOrderCalculationLog::where('batch_code', $this->batch_code)
            ->where('warehouse_id', $this->warehouse_id)
            ->where('item_id', $this->item_id)
            ->first();
    }

    /**
     * コレクションに対して計算ログを一括プリロード
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, self>  $candidates
     */
    public static function preloadCalculationLogs($candidates): void
    {
        if ($candidates->isEmpty()) {
            return;
        }

        // 単一クエリで全ての計算ログを取得（複合インデックス idx_batch_warehouse_item を使用）
        // (batch_code, warehouse_id, item_id) のタプルでフィルタ
        $tuples = $candidates->map(fn ($c) => [
            $c->batch_code,
            $c->warehouse_id,
            $c->item_id,
        ])->unique()->values();

        // WHERE (batch_code, warehouse_id, item_id) IN ((...), (...), ...) 形式
        $logs = WmsOrderCalculationLog::whereRaw(
            '(batch_code, warehouse_id, item_id) IN ('.
            $tuples->map(fn () => '(?, ?, ?)')->implode(', ').')',
            $tuples->flatten()->toArray()
        )->get();

        // キーでインデックス化
        $logsIndexed = $logs->keyBy(fn ($log) => "{$log->batch_code}_{$log->warehouse_id}_{$log->item_id}");

        // 各候補にログをセット
        $candidates->each(function ($candidate) use ($logsIndexed) {
            $key = "{$candidate->batch_code}_{$candidate->warehouse_id}_{$candidate->item_id}";
            $candidate->setPreloadedCalculationLog($logsIndexed->get($key));
        });
    }

    /**
     * コレクションに対してItemContractorを一括プリロード
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, self>  $candidates
     */
    public static function preloadItemContractors($candidates): void
    {
        if ($candidates->isEmpty()) {
            return;
        }

        // (item_id, warehouse_id) のタプルでフィルタ
        $tuples = $candidates->map(fn ($c) => [
            $c->item_id,
            $c->warehouse_id,
        ])->unique()->values();

        // WHERE (item_id, warehouse_id) IN ((...), (...), ...) 形式
        $itemContractors = ItemContractor::with('supplier.partner')
            ->whereRaw(
                '(item_id, warehouse_id) IN ('.
                $tuples->map(fn () => '(?, ?)')->implode(', ').')',
                $tuples->flatten()->toArray()
            )->get();

        // キーでインデックス化
        $indexed = $itemContractors->keyBy(fn ($ic) => "{$ic->item_id}_{$ic->warehouse_id}");

        // 各候補にセット
        $candidates->each(function ($candidate) use ($indexed) {
            $key = "{$candidate->item_id}_{$candidate->warehouse_id}";
            $candidate->setPreloadedItemContractor($indexed->get($key));
        });
    }

    /**
     * 現在庫（有効在庫）を取得
     * 直接カラムから取得、なければcalculationLogにフォールバック
     */
    public function getCurrentStockAttribute(): ?int
    {
        // 直接カラムがあればそちらを使用
        if ($this->attributes['current_effective_stock'] ?? null !== null) {
            return $this->attributes['current_effective_stock'];
        }

        return $this->calculationLog?->current_effective_stock;
    }

    /**
     * 現時点の理論在庫（引当可能数）を基幹 real_stocks からライブ取得する
     *
     * 発注計算バッチ（OrderCandidateCalculationService）と同じく
     * wms_v_stock_available の available_for_wms（= real_stocks.available_quantity =
     * current_quantity - reserved_quantity）を real_stock_id で重複排除して集計する。
     * バッチ時点のスナップショット（current_effective_stock）と異なり、常に最新値を返す。
     */
    public function liveAvailableStock(): int
    {
        $rows = \Illuminate\Support\Facades\DB::connection('sakemaru')->select(
            'SELECT SUM(stock_qty) AS total
             FROM (
                 SELECT DISTINCT real_stock_id, available_for_wms AS stock_qty
                 FROM wms_v_stock_available
                 WHERE warehouse_id = ? AND item_id = ?
             ) dedup',
            [$this->warehouse_id, $this->item_id]
        );

        return (int) ($rows[0]->total ?? 0);
    }

    /**
     * 入庫予定数を取得（オーバーライドがあればそちらを使用）
     * 直接カラムから取得、なければcalculationLogにフォールバック
     */
    public function getEffectiveIncomingQuantityAttribute(): ?int
    {
        // オーバーライドが設定されていればそちらを使用
        if ($this->incoming_quantity_override !== null) {
            return $this->incoming_quantity_override;
        }

        // 直接カラムがあればそちらを使用
        if (($this->attributes['incoming_quantity'] ?? null) !== null) {
            return $this->attributes['incoming_quantity'];
        }

        return $this->calculationLog?->incoming_quantity;
    }

    /**
     * 元の入庫予定数を取得
     * 直接カラムから取得、なければcalculationLogにフォールバック
     */
    public function getOriginalIncomingQuantityAttribute(): ?int
    {
        // 直接カラムがあればそちらを使用
        if (($this->attributes['incoming_quantity'] ?? null) !== null) {
            return $this->attributes['incoming_quantity'];
        }

        return $this->calculationLog?->incoming_quantity;
    }

    /**
     * 計算後在庫（利用可能在庫）を取得
     * オーバーライドがある場合は再計算
     */
    public function getCalculatedAvailableAttribute(): ?int
    {
        // オーバーライドがある場合は再計算
        if ($this->incoming_quantity_override !== null) {
            $currentStock = $this->current_stock ?? 0;
            $incomingQty = $this->incoming_quantity_override;

            return $currentStock + $incomingQty;
        }

        // 直接カラムから計算
        $currentStock = $this->attributes['current_effective_stock'] ?? null;
        $incomingQty = $this->attributes['incoming_quantity'] ?? null;
        if ($currentStock !== null && $incomingQty !== null) {
            return $currentStock + $incomingQty;
        }

        return $this->calculationLog?->calculation_details['利用可能在庫'] ?? null;
    }

    /**
     * 発注点（安全在庫）を取得
     * 直接カラムから取得、なければcalculationLogにフォールバック
     */
    public function getEffectiveSafetyStockAttribute(): ?int
    {
        // 直接カラムがあればそちらを使用
        if (($this->attributes['safety_stock'] ?? null) !== null) {
            return $this->attributes['safety_stock'];
        }

        return $this->calculationLog?->calculation_details['安全在庫'] ?? null;
    }

    /**
     * 不足数を取得
     * 直接カラムから取得、なければcalculationLogにフォールバック
     */
    public function getShortageQtyAttribute(): ?int
    {
        // 直接カラムがあればそちらを使用
        if (($this->attributes['calculated_shortage_qty'] ?? null) !== null) {
            return $this->attributes['calculated_shortage_qty'];
        }

        return $this->calculationLog?->calculated_shortage_qty;
    }

    public function scopeForBatch(Builder $query, string $batchCode): Builder
    {
        return $query->where('batch_code', $batchCode);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', CandidateStatus::PENDING);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', CandidateStatus::APPROVED);
    }

    public function scopeWithWarnings(Builder $query): Builder
    {
        return $query->whereIn('lot_status', [LotStatus::BLOCKED, LotStatus::NEED_APPROVAL]);
    }

    public function scopeForCreatedBy(Builder $query, ?int $userId): Builder
    {
        if ($userId === null) {
            return $query;
        }

        return $query->whereIn('batch_code', WmsAutoOrderJobControl::query()
            ->where('created_by', $userId)
            ->select('batch_code'));
    }

    /**
     * 合計必要数を取得
     */
    public function getTotalRequiredAttribute(): int
    {
        return $this->self_shortage_qty + $this->satellite_demand_qty;
    }
}
