<?php

namespace App\Models\Sakemaru;

use App\Models\Sakemaru\Bill;
use App\Models\Sakemaru\ClosingBalanceOverview;
use App\Models\Sakemaru\CustomModel;
use App\Models\Sakemaru\Trade;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class ClosingBill extends CustomModel
{
    protected $guarded = [];
    protected $casts = [];

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    public function balance_overviews(): MorphMany
    {
        return $this->morphMany(ClosingBalanceOverview::class, 'closing');
    }

    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class)->orderBy('id');
    }
    protected static function booted()
    {
        // レコードが保存された後（作成・更新時）に実行
        static::saved(function (ClosingBill $closingBill) {
            // この締め請求書に関連する全てのBillのソート用カラムを更新する
            // 関連レコードがない場合も考慮して optional() と each() を使うと安全
            optional($closingBill->bills)->each(function ($bill) {
                $bill->updateEffectiveClosingDate();
            });
        });

        // レコードが削除された後に実行
        static::deleted(function (ClosingBill $closingBill) {
            // 削除された場合も、関連していたBillの再計算が必要
            optional($closingBill->bills)->each(function ($bill) {
                $bill->updateEffectiveClosingDate();
            });
        });
    }
}
