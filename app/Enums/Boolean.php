<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum Boolean: string
{
    use EnumExtensionTrait;

    // 変更時はhubも考慮
    case FALSE = '0';
    case TRUE = '1';

    public function name() : string
    {
        return match ($this) {
            self::FALSE => 'FALSE',
            self::TRUE => 'TRUE',
        };
    }
}
