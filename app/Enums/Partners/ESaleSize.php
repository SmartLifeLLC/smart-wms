<?php


namespace App\Enums\Partners;

use App\Traits\EnumExtensionTrait;

enum ESaleSize: string
{
    use EnumExtensionTrait;

    case NONE = 'NONE';
    case LESS_THAN_10M = 'LESS_THAN_10M';
    case LESS_THAN_20M = 'LESS_THAN_20M';
    case LESS_THAN_30M = 'LESS_THAN_30M';
    case LESS_THAN_50M = 'LESS_THAN_50M';
    case OTHER = 'OTHER';

    public function name(): string
    {
        return match ($this) {
            self::NONE => '未使用',
            self::LESS_THAN_10M => '1千万未満',
            self::LESS_THAN_20M => '2千万未満',
            self::LESS_THAN_30M => '3千万未満',
            self::LESS_THAN_50M => '5千万未満',
            self::OTHER => 'その他'
        };
    }

    public function getID(): int
    {
        return match ($this) {
            self::NONE => 0,
            self::LESS_THAN_10M => 1,
            self::LESS_THAN_20M => 2,
            self::LESS_THAN_30M => 3,
            self::LESS_THAN_50M => 4,
            self::OTHER => 5

        };
    }
}
