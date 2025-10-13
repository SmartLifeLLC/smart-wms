<?php

namespace App\Models\Sakemaru;
use App\Casts\NullSetter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Estimate extends CustomModel
{
    use HasFactory;
    protected $guarded = [];
    protected $casts = [
        'expired_date' => NullSetter::class,
    ];

    public function buyer() : BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function estimate_items() : HasMany
    {
        return $this->hasMany(EstimateItem::class);
    }

    public function manager() : BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id', 'id');
    }

    public function warehouse() : BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
