<?php

namespace App\Models\Sakemaru;

use App\Enums\EItemTaxType;
use App\Enums\Partners\EContainerDepositTaxType;
use App\Enums\Partners\EContainerTradeType;
use App\Enums\Partners\EFraction;
use App\Enums\Partners\EPaymentMethod;
use App\Enums\TaxType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierDetail extends CustomModel
{
    protected $guarded = [];
    protected $casts = [];

    public static function getCurrent(?int $supplier_id, ?int $client_id = null)
    {
        $system_date = ClientSetting::systemDate(true, $client_id)->format('Y-m-d');
        return SupplierDetail::whereDate('start_date', '<=', $system_date)
            ->where('supplier_id', $supplier_id)?->orderBy('start_date', 'desc')->first();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
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
        return $this->belongsTo(User::class);
    }

    public function billingType() : \App\Enums\BillingType
    {
        return EPaymentMethod::from($this->payment_method)->billingType();
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

    public function rebateTaxType() : TaxType
    {
        return TaxType::from($this->purchase_tax_rebate);
    }

    public function rebateTaxFraction() : EFraction
    {
        return EFraction::from($this->purchase_tax_rebate_fraction);
    }

    public function rebateFraction() : EFraction
    {
        return EFraction::from($this->purchase_rebate_fraction);
    }

    public static function currentDetailQuery(?Carbon $date = null) : Builder
    {
        if(is_null($date)) {
            $date = ClientSetting::systemDate(true);
        }
        return SupplierDetail::query()
            ->select('supplier_details.*')
            ->selectRaw("ROW_NUMBER() OVER (PARTITION BY supplier_details.supplier_id ORDER BY supplier_details.start_date DESC) AS row_num")
            ->where('supplier_details.start_date', '<=', $date->toDateString());
    }
}
