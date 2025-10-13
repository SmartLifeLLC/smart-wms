<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum EPartnerHolidayType: string

{
    use EnumExtensionTrait;
    case SELF = 'SELF';
    case HOLIDAY = 'HOLIDAY';

    public function name() : string
    {
        return match($this) {
            self::SELF => '営業日',
            self::HOLIDAY => '定休日',
        };
    }

    public function getID(): int
    {
        return match($this) {
            self::SELF => 1,
            self::HOLIDAY => 0,
        };
    }

    public function displayString() : string
    {
        return match($this) {
            self::SELF => '',
            self::HOLIDAY => '休日',
        };
    }
}
