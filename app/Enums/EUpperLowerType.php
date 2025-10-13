<?php

namespace App\Enums;


use App\Traits\EnumExtensionTrait;

enum EUpperLowerType: string
{
    use EnumExtensionTrait;

    case DIFFERENCE = 'DIFFERENCE';
    case RATE = 'RATE';
    case FIXED_UNIT_PRICE = 'FIXED_UNIT_PRICE';

    public function name() : string
    {
        return match ($this) {
            self::DIFFERENCE => '差額',
            self::RATE => '率',
            self::FIXED_UNIT_PRICE => '単価',
        };
    }

    public function getID() : int
    {
        return match ($this) {
            self::DIFFERENCE => 1,
            self::RATE => 2,
            self::FIXED_UNIT_PRICE => 3,
        };
    }


}
