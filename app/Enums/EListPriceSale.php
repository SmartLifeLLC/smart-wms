<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum EListPriceSale: string
{
    use EnumExtensionTrait;

    case TRUE = 'TRUE';
    case FALSE = 'FALSE';

    public function name() : string
    {
        return match ($this) {
            self::TRUE => 'する',
            self::FALSE => 'しない',
        };
    }

    public function getID() : int
    {
        return match ($this) {
            self::TRUE => 1,
            self::FALSE => 0,
        };
    }

}
