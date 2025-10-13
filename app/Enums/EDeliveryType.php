<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum EDeliveryType: string
{
    use EnumExtensionTrait;

    case DIRECT = 'DIRECT';
    case ARRIVAL = 'ARRIVAL'; //todo 命名変更

    public function name() : string
    {
        return match ($this) {
            self::DIRECT => '直送',
            self::ARRIVAL => '倉出',
        };
    }

    public function getID() : int
    {
        return match ($this) {
            self::DIRECT => 0,
            self::ARRIVAL => 1,
        };
    }
}
