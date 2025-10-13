<?php


namespace App\Enums;


namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum PriceCategory: string
{
    use EnumExtensionTrait;

    case CLIENT = 'CLIENT';
    case PARTNER = 'PARTNER';
    case OTHER = 'OTHER';
    case UNKNOWN = 'UNKNOWN';

    public function name() {
        return match ($this) {
            self::CLIENT => '商品',
            self::PARTNER => '特単',
            self::OTHER => '手打',
            self::UNKNOWN => '単価不明',
        };
    }

    public function getID() : int
    {
        return match ($this) {
            self::CLIENT => 0,
            self::PARTNER => 1,
            self::OTHER => 2,
            self::UNKNOWN => 3,
        };
    }
}
