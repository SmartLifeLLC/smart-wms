<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum EAutofillPriceType: string     // 自動入力する単価の種類
{
    use EnumExtensionTrait;

    case PURCHASE = 'PURCHASE';
    case SALE = 'SALE';
    case COST = 'COST';

    public function name() : string
    {
        return match($this) {
            self::PURCHASE => '仕入',
            self::SALE => '売上',
            self::COST => '原価',
        };
    }

    static function defaultType(bool $is_supplier): self
    {
        return $is_supplier ? self::PURCHASE : self::SALE;
    }

    static function forTradeCategory(TradeCategory $trade_category): self
    {
        return $trade_category->isBasePurchasePrice() ? self::PURCHASE : self::SALE;
    }
}
