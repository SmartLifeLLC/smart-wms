<?php

namespace App\Models\Sakemaru;

use App\Enums\PrintType;
use App\Traits\LogPdfTrait;
use App\Traits\SyncTradeTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransfer extends CustomModel
{
    use HasFactory;
    use SyncTradeTrait;
    use LogPdfTrait;

    protected $guarded = [];
    protected $casts = [];

    protected PrintType $checklist_print_type = PrintType::STOCK_TRANSFER_CHECK;

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    public function from_warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function to_warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }
}
