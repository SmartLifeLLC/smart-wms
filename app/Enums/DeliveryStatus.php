<?php


namespace App\Enums;


namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum DeliveryStatus: string
{
    use EnumExtensionTrait;

    case UNCONFIRMED = 'UNCONFIRMED';
    case CONFIRMED = 'CONFIRMED';
    case ARRANGED = 'ARRANGED';


    public function name() : string
    {
        return match($this) {
            self::UNCONFIRMED => '未確定',
            self::CONFIRMED => '確定済',
            self::ARRANGED => '手配済',
        };
    }

    public function getID() : int
    {
        return match ($this) {
            self::UNCONFIRMED => 0,
            self::CONFIRMED => 1,
            self::ARRANGED => 2,
        };
    }

    public function color(): BadgeColor
    {
        return match($this) {
            self::UNCONFIRMED => BadgeColor::YELLOW,
            self::CONFIRMED => BadgeColor::GREEN,
            self::ARRANGED => BadgeColor::PRIMARY,
        };
    }
}
