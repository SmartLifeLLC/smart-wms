<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerTimetable extends CustomModel
{
    use HasFactory;

    protected $guarded = [];
    protected $casts = [];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function partner_timetable_plan(): BelongsTo
    {
        return $this->belongsTo(PartnerTimetablePlan::class);
    }
}
