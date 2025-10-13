<?php

namespace App\Enums\Partners;

use App\Models\Earning;
use App\Traits\EnumExtensionTrait;

enum EEntryMethod: string
{
    use EnumExtensionTrait;

    case SELF = 'SELF';
    case JAN = 'JAN';
    case PARTNER_CODE = 'PARTNER_CODE';
    case SDP = 'SDP';

    public function name() : string
    {
        return match($this) {
            self::SELF => '自社',
            self::JAN => 'JAN',
            self::PARTNER_CODE => '先方コード',
            self::SDP => 'SDP',
        };
    }
    public function getID() : string
    {
        return match($this) {
            self::SELF => '1',
            self::JAN => '2',
            self::PARTNER_CODE => '3',
            self::SDP => '4',
        };
    }
}
