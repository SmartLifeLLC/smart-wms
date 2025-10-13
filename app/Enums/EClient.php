<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum EClient: string
{
    use EnumExtensionTrait;

    case TANIGUCHI = 'TANIGUCHI';
    case MOTOHARA = 'MOTOHARA';

    public function code() : int
    {
        return match ($this) {
            self::TANIGUCHI => 240603000100,
            self::MOTOHARA => 241003000300,
        };
    }
}
