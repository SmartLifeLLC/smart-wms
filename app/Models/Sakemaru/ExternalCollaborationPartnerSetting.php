<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ExternalCollaborationPartnerSetting extends CustomModel
{
    protected $guarded = [];
    protected $casts = [];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function delivery_availability(): BelongsTo
    {
        return $this->belongsTo(DeliveryAvailability::class);
    }

    public function order_closing_time(): BelongsTo
    {
        return $this->belongsTo(OrderClosingTime::class);
    }

    public function emails(): BelongsToMany
    {
        return $this->belongsToMany(Email::class)
            ->orderBy('id');
    }
}
