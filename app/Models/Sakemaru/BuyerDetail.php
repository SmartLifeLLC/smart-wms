<?php

namespace App\Models\Sakemaru;
use App\Enums\Partners\EContainerDepositTaxType;
use App\Enums\Partners\EContainerTradeType;
use App\Enums\Partners\EFraction;
use App\Enums\Partners\EPaymentMethod;
use App\Enums\TaxType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BuyerDetail extends CustomModel
{
    use HasFactory;

    protected $guarded = [];
    protected $casts = [];
    public static function getCurrent(?int $buyer_id, ?int $client_id = null)
    {
        $system_date = ClientSetting::systemDate(true, $client_id)->format('Y-m-d');
        return BuyerDetail::whereDate('start_date', '<=', $system_date)
            ->where('buyer_id', $buyer_id)->orderBy('start_date', 'desc')?->first();
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesman_id', 'id');
    }

    public function bill_collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bill_collector_id', 'id');
    }

    public function delivery_center(): BelongsTo
    {
        return $this->belongsTo(DeliveryCenter::class);
    }
    public function delivery_course(): BelongsTo
    {
        return $this->belongsTo(DeliveryCourse::class);
    }

    public function holiday_delivery_course(): BelongsTo
    {
        return $this->belongsTo(DeliveryCourse::class, 'holiday_delivery_course_id', 'id');
    }

    public function partner_delivery_course(): BelongsTo
    {
        return $this->belongsTo(DeliveryCourse::class, 'partner_delivery_course_id', 'id');
    }

    public function delivery_warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'delivery_warehouse_id', 'id');
    }

    public function holiday_delivery_warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'holiday_delivery_warehouse_id', 'id');
    }

    public function slip_type(): BelongsTo
    {
        return $this->belongsTo(SlipType::class);
    }

    public function paymentMethod() : EPaymentMethod
    {
        return EPaymentMethod::from($this->payment_method);
    }

    public function billingType() : \App\Enums\BillingType
    {
        return $this->paymentMethod()->billingType();
    }

    public function taxType() : TaxType
    {
        return TaxType::from($this->display_tax_type);
    }

    public function slipFraction() : EFraction
    {
        return EFraction::from($this->slip_fraction);
    }

    public function taxFraction() : EFraction
    {
        return EFraction::from($this->tax_fraction);
    }

    public function containerDepositTaxType() : EContainerDepositTaxType
    {
        return EContainerDepositTaxType::from($this->container_deposit_tax_type);
    }

    public function containerTradeType() : EContainerTradeType
    {
        return EContainerTradeType::from($this->container_trade_type);
    }

    public static function currentDetailQuery(?Carbon $date = null) : Builder
    {
        if(is_null($date)) {
            $date = ClientSetting::systemDate(true);
        }
        return BuyerDetail::query()
            ->select('buyer_details.*')
            ->selectRaw("ROW_NUMBER() OVER (PARTITION BY buyer_details.buyer_id ORDER BY buyer_details.start_date DESC) AS row_num")
            ->where('buyer_details.start_date', '<=', $date->toDateString());
    }
}
