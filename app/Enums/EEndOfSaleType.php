<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum EEndOfSaleType: string
{
    use EnumExtensionTrait;

    case NORMAL = 'NORMAL';
    case MANUFACTURER = 'MANUFACTURER';
    case MANUFACTURER_PLAN = 'MANUFACTURER_PLAN';
    case SELF = 'SELF';
    case DIFFICULT = 'DIFFICULT';

    public function name() : string
    {
        return match ($this) {
            self::NORMAL => '通常',
            self::MANUFACTURER => 'メーカー終売',
            self::MANUFACTURER_PLAN => 'メーカー終売予定',
            self::SELF => '自社終売',
            self::DIFFICULT => '入手困難',
        };
    }

    public function getID() : int
    {
        return match ($this) {
            self::NORMAL => 0,
            self::MANUFACTURER => 1,
            self::MANUFACTURER_PLAN => 2,
            self::SELF => 3,
            self::DIFFICULT => 4,
        };
    }

    public function canOrder(): bool
    {
        return match ($this) {
            self::NORMAL => true,
            self::MANUFACTURER => false,
            self::MANUFACTURER_PLAN => false,
            self::SELF => false,
            self::DIFFICULT => false,
        };
    }

    public function candidateColor(): string
    {
        return match ($this) {
            self::NORMAL => BadgeColor::WHITE->bg(),
            self::MANUFACTURER => BadgeColor::INDIGO->bg(),
            self::MANUFACTURER_PLAN => BadgeColor::PURPLE->bg(),
            self::SELF => BadgeColor::PINK->bg(),
            self::DIFFICULT => BadgeColor::ORANGE->bg(),
        };
    }

    public static function createFromMSDCode($code): EEndOfSaleType
    {
        return match ($code) {
            0=>self::NORMAL,
            1=>self::MANUFACTURER,
            2=>self::MANUFACTURER_PLAN,
            3=>self::SELF,
            9=>self::DIFFICULT,
        };
    }
}
