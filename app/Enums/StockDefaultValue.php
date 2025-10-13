<?php


namespace App\Enums;

use App\Enums\TaxRate;
use App\Traits\EnumExtensionTrait;

enum StockDefaultValue: string
{
    use EnumExtensionTrait;

    case INVENTORY = 'INVENTORY';
    case ZERO = 'ZERO';
    case BLANK = 'BLANK';

    public function name(): string
    {
        return match ($this) {
            self::INVENTORY => '理論在庫数',
            self::ZERO => 'ゼロ',
            self::BLANK => '空白',
        };
    }

    public function getID(): int
    {
        return match ($this) {
            self::INVENTORY => 0,
            self::ZERO => 1,
            self::BLANK => 2,
        };
    }
}
