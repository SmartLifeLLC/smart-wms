<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum EBankType: string
{
    use EnumExtensionTrait;

    case SAVING = 'SAVING';
    case CHECKING = 'CHECKING';

    public function name() : string
    {
        return match ($this) {
            self::SAVING => '普通',
            self::CHECKING => '当座',
        };
    }

    public function getID() : int
    {
        return match ($this) {
            self::SAVING => 0,
            self::CHECKING => 1,
        };
    }
}
