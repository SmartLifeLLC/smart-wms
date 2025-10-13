<?php


namespace App\Enums;


namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum ERebateCalculationMethod: string
{
    use EnumExtensionTrait;

    case PER_UNIT = 'PER_UNIT';
    case PER_AMOUNT = 'PER_AMOUNT';
    case PER_VOLUME = 'PER_VOLUME';
    public function name() : string
    {
        return match ($this) {
            self::PER_UNIT => '数量単位',
            self::PER_AMOUNT => '金額歩引',
            self::PER_VOLUME => '容量換算',
        };
    }

    public function getID() : int
    {
        return match ($this) {
            self::PER_UNIT => 0,
            self::PER_AMOUNT => 1,
            self::PER_VOLUME => 2,
        };
    }

    public function rebateAmountName() : string
    {
        return match ($this) {
            self::PER_UNIT => '単価',
            self::PER_AMOUNT => '割戻率（%）',
            self::PER_VOLUME => '金額',
        };
    }

    public function calculationDescription(string $base, ?string $unit_amount = '') : string
    {
        $base = numberOrNull($base, 2);
        return match ($this) {
            self::PER_UNIT => $base. ' x 数量',
            self::PER_AMOUNT => '金額 x ' . $base . '%',
            self::PER_VOLUME => '（容量 / ' . $unit_amount . '） x ' . $base,
        };
    }
}
