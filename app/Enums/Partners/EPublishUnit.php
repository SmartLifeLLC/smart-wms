<?php


namespace App\Enums\Partners;

use App\Traits\EnumExtensionTrait;

enum EPublishUnit: string
{
    use EnumExtensionTrait;

    case NONE = 'NONE';
    case PER_SLIP = 'PER_BILL';
    case PER_DAY =  'PER_DAY';
    case PER_SEASON = 'PER_SEASON';
    case PER_MONTH = 'PER_MONTH';
    public function name() : string
    {
        return match($this) {
            self::NONE => '未使用',
            self::PER_SLIP => '伝票単位',
            self::PER_DAY => '日単位',
            self::PER_SEASON => '旬単位',
            self::PER_MONTH => '月単位'
        };
    }
    public function getID() : int
    {
        return match($this) {
            self::NONE => 0,
            self::PER_SLIP => 1,
            self::PER_DAY => 2,
            self::PER_SEASON => 3,
            self::PER_MONTH => 4
        };
    }
}
