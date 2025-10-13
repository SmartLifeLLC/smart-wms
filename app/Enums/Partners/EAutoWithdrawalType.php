<?php

namespace App\Enums\Partners;

use App\Traits\EnumExtensionTrait;

enum EAutoWithdrawalType: string
{
    use EnumExtensionTrait;

    case NOT_USED = 'NOT_USED';
    case START_TO_USE = 'START_TO_USE';
    case USING = 'USING';
    public function name() : string
    {
        return match ($this) {
            self::NOT_USED => '未使用',
            self::START_TO_USE => '連携開始',
            self::USING => '連携中',
        };
    }

    public function getID() : int
    {
        return match ($this) {
            self::NOT_USED => 0,
            self::START_TO_USE => 1,
            self::USING => 2,
        };
    }

    public function isConnected() : bool
    {
        return match ($this) {
            self::NOT_USED => false,
            self::START_TO_USE,
            self::USING => true,
        };
    }
}
