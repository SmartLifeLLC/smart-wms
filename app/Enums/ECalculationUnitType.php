<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum ECalculationUnitType: string
{
    use EnumExtensionTrait;

    case QUANTITY = 'QUANTITY';
    case COUNT = 'COUNT';

    public function name(): string {
        return match ($this) {
            self::COUNT => '出荷回数',
            default => '出荷数量',
        };
    }
    public function getID(): int {
        return match ($this) {
            self::COUNT => 1,
            default => 0,
        };
    }
}
