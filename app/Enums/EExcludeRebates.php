<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum EExcludeRebates: string
{
    use EnumExtensionTrait;

    case DISABLE = 'DISABLE';
    case ENABLE = 'ENABLE';

    public function name() : string
    {
        return match ($this) {
            self::DISABLE => '-',
            self::ENABLE => '除外する',
        };
    }

    public function getID() : int
    {
        return match ($this) {
            self::DISABLE => 0,
            self::ENABLE => 1,
        };
    }
}
