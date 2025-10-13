<?php

namespace App\Models\Sakemaru;


use App\Enums\DeliveryStatus;
use App\Enums\TradeCategory;
use App\Models\Sakemaru\ClosingBill;
use App\Models\Sakemaru\ClosingDaily;
use App\Models\Sakemaru\ClosingMonthly;
use App\Models\Sakemaru\ContainerPickup;
use App\Models\Sakemaru\ContainerReturn;
use App\Models\Sakemaru\CustomModel;
use App\Models\Sakemaru\Deposit;
use App\Models\Sakemaru\Earning;
use App\Models\Sakemaru\LedgerClassification;
use App\Models\Sakemaru\Order;
use App\Models\Sakemaru\Partner;
use App\Models\Sakemaru\Payment;
use App\Models\Sakemaru\Purchase;
use App\Models\Sakemaru\RebateDeposit;
use App\Models\Sakemaru\StockTransfer;
use App\Models\Sakemaru\TradeBalance;
use App\Models\Sakemaru\TradeItem;
use App\Models\Sakemaru\TradePrice;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

class Trade extends CustomModel
{
    use HasFactory;
    protected $guarded = [];
    protected $casts = [
        'subtotal' => 'int',
        'total' => 'int',
    ];

    public function partner() : BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function trade_items(): HasMany
    {
        return $this->hasMany(TradeItem::class);
    }

    public function trade_price(): HasOne
    {
        return $this->hasOne(TradePrice::class);
    }

    public function earning(): HasOne
    {
        return $this->hasOne(Earning::class);
    }

    public function purchase(): HasOne
    {
        return $this->hasOne(Purchase::class);
    }

    public function container_pickup(): HasOne
    {
        return $this->hasOne(ContainerPickup::class);
    }

    public function container_return(): HasOne
    {
        return $this->hasOne(ContainerReturn::class);
    }

    public function stock_transfer(): HasOne
    {
        return $this->hasOne(StockTransfer::class);
    }

    public function deposit(): HasOne
    {
        return $this->hasOne(Deposit::class);
    }

    public function rebate_deposit(): HasOne
    {
        return $this->hasOne(RebateDeposit::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    public function order(): HasOne
    {
        return $this->hasOne(Order::class);
    }

    public function trade_balances(): HasMany
    {
        return $this->hasMany(TradeBalance::class);
    }

    public function ledger_classification() : BelongsTo
    {
        return $this->belongsTo(LedgerClassification::class, 'ledger_classification_id', 'id');
    }

    public function slip_classification() : BelongsTo
    {
        return $this->belongsTo(LedgerClassification::class, 'slip_classification_id', 'id');
    }

    public function closing_daily() : BelongsTo
    {
        return $this->belongsTo(ClosingDaily::class);
    }

    public function closing_monthly() : BelongsTo
    {
        return $this->belongsTo(ClosingMonthly::class);
    }

    public function closing_bill() : BelongsTo
    {
        return $this->belongsTo(ClosingBill::class);
    }

    public function origin_trade() : BelongsTo
    {
        return $this->belongsTo(Trade::class, 'origin_trade_id');
    }


    public static function currentMonthlyTrades(bool $as_query = false) : Collection|Builder
    {
        $query = static::whereNull('closing_monthly_id');
        if ($as_query) {
            return $query;
        }
        return $query->get();
    }

    public function didPrintChecklist() : Attribute
    {
        return new Attribute(function () {
            return $this->checklist_print_count > 0 ? 1 : 0;
        });
    }

    public function totalTax() : Attribute
    {
        return new Attribute(function () {
            return $this->total - $this->subtotal;
        });
    }

    public function isDirect() : Attribute
    {
        $trade_category = TradeCategory::tryFrom($this->trade_category);
        return new Attribute(function () use($trade_category) {
            return $trade_category->detailModel($this)?->is_direct_delivery ?? false;
        });
    }

    public function tradeCategory() : TradeCategory
    {
        return TradeCategory::from($this->trade_category);
    }

    /**
     * earnings, purchases等を返す
     * @param bool $for_direct
     * @return CustomModel|null
     */
    public function detailModel(bool $for_direct = false) : ?CustomModel
    {
        return $this->tradeCategory()->detailModel($this, $for_direct);
    }

    public function isEditable(?CustomModel $base_model = null) : bool
    {
        // 日時処理後や、請求締め処理後は編集不可
        $is_editable = is_null($this->closing_daily_id) &&
            is_null($this->closing_monthly_id) &&
            is_null($this->closing_bill_id);

        // 充当済みの場合は編集不可
        $bill = $base_model?->bill;
        if ($is_editable && $bill && !$this->tradeCategory()->isForContainer()) {
            $is_editable = $bill->allocation_amount == 0;
        }

        // hub, FIT送信済みは編集不可
        if ($base_model instanceof Earning) {
            $is_editable = $is_editable && $base_model->hub_sent_count == 0 && $base_model->fit_sent_count == 0;

            // 直送の場合仕入もチェック
            if ($base_model->is_direct_delivery) {
                $purchase = $base_model->direct_purchase;
                $is_editable = $is_editable && $purchase->trade->isEditable($purchase);
            }
        }

        if ($base_model instanceof ContainerPickup) {
            // 直送返却の場合返却もチェック
            if($base_model->is_direct_delivery) {
                $container_return = $base_model->direct_purchase;
                $is_editable = $is_editable && $container_return->trade->isEditable($container_return);
            }
        }

        return $is_editable;
    }

    public function isRecreatable(?CustomModel $base_model = null) : bool
    {
        // 訂正済みのレコードは訂正できない（訂正後のレコードは可能）
        $is_recreatable = $this->is_latest;

        // hub送信後に配送完了となっていないレコードは訂正できない
        if ($base_model instanceof Earning) {
            $is_recreatable = $is_recreatable && ($base_model->hub_sent_count == 0 or $base_model->is_delivered);
        }

        return  $is_recreatable;
    }

    public static function warehouseIdQuery() : Builder
    {
        return Trade::select(['trades.id'])
            ->selectRaw('COALESCE(orders.warehouse_id, purchases.warehouse_id, earnings.warehouse_id, container_pickups.warehouse_id, container_returns.warehouse_id, stock_transfers.from_warehouse_id) as warehouse_id')
            ->leftJoin('orders', 'orders.trade_id', 'trades.id')
            ->leftJoin('purchases', 'purchases.trade_id', 'trades.id')
            ->leftJoin('earnings', 'earnings.trade_id', 'trades.id')
            ->leftJoin('container_returns', 'container_returns.trade_id', 'trades.id')
            ->leftJoin('container_pickups', 'container_pickups.trade_id', 'trades.id')
            ->leftJoin('stock_transfers', 'stock_transfers.trade_id', 'trades.id');
    }

    public static function deliveryCourseIdQuery() : Builder
    {
        return Trade::select(['trades.id'])
            ->selectRaw('COALESCE(earnings.delivery_course_id) as delivery_course_id')
            ->leftJoin('earnings', 'earnings.trade_id', 'trades.id');
    }
}
