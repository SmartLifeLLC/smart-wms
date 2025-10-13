<?php

namespace App\Enums\Partners;

use App\Traits\EnumExtensionTrait;

enum EPaymentDateType: string
{
    use EnumExtensionTrait;

    case NOT_SET = 'NOT_SET';
    case PREVIOUS_BUSINESS_DAY = 'PREVIOUS_BUSINESS_DAY';
    case NEXT_BUSINESS_DAY = 'NEXT_BUSINESS_DAY';

    public function name(): string
    {
        return match ($this) {
            self::NOT_SET => '未設定',
            self::PREVIOUS_BUSINESS_DAY => '前営業日',
            self::NEXT_BUSINESS_DAY => '翌営業日',
        };
    }

    public function getID(): int
    {
        return match ($this) {
            self::NOT_SET => 0,
            self::PREVIOUS_BUSINESS_DAY => 1,
            self::NEXT_BUSINESS_DAY => 9,
        };
    }
}
