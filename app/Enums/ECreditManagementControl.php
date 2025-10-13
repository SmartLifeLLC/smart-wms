<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum ECreditManagementControl: string
{
    use EnumExtensionTrait;

    case WARNING = 'WARNING';
    case PROHIBITION = 'PROHIBITION';

    public function name() : string
    {
        return match ($this) {
            self::WARNING => '警告',
            self::PROHIBITION => '禁止',
        };
    }

    public function getID() : int
    {
        return match ($this) {
            self::WARNING => 1,
            self::PROHIBITION => 2,
        };
    }
}
