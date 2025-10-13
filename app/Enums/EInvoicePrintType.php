<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum EInvoicePrintType: string
{
    use EnumExtensionTrait;

    case PRINT_ALL = 'PRINT_ALL';
    case NOT_PRINT = 'NOT_PRINT';
    case ONLY_HEADER = 'ONLY_HEADER';

    public function name() : string
    {
        return match ($this) {
            self::PRINT_ALL => '印刷する',
            self::NOT_PRINT => '印刷しない',
            self::ONLY_HEADER => '鑑のみ',
        };
    }

    public function getID() : int
    {
        return match ($this) {
            self::PRINT_ALL => 0,
            self::NOT_PRINT => 1,
            self::ONLY_HEADER => 2,
        };
    }
}
