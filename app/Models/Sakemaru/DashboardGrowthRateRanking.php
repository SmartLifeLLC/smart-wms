<?php

namespace App\Models\Sakemaru;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DashboardGrowthRateRanking extends CustomModel {
    use HasFactory;

    public function item(): BelongsTo {
        return $this->belongsTo(Item::class);
    }
}
