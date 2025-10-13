<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum EPrintCreationType: string
{
    use EnumExtensionTrait;

    case NONE = 'NONE';
    case LATER = 'LATER';

    public function name() : string
    {
        return match ($this) {
            self::NONE => 'しない',
            self::LATER => '一括',
        };
    }

    public function getID() : int
    {
        return match ($this) {
            self::NONE => 0,
            self::LATER => 1,
        };
    }
}
