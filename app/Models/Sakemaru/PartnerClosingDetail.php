<?php

namespace App\Models\Sakemaru;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerClosingDetail extends CustomModel
{

    protected $guarded = [];
    protected $casts = [];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
    public function ledgerClassification(): BelongsTo
    {
        return $this->belongsTo(LedgerClassification::class);
    }
}
