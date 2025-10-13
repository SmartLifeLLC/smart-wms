<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum EAcrossDateType: string
{
    use EnumExtensionTrait;

    case SAME_DAY = 'SAME_DAY';
    case NEXT_DAY = 'NEXT_DAY';

    public function name() : string
    {
        return match ($this) {
            self::SAME_DAY => '当日',
            self::NEXT_DAY => '翌日',
        };
    }

    public function getID() : int
    {
        return match ($this) {
            self::SAME_DAY => 0,
            self::NEXT_DAY => 1,
        };
    }
}
