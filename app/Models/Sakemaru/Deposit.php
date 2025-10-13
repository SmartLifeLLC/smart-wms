<?php

namespace App\Models\Sakemaru;
use App\Enums\PrintType;
use App\Traits\LogPdfTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deposit extends CustomModel
{
    use HasFactory;
    use LogPdfTrait;

    protected $guarded = [];
    protected $casts = [];

    protected PrintType $checklist_print_type = PrintType::DEPOSIT_CHECK;

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }
}
