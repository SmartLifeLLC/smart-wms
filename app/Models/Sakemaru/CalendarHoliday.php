<?php

namespace App\Models\Sakemaru;

use App\Models\Sakemaru\ClientCalendar;
use App\Models\Sakemaru\CustomModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarHoliday extends CustomModel
{
    protected $guarded = [];
    protected $casts = [];

    public function client_calendar(): belongsTo
    {
        return $this->belongsTo(ClientCalendar::class);
    }

}
