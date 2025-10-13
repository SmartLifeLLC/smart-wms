<?php

namespace App\Models\Sakemaru;


use App\Enums\Partners\EHandleCompany;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerDeliveryType extends CustomModel
{
    protected $guarded = [];

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function handleCompany($date): EHandleCompany
    {
        $day_of_week = toCarbon($date)->dayOfWeek;

        $value = match ($day_of_week) {
            0 => $this->sunday,
            1 => $this->monday,
            2 => $this->tuesday,
            3 => $this->wednesday,
            4 => $this->thursday,
            5 => $this->friday,
            6 => $this->saturday,
        };
        return EHandleCompany::fromValue($value);
    }
}
