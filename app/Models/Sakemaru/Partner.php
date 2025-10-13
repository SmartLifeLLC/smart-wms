<?php

namespace App\Models\Sakemaru;

use App\Casts\NullSetter;
use App\Enums\EExternalCollaborationPartner;
use App\Enums\EInvoicePrintType;
use App\Enums\PartnerCategory;
use App\Models\Sakemaru\Bill;
use App\Models\Sakemaru\Buyer;
use App\Models\Sakemaru\BuyerDetail;
use App\Models\Sakemaru\ClientBank;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\CustomModel;
use App\Models\Sakemaru\ExternalCollaborationPartnerSetting;
use App\Models\Sakemaru\ItemPartnerNote;
use App\Models\Sakemaru\ItemPartnerPrice;
use App\Models\Sakemaru\MiscellaneousItemPrice;
use App\Models\Sakemaru\PartnerBank;
use App\Models\Sakemaru\PartnerClosingDetail;
use App\Models\Sakemaru\PartnerTimetable;
use App\Models\Sakemaru\PartnerTimetablePlan;
use App\Models\Sakemaru\RebateBill;
use App\Models\Sakemaru\Supplier;
use App\Models\Sakemaru\SupplierDetail;
use App\Models\Sakemaru\Trade;
use Carbon\Carbon;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Partner extends CustomModel
{
    use HasFactory;

    protected $guarded = [];
    protected $casts = [
        'start_of_trade_date' => NullSetter::class,
        'end_of_trade_date' => NullSetter::class,
    ];

    public function supplier()
    {
        return $this->hasOne(Supplier::class, 'partner_id', 'id');
    }

    public function buyer()
    {
        return $this->hasOne(Buyer::class, 'partner_id', 'id');
    }

//    public function branch() {
//        return $this->belongsTo(Branch::class, 'branch_id', 'id');
//    }
//
//    public function department() {
//        return $this->belongsTo(Department::class, 'department_id', 'id');
//    }
//
//    public function salesman() {
//        return $this->belongsTo(User::class, 'salesman_id', 'id');
//    }

    public function partner_price_group(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_price_group_id', 'id');
    }

    public function partner_price_group2(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_price_group2_id', 'id');
    }

    public function item_conversion_group(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'item_conversion_group_id', 'id');
    }

    public function partner_achievement_group(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_achievement_group_id', 'id');
    }

    public function bill_group(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'bill_group_id', 'id');
    }

    public function bill_children(): HasMany
    {
        return $this->hasMany(Partner::class, 'bill_group_id', 'id');
    }

    public function ledger_group(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'ledger_group_id', 'id');
    }

    public function client_bank(): BelongsTo
    {
        return $this->belongsTo(ClientBank::class);
    }

    public function partner_bank(): HasOne
    {
        return $this->hasOne(PartnerBank::class);
    }

    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    public function timetable_plans(): HasMany
    {
        return $this->hasMany(PartnerTimetablePlan::class);
    }

    public function current_timetable_plan(): HasOne
    {
        $system_date = ClientSetting::systemDate(true);
        return $this->hasOne(PartnerTimetablePlan::class)
            ->whereDate('start_date', '<=', $system_date)
            ->orderBy('start_date', 'desc');
    }

    public function partner_closing_details(): HasMany
    {
        return $this->hasMany(PartnerClosingDetail::class);
    }

    public function miscellaneous_item_prices(): HasMany
    {
        return $this->hasMany(MiscellaneousItemPrice::class);
    }

    public function getDetail(): BuyerDetail|SupplierDetail|null
    {
        if ($this->is_supplier) return $this->supplier?->current_detail;
        else return $this->buyer?->current_detail;
    }

    public function getRebateBalance(bool $is_tentative): int
    {
        return RebateBill::where('is_allocated', false)
            ->where('partner_id', $this->id)
            ->where('is_tentative', $is_tentative)
            ->sum('remaining_amount');
    }

    public function getBalance(\App\Enums\BillingType $billing_type): int
    {
        return Bill::where('is_allocated', false)
            ->where('partner_id', $this->id)
            ->where('billing_type', $billing_type)
            ->sum('remaining_amount');
    }

    public function sumatyu_setting(): HasOne
    {
        return $this->hasOne(ExternalCollaborationPartnerSetting::class)
            ->where('collaborator', EExternalCollaborationPartner::SUMATYU);
    }

    public function infomart_setting(): HasOne
    {
        return $this->hasOne(ExternalCollaborationPartnerSetting::class)
            ->where('collaborator', EExternalCollaborationPartner::INFOMART);
    }

    public function partnerCategory(): Attribute
    {
        if ($this->is_supplier) $partner_category = $this->supplier?->partner_category;
        else $partner_category = $this->buyer?->partner_category;
        return Attribute::make(
            get: function () use ($partner_category) {
                return PartnerCategory::tryFrom($partner_category)?->name();
            },
        );
    }

    public function joinedAddress(): Attribute
    {
        return Attribute::make(
            get: function () {
                return $this->address1 . " " . $this->address2;
            }
        );
    }

    public function slipDestinationName(): Attribute
    {
        return Attribute::make(
            get: function () {
                return !empty($this->buyer->delivery_name) ? $this->buyer->delivery_name : $this->name_main;
            }
        );
    }

    public function paymentSlipName(): String
    {
        return $this->name_main . $this->name_store;
    }

    public function slipPostalCode(): Attribute
    {

        return Attribute::make(
            get: function () {
                return !empty($this->buyer->delivery_postal_code) ? $this->buyer->delivery_postal_code : $this->postal_code;
            }
        );
    }

    public function slipAddress1(): Attribute
    {
        return Attribute::make(
            get: function () {
                return !empty($this->buyer->delivery_address1) ? $this->buyer->delivery_address1 : $this->address1;
            }
        );
    }

    public function slipAddress2(): Attribute
    {
        return Attribute::make(
            get: function () {
                return !empty($this->buyer->delivery_address1) || !empty($this->buyer->delivery_address2) ? $this->buyer->delivery_address2 : $this->address2;
            }
        );
    }

    public function slipTel(): Attribute
    {
        return Attribute::make(
            get: function () {
                return !empty($this->buyer->delivery_tel) ? $this->buyer->delivery_tel : $this->tel;
            }
        );
    }

    public function invoicePrintType(): EInvoicePrintType
    {

        $buyer = $this->buyer;
        return $buyer
            ? EInvoicePrintType::tryfrom($buyer->invoice_print_type)
            : EInvoicePrintType::PRINT_ALL;
    }

    public function hasPartnerPriceParent(): bool
    {
        return !is_null($this->partner_price_group_id) && $this->partner_price_group_id != $this->id;
    }

    public static function deleteCreatedFromDataTransfer($client_id, $where_conditions = [], OutputStyle $output = null, $onlyCreatedFromDataTransfer = false)
    {

        // Get Target Partner chunk by 1000
        $query = (new Partner())->onOffIsActive(false)->where('client_id', $client_id)->where('is_created_from_data_transfer', 1);
        foreach ($where_conditions as $column => $condition) {
            $query = $query->where($column, $condition);
        }
        $output?->info("Deleting " . $query->count() . " partners");
        $output?->progressStart($query->count());
        $chunk_query = clone $query;

        $chunk_query->chunk(1000, function ($partners) use ($output) {
            $output?->progressAdvance($partners->count());
            $partner_ids = $partners->pluck('id')->toArray();
            //Delete Buyers

            $buyers = (new Buyer)->onOffIsActive(false)->whereIn('partner_id', $partner_ids)->pluck('id')->toArray();

            if (!empty($buyers)) {

                (new BuyerDetail)->onOffIsActive(false)->whereIn('buyer_id', $buyers)->delete();
                (new Buyer)->onOffIsActive(false)->whereIn('id', $buyers)->delete();
            }

            //Delete Suppliers
            $suppliers = (new Supplier)->onOffIsActive(false)->whereIn('partner_id', $partner_ids)->pluck('id')->toArray();
            if (!empty($suppliers)) {
                (new SupplierDetail)->onOffIsActive(false)->whereIn('supplier_id', $suppliers)->delete();
                (new Supplier)->onOffIsActive(false)->whereIn('id', $suppliers)->delete();
            }
            //Delete all related data
            (new ItemPartnerNote)->onOffIsActive(false)->whereIn('partner_id', $partner_ids)->delete();
            (new ItemPartnerPrice)->onOffIsActive(false)->whereIn('partner_id', $partner_ids)->delete();
            (new PartnerClosingDetail)->onOffIsActive(false)->whereIn('partner_id', $partner_ids)->delete();
            (new PartnerTimetablePlan)->onOffIsActive(false)->whereIn('partner_id', $partner_ids)->delete();
            (new PartnerTimetable)->onOffIsActive(false)->whereIn('partner_id', $partner_ids)->delete();
        });
        $query->delete();
        $output?->progressFinish();
    }

    public function getCreatedAtAttribute($value)
    {
        return Carbon::parse($value)->format('Y-m-d');
    }

    public function isWithinTradingPeriod($target_date): bool
    {
        $target_date = Carbon::parse($target_date);
        $start_of_trade_date = Carbon::parse($this->start_of_trade_date);

        if (!empty($this->start_of_trade_date) && $target_date->lessThan($start_of_trade_date)) {
            return false;
        }

        if (empty($this->end_of_trade_date)) {
            return true;
        }

        $end_of_trade_date = Carbon::parse($this->end_of_trade_date);
        if ($target_date->greaterThanOrEqualTo($end_of_trade_date)) {
            return false;
        }

        return true;
    }

    public function billParentPartner() : Partner
    {
        return $this->bill_group ?? $this;
    }

    public static function unknownPriceGroup(): ?Partner
    {
        return self::query()->where('code', '=', '999999999')->first();
    }
}
