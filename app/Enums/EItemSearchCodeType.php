<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum EItemSearchCodeType: string
{
    use EnumExtensionTrait;

    case JAN = 'JAN';
    case ITF = 'ITF';
    case SDP = 'SDP';
    case OTHER = 'OTHER';

    public function name() : string
    {
        return match ($this) {
            self::JAN => 'JAN',
            self::ITF => 'ITF',
            self::SDP => 'SDP',
            self::OTHER => 'その他',
        };
    }

    public function getID() : int
    {
        return match ($this) {
            self::JAN => 0,
            self::ITF => 1,
            self::SDP => 2,
            self::OTHER => 3,
        };
    }
}
