<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;
use Illuminate\Support\Arr;

enum EDeliveryQuantityCompletion: string
{
    use EnumExtensionTrait;

    case NORMAL = 'NORMAL'; //品切区分に応じる
    case ALL = 'ALL'; //無条件で受注数
    case ZERO = 'ZERO'; //無条件で0

    public function name() : string
    {
        return match ($this) {
            self::NORMAL => '通常商品同様',
            self::ALL => '出荷可',
            self::ZERO => '出荷不可',
        };
    }

    public function getID() : int
    {
        return match ($this) {
            self::NORMAL => 0,
            self::ALL => 1,
            self::ZERO => 2,
        };
    }
}
