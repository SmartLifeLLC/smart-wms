<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum EClientCalendarType: string
{
    use EnumExtensionTrait;

    case FOR_DELIVERY = 'DAILY';
    case FOR_PURCHASE= 'MONTHLY';

    public function name() : string
    {
        return match ($this) {
            self::FOR_DELIVERY => '配送用',
            self::FOR_PURCHASE => '発注用',
        };
    }
    public function getID() : int
    {
        return match ($this) {
            self::FOR_DELIVERY => 0,
            self::FOR_PURCHASE => 1,
        };
    }
}
