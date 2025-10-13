<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseContractor extends CustomModel
{
    protected $guarded = [];
    protected $casts = [];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function contractor() : BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    public function ordering_manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ordering_manager_id');
    }
}
