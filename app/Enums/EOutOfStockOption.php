<?php

namespace App\Enums;

use App\Models\Partner;
use App\Traits\EnumExtensionTrait;

enum EOutOfStockOption: string
{
    use EnumExtensionTrait;

    case IGNORE_STOCK = 'IGNORE_STOCK';
    case UP_TO_STOCK = 'UP_TO_STOCK';

    public function name() : string
    {
        return match ($this) {
            self::IGNORE_STOCK => 'マイナス在庫可',
            self::UP_TO_STOCK => '引当可能数まで',
        };
    }

    public function getID() : int
    {
        return match ($this) {
            self::IGNORE_STOCK => 0,
            self::UP_TO_STOCK => 1,
        };
    }
}
