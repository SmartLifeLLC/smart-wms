<?php

namespace App\Models\Sakemaru;

use App\Enums\PrintType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends CustomModel
{
    use HasFactory;

    protected $guarded = [];
    protected $casts = [];

    protected PrintType $checklist_print_type = PrintType::PAYMENT_CHECK;

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }
}
