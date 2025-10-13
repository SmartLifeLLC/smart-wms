<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum EManageContainerDeposit: string
{
    use EnumExtensionTrait;

    case DISABLE = 'DISABLE';
    case ENABLE = 'ENABLE';

    public function name() : string
    {
        return match ($this) {
            self::DISABLE => '-',
            self::ENABLE => '別管理',
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
