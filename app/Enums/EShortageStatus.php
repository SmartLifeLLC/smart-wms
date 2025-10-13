<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum EShortageStatus: string
{
    use EnumExtensionTrait;

    case NO_SHORTAGE = 'NO_SHORTAGE';
    case SHORTAGE = 'SHORTAGE';
    case REPLENISHED = 'REPLENISHED';
    case SKIPPED = 'SKIPPED';

    public function name() : string
    {
        return match ($this) {
            self::NO_SHORTAGE => "欠品無し",
            self::SHORTAGE => "欠品中",
            self::REPLENISHED => "引当済み",
            self::SKIPPED => "キャンセル",
        };
    }

    public function getID() : int
    {
        return match ($this) {
            self::NO_SHORTAGE => 0,
            self::SHORTAGE => 1,
            self::REPLENISHED => 2,
            self::SKIPPED => 3,
        };
    }

    public function color(): BadgeColor|null
    {
        return match ($this) {
            self::NO_SHORTAGE => BadgeColor::BLUE,
            self::SHORTAGE => BadgeColor::RED,
            self::REPLENISHED => BadgeColor::GREEN,
            self::SKIPPED => BadgeColor::YELLOW,
        };
    }

    public static function replenishStatuses() : array
    {
        return [
            self::SHORTAGE, self::REPLENISHED, self::SKIPPED
        ];
    }
}
