<?php


namespace App\Enums;


namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum ESystemDateUpdateType: string
{
    use EnumExtensionTrait;

    case TODAY = 'TODAY';
    case TOMORROW = 'TOMORROW';
    case BOTH = 'BOTH';

    public function name() : string
    {
        return match($this) {
            self::TODAY => '当日',
            self::TOMORROW => '翌日',
            self::BOTH => ' どちらも可'
        };
    }
    public function getID() : int
    {
        return match ($this) {
            self::TODAY => 1,
            self::TOMORROW => 2,
            self::BOTH => 3,
        };
    }


}
