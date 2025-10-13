<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum ECalculationPriceStandard: string
{
    use EnumExtensionTrait;

    case CONTRACTOR = 'CONTRACTOR';
    case SALE = 'SALE';

    public function name() : string
    {
        return match ($this) {
            self::CONTRACTOR => '発注先単価',
            self::SALE => '販売単価',
        };
    }

    public function getID() : int
    {
        return match ($this) {
            self::CONTRACTOR => 0,
            self::SALE => 1,
        };
    }
}
