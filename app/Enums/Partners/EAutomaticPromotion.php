<?php

namespace App\Enums\Partners;

use App\Models\Earning;
use App\Traits\EnumExtensionTrait;

enum EAutomaticPromotion: string
{
    use EnumExtensionTrait;

    case NONE = 'NONE';
    case APPLY = 'APPLY';
    case PRIZE_ONLY = 'PRIZE_ONLY';
    public function name() : string
    {
        return match($this) {
            self::NONE => 'なし',
            self::APPLY => '販促対象',
            self::PRIZE_ONLY => '景品のみ',
        };
    }
    public function getID() : int
    {
        return match($this) {
            self::NONE => 1,
            self::APPLY => 2,
            self::PRIZE_ONLY => 3,
        };
    }
}
