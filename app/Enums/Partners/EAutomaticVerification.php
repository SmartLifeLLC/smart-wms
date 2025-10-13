<?php

namespace App\Enums\Partners;

use App\Models\Earning;
use App\Traits\EnumExtensionTrait;

enum EAutomaticVerification: string
{
    use EnumExtensionTrait;

    case ORDER_ID = 'ORDER_ID';
    case OTHER = 'OTHER';

    public function name() : string
    {
        return match($this) {
            self::ORDER_ID => '発注番号',
            self::OTHER => 'その他',
        };
    }
    public function getID() : int
    {
        return match($this) {
            self::ORDER_ID => 1,
            self::OTHER => 2,
        };
    }
}
