<?php


namespace App\Enums\Partners;

use App\Traits\EnumExtensionTrait;

enum EOrderType: string
{
    use EnumExtensionTrait;

    case ONLY_ORDER = 'ONLY_ORDER';
    case CONFIRMED = 'CONFIRMED';


    public function name(): string
    {
        return match ($this) {
            self::ONLY_ORDER => '発注のみ',
            self::CONFIRMED => '確定',
        };
    }

    public function getID(): int
    {
        return match ($this) {
            self::ONLY_ORDER => 0,
            self::CONFIRMED => 1,
        };
    }
}
