<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum EPurchaseCondition: string
{
    use EnumExtensionTrait;

    case ALL_PURCHASES = 'ALL_PURCHASES';
    case ARRIVAL_ONLY = 'ARRIVAL_ONLY';
    case DIRECT_DELIVERY_ONLY = 'DIRECT_DELIVERY_ONLY';

    public function name() : string
    {
        return match ($this) {
            self::ALL_PURCHASES => '全仕入',
            self::ARRIVAL_ONLY => '倉入のみ',
            self::DIRECT_DELIVERY_ONLY => '直送のみ',
        };
    }

    public function getID() : int
    {
        return match ($this) {
            self::ALL_PURCHASES => 0,
            self::ARRIVAL_ONLY => 1,
            self::DIRECT_DELIVERY_ONLY => 2,
        };
    }
}
