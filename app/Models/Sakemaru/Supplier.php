<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Supplier extends CustomModel
{
    use HasFactory;

    protected $guarded = [];
    protected $casts = [];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
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

    public function delivery_cooperator(): BelongsTo
    {
        return $this->belongsTo(DeliveryCooperator::class);
    }

    public function rebate_billing_group() : BelongsTo
    {
        return $this->belongsTo(Partner::class, 'rebate_billing_group_id', 'id');
    }

    public function current_detail() : HasOne
    {
        $system_date = ClientSetting::systemDate(true);
        return $this->hasOne(SupplierDetail::class)
            ->whereDate('start_date', '<=', $system_date)
            ->orderBy('start_date', 'desc');
    }

    public function currentDetailForStartDate(?string $start_date)
    {
        return $this->hasOne(SupplierDetail::class)
            ->whereDate('start_date', '<=', $start_date)
            ->orderBy('start_date', 'desc')
            ->first();
    }

}
