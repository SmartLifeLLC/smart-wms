<?php


namespace App\Enums\Partners;

use App\Traits\EnumExtensionTrait;

enum EFractionDetail: string
{
    use EnumExtensionTrait;

    case ROUND_DOWN_YEN = 'ROUND_DOWN_YEN';
    case ROUND_YEN = 'ROUND_YEN';
    case ROUND_UP_YEN = 'ROUND_UP_YEN';
    case ROUND_DOWN_SEN = 'ROUND_DOWN_SEN';
    case ROUND_SEN = 'ROUND_SEN';
    case ROUND_UP_SEN = 'ROUND_UP_SEN';


    public function name() : string
    {
        return match($this) {
            self::ROUND_DOWN_YEN => '切捨(円未満)',
            self::ROUND_YEN => '四捨五入(円未満)',
            self::ROUND_UP_YEN => '切上(円未満)',
            self::ROUND_DOWN_SEN => '切捨(銭未満)',
            self::ROUND_SEN => '四捨五入(銭未満)',
            self::ROUND_UP_SEN => '切上(銭未満)',
        };
    }
    public function getID() : int
    {
        return match($this) {
            self::ROUND_DOWN_YEN => 1,
            self::ROUND_YEN => 2,
            self::ROUND_UP_YEN => 3,
            self::ROUND_DOWN_SEN => 4,
            self::ROUND_SEN => 5,
            self::ROUND_UP_SEN => 6,
        };
    }
}
