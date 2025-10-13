<?php

namespace App\Models\Sakemaru;


use App\Models\BZCore\CalendarHoliday;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientCalendar extends CustomModel
{
    protected $guarded = [];
    protected $casts = [];

    public function calendar_holidays(): HasMany
    {
        return $this->hasMany(CalendarHoliday::class);
    }

    public function isHoliday($date)
    {
        $holiday = $this->calendar_holidays()->firstWhere('date', $date);
        if (!is_null($holiday)) {
            return $holiday->is_holiday;
        }

        $day_of_week = toCarbon($date)->dayOfWeek;

        return match ($day_of_week) {
            0 => $this->is_holiday_every_sunday,
            1 => $this->is_holiday_every_monday,
            2 => $this->is_holiday_every_tuesday,
            3 => $this->is_holiday_every_wednesday,
            4 => $this->is_holiday_every_thursday,
            5 => $this->is_holiday_every_friday,
            6 => $this->is_holiday_every_saturday,
        };
    }
}
