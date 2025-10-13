<?php

namespace App\Models\Sakemaru;
use App\Enums\PrintType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContainerPickup extends CustomModel
{
    use HasFactory;

    protected $guarded = [];
    protected $casts = [];

    protected PrintType $checklist_print_type = PrintType::CONTAINER_PICKUP_CHECK;
    protected PrintType $direct_checklist_print_type = PrintType::CONTAINER_DIRECT_CHECK;


    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function delivered_type(): BelongsTo
    {
        return $this->belongsTo(DeliveredType::class);
    }

    public function delivery_course(): BelongsTo
    {
        return $this->belongsTo(DeliveryCourse::class);
    }

    public function direct_purchase() : BelongsTo
    {
        return $this->belongsTo(ContainerReturn::class, 'direct_container_return_id', 'id');
    }
}
