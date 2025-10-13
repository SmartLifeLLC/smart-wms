<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum ERebateConditionType: string
{
    use EnumExtensionTrait;

    case PURCHASE = 'PURCHASE';
    case STORE = 'STORE';

    public function name() : string
    {
        return match ($this) {
            self::PURCHASE => '仕入条件',
            self::STORE => '個店条件',
        };
    }

    public function invoiceName() : string
    {
        return match ($this) {
            self::PURCHASE => '仕入リベート請求書',
            self::STORE => '価格保証条件請求書',
        };
    }

    public function detailPrintName() : string
    {
        return match ($this) {
            self::PURCHASE => '仕入リベート内訳書',
            self::STORE => '価格保証条件内訳書',
        };
    }

    public function getID() : int
    {
        return match ($this) {
            self::PURCHASE => 0,
            self::STORE => 1,
        };
    }

    public function tradeCategory() : TradeCategory
    {
        return match ($this) {
            self::PURCHASE => TradeCategory::PURCHASE,
            self::STORE => TradeCategory::EARNING,
        };
    }


    public function color(): BadgeColor|null
    {
        return match ($this) {
            self::PURCHASE => BadgeColor::PURPLE,
            self::STORE => BadgeColor::BLUE,
        };
    }


    public static function fromTradeCategory(TradeCategory|string $category) : self|null
    {
        if (TradeCategory::PURCHASE->isSameAs($category)) {
            return self::PURCHASE;
        } elseif (TradeCategory::EARNING->isSameAs($category)) {
            return self::STORE;
        }
        return null;
    }
}
