<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum EDifferentTreatment: string
{
    use EnumExtensionTrait;

    case DISABLE = 'DISABLE';
    case ENABLE = 'ENABLE';

    public function name() : string
    {
        return match ($this) {
            self::DISABLE => '-',
            self::ENABLE => '別扱い',
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
