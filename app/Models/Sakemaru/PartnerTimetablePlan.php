<?php

namespace App\Models\Sakemaru;

use App\Enums\EPartnerHolidayType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;

class PartnerTimetablePlan extends CustomModel
{
    protected $guarded = [];
    protected $casts = [];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function partner_timetables(): HasMany
    {
        return $this->hasMany(PartnerTimetable::class);
    }

    public function holidayTypeFor(Carbon|string $datetime): EPartnerHolidayType
    {
        $datetime = toCarbon($datetime);

        $partner_timetables = $this->partner_timetables ?? [];
        $partner_timetable = Arr::first($partner_timetables, function ($timetable) use ($datetime) {
            return $timetable->week == $datetime?->weekOfMonth;
        });
        $partner_holiday_string = match ($datetime?->dayOfWeek) {
            0 => Arr::get($partner_timetable, 'sunday'),
            1 => Arr::get($partner_timetable, 'monday'),
            2 => Arr::get($partner_timetable, 'tuesday'),
            3 => Arr::get($partner_timetable, 'wednesday'),
            4 => Arr::get($partner_timetable, 'thursday'),
            5 => Arr::get($partner_timetable, 'friday'),
            6 => Arr::get($partner_timetable, 'saturday'),
            null => null,
        };
        return EPartnerHolidayType::tryFrom($partner_holiday_string);
    }
}
