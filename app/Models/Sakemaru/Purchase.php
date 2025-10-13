<?php

namespace App\Models\Sakemaru;

use App\Enums\EExportType;
use App\Enums\PrintType;
use App\Traits\LogPdfTrait;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Purchase extends CustomModel
{
    use HasFactory;
    use LogPdfTrait;

    protected $guarded = [];
    protected $casts = [];

    protected PrintType $checklist_print_type = PrintType::PURCHASE_CHECK;

    protected static function booted()
    {
        $updateBill = function (Purchase $purchase) {
            // このpurchaseに関連するbillのソート用カラムを更新
            // bill_id が存在し、関連Billが存在する場合のみ実行
            if ($purchase->bill_id) {
                optional($purchase->bill)->updateEffectiveClosingDate();
            }
        };

        // レコードが保存された後（作成・更新時）に実行
        static::saved($updateBill);

        // レコードが削除された後に実行
        static::deleted($updateBill);
    }

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

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function direct_earning() : HasOne
    {
        return $this->hasOne(Earning::class, 'direct_purchase_id', 'id');
    }

}
