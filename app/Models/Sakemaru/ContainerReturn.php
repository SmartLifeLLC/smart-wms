<?php

namespace App\Models\Sakemaru;
use App\Enums\PrintType;
use App\Traits\LogPdfTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ContainerReturn extends CustomModel
{
    use HasFactory;
    use LogPdfTrait;

    protected $guarded = [];
    protected $casts = [];

    protected PrintType $checklist_print_type = PrintType::CONTAINER_RETURN_CHECK;

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function delivered_type(): BelongsTo
    {
        return $this->belongsTo(DeliveredType::class);
    }

    public function billing_type(): BelongsTo
    {
        return $this->belongsTo(BillingType::class);
    }

    public function direct_earning() : HasOne
    {
        return $this->hasOne(ContainerPickup::class, 'direct_container_return_id', 'id');
    }
}
