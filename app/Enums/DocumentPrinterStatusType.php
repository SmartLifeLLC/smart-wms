<?php

namespace App\Enums;


use App\Traits\EnumExtensionTrait;

enum DocumentPrinterStatusType : string
{
    use EnumExtensionTrait;

    case STANDBY = 'STANDBY';
    case START = 'START';
    case END = 'END';
    case FAILURE = 'FAILURE';

    public function name() : string
    {
        return match($this) {
            self::STANDBY => '待機',
            self::START => '開始',
            self::END => '終了',
            self::FAILURE => '失敗',
        };
    }

    public function color(): BadgeColor
    {
        return match($this) {
            self::STANDBY => BadgeColor::INDIGO,
            self::START => BadgeColor::PRIMARY,
            self::END => BadgeColor::GREEN,
            self::FAILURE => BadgeColor::RED,
        };
    }
}
