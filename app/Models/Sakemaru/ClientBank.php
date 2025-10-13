<?php

namespace App\Models\Sakemaru;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientBank extends CustomModel
{
    use HasFactory;

    protected $guarded = [];

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }
}
