<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum EItemManagementType: string
{
    use EnumExtensionTrait;

    case STANDARD = 'STANDARD';
    case CUSTOM = 'CUSTOM';
    case RARE = 'RARE';
    case OUT_OF_STOCK = 'OUT_OF_STOCK';
    case DISCONTINUED = 'DISCONTINUED';

    public function name() : string
    {
        return match($this)
        {
            self::STANDARD => '定番品',
            self::CUSTOM => '受発注品',
            self::RARE => '希少品',
            self::OUT_OF_STOCK => 'メーカー欠品',
            self::DISCONTINUED => '終売',
        };
    }
    public function getID() : int
    {
        return match($this)
        {
            self::STANDARD => 1,
            self::CUSTOM => 2,
            self::RARE => 3,
            self::OUT_OF_STOCK => 4,
            self::DISCONTINUED => 5,
        };
    }
}
