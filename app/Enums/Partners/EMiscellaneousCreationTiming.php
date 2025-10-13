<?php

namespace App\Enums\Partners;

use App\Traits\EnumExtensionTrait;

enum EMiscellaneousCreationTiming: string
{
    use EnumExtensionTrait;

    case NONE = 'NONE';
    case ON_EARNING = 'ON_EARNING';
    case ON_CLOSING_DAILY = 'ON_CLOSING_DAILY';

    public function name() : string
    {
        return match ($this) {
            self::NONE => '生成しない',
            self::ON_EARNING => '売上時',
            self::ON_CLOSING_DAILY => '日次締時',
        };
    }

    public function getID() : int
    {
        return match ($this) {
            self::NONE => 0,
            self::ON_EARNING => 1,
            self::ON_CLOSING_DAILY => 2,
        };
    }
}
