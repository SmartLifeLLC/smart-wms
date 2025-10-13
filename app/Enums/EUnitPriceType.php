<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum EUnitPriceType: string
{
    use EnumExtensionTrait;

    case PURCHASE_UNIT_SALE_UNIT = 'PURCHASE_UNIT_SALE_UNIT';
    case PURCHASE_CASE_SALE_UNIT = 'PURCHASE_CASE_SALE_UNIT';
    case PURCHASE_CASE_SALE_CASE = 'PURCHASE_CASE_SALE_CASE';
    case PURCHASE_UNIT_SALE_CASE = 'PURCHASE_UNIT_SALE_CASE';

    public function name() : string
    {
        return match ($this) {
            self::PURCHASE_UNIT_SALE_UNIT => '仕バラ_売バラ',
            self::PURCHASE_CASE_SALE_UNIT => '仕ケース_売バラ',
            self::PURCHASE_CASE_SALE_CASE => '仕ケース_売ケース',
            self::PURCHASE_UNIT_SALE_CASE => '仕バラ_売ケース',
        };
    }

    public function getID() : int
    {
        return match ($this) {
            self::PURCHASE_UNIT_SALE_UNIT => 0,
            self::PURCHASE_CASE_SALE_UNIT => 1,
            self::PURCHASE_CASE_SALE_CASE => 2,
            self::PURCHASE_UNIT_SALE_CASE => 3,
        };
    }
}
