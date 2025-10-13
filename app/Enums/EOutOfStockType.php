<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum EOutOfStockType: string
{
    use EnumExtensionTrait;

    case AUTO_CALCULATION = 'AUTO_CALCULATION';
    case SMALL_STOCK = 'SMALL_STOCK';
    case ORDERED = 'ORDERED';
    case RARE = 'RARE';
    case DISCONTINUED = 'DISCONTINUED';
    case OUT_OF_STOCK = 'OUT_OF_STOCK';
    case NONE_MOVABLE = 'NONE_MOVABLE';
    case DIRECT = 'DIRECT';

    public function name() : string
    {
        return match($this)
        {
            self::AUTO_CALCULATION => '自動計算',
            self::SMALL_STOCK => '小口在庫品',
            self::ORDERED => '受注発注品',
            self::RARE => '希少品',
            self::DISCONTINUED => '終売品',
            self::OUT_OF_STOCK => 'メーカー欠品',
            self::NONE_MOVABLE => '不動品',
            self::DIRECT => '直送備品',

        };
    }

    public function getID() : int
    {
        return match($this)
        {
            self::AUTO_CALCULATION => 1,
            self::SMALL_STOCK => 2,
            self::ORDERED => 3,
            self::RARE => 4,
            self::DISCONTINUED => 5,
            self::OUT_OF_STOCK => 6,
            self::NONE_MOVABLE => 7,
            self::DIRECT => 8,
        };
    }
}
