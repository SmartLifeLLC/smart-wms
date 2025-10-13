<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerBank extends CustomModel
{
    protected $guarded = [];

    public function partner() : belongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function hasData(): bool
    {
        return !(is_null($this->bank_id) && is_null($this->account_number) && is_null($this->holder_name));
    }
}
