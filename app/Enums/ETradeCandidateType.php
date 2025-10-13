<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum ETradeCandidateType: string
{
    use EnumExtensionTrait;

    case HISTORY = 'HISTORY';
    case ESTIMATE = 'ESTIMATE';
    case MANUAL = 'MANUAL';
    case PLAN = 'PLAN';

    public function name(): string
    {
        return match ($this) {
            self::HISTORY => '履歴',
            self::ESTIMATE => '見積',
            self::MANUAL => '手動',
            self::PLAN => '予定',
        };
    }

    public function color(): BadgeColor|null
    {
        return match ($this) {
            self::HISTORY => BadgeColor::BLUE,
            self::ESTIMATE => BadgeColor::GREEN,
            self::MANUAL => BadgeColor::RED,
            self::PLAN => BadgeColor::YELLOW,
        };
    }
}
