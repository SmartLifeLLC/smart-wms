<?php

namespace App\Models\Sakemaru;

use App\Enums\PrintType;
use App\Traits\LogPdfTrait;
use App\Traits\SyncTradeTrait;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends CustomModel
{
    use HasFactory;
    use SyncTradeTrait;
    use LogPdfTrait;

    protected $guarded = [];
    protected $casts = [];

    protected PrintType $checklist_print_type = PrintType::ORDER_CHECK;

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

    public function contractor():BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    public function purchaseOrderStatus(): Attribute
    {
        return new Attribute(function () {
            return $this->getExportType(PrintType::ORDER)->value;
        });
    }

    public function arrivalPlanStatus(): Attribute
    {
        return new Attribute(function () {
            return $this->getExportType(PrintType::ARRIVAL_PLAN)->value;
        });
    }
}
