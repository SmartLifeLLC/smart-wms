<?php

namespace App\Models\Sakemaru;

use App\Enums\DeliveryStatus;
use App\Enums\EExportType;
use App\Enums\Partners\EMiscellaneousCollectionType;
use App\Enums\PrintType;
use App\Traits\LogPdfTrait;
use App\Traits\SyncTradeTrait;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Earning extends CustomModel
{
    use HasFactory;
    use SyncTradeTrait;
    use LogPdfTrait;

    protected $guarded = [];
    protected $casts = [];

    protected PrintType $checklist_print_type = PrintType::EARNING_CHECK;
    protected PrintType $direct_checklist_print_type = PrintType::EARNING_DIRECT_CHECK;

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function delivery_course(): BelongsTo
    {
        return $this->belongsTo(DeliveryCourse::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function delivered_type(): BelongsTo
    {
        return $this->belongsTo(DeliveredType::class);
    }

    public function direct_purchase() : BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'direct_purchase_id', 'id');
    }

    public function deliveryStatus() : Attribute
    {
        return new Attribute(function () {
            return DeliveryStatus::getFromEarning($this)->name();
        });
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }
}
