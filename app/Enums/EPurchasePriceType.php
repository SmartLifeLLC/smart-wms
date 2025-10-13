<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum EPurchasePriceType: string
{
    use EnumExtensionTrait;

    case PRODUCER = 'PRODUCER';
    case COST = 'COST';
    case WHOLESALE = 'WHOLESALE';

    public function name() : string
    {
        return match ($this) {
            self::PRODUCER => '生販単価',
            self::COST => '仕入単価',
            self::WHOLESALE => '卸単価',
        };
    }

    public function getID() : int
    {
        return match ($this) {
            self::PRODUCER => 0,
            self::COST => 1,
            self::WHOLESALE => 2,
        };
    }
}
