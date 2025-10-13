<?php

namespace App\Models\Sakemaru;
use App\Enums\EPrintCreationType;
use App\Enums\Partners\EMiscellaneousCollectionType;
use App\Enums\Partners\EMiscellaneousCreationTiming;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Buyer extends CustomModel
{
    use HasFactory;

    protected $guarded = [];
    protected $casts = [];

//    public static function find($id) :static | null
//    {
//        return static::query()
//            ->select('buyers.*', 'partners.name as name', 'partners.code as code')
//            ->where('buyers.id', $id)
//            ->leftJoin('partners', 'buyers.partner_id', 'partners.id')
//            ->first();
//    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function bills(): BelongsTo
    {
        return $this->belongsTo(Bill::class, 'partner_id', 'partner_id');
    }

    public function delivery_ally(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'delivery_ally_id', 'id');
    }

    public function rebate_store_group(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'rebate_store_group_id', 'id');
    }

    public function business_type(): BelongsTo
    {
        return $this->belongsTo(BusinessType::class);
    }

    public function sale_size(): BelongsTo
    {
        return $this->belongsTo(SaleSize::class);
    }

    public function location_condition(): BelongsTo
    {
        return $this->belongsTo(LocationCondition::class);
    }


    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }
    public function bill_department(): BelongsTo
    {
        return $this->belongsTo(BillDepartment::class);
    }

    public function partner_delivery_type(): HasOne
    {
        return $this->hasOne(PartnerDeliveryType::class);
    }
    public function partnerName() : Attribute
    {
        return Attribute::make(
            fn() => $this->partner?->name,
        );
    }

    public function partnerCode() : Attribute
    {
        return Attribute::make(
            fn() => $this->partner?->code,
        );
    }

    public function current_detail() : HasOne
    {
        $system_date = ClientSetting::systemDate(true);
        return $this->hasOne(BuyerDetail::class)
            ->whereDate('start_date', '<=', $system_date)
            ->orderBy('start_date', 'desc');
    }

    public function currentDetailForStartDate(?string $start_date)
    {
        return $this->hasOne(BuyerDetail::class)
            ->whereDate('start_date', '<=', $start_date)
            ->orderBy('start_date', 'desc')
            ->first();
    }

    public function miscellaneousCreationTiming() : EMiscellaneousCreationTiming
    {
        return EMiscellaneousCreationTiming::from($this->miscellaneous_creation_timing);
    }

    public function miscellaneousSlipType() : EPrintCreationType
    {
        return EPrintCreationType::from($this->miscellaneous_slip_type);
    }

    public function miscellaneousCollectionType() : EMiscellaneousCollectionType
    {
        return EMiscellaneousCollectionType::from($this->miscellaneous_collection_type);
    }
}
