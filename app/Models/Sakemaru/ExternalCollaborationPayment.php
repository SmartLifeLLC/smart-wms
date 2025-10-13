<?php

namespace App\Models\Sakemaru;


use App\Traits\QuantityTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;


class ExternalCollaborationPayment extends CustomModel
{
    use QuantityTrait;

    protected $guarded = [];
    protected $casts = [
        'log' => 'json',
    ];
    protected bool $is_active_activate = false;

    public function partner() : BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
    public function external_collaboration_data() : BelongsTo
    {
        return $this->belongsTo(ExternalCollaborationData::class);
    }
}
