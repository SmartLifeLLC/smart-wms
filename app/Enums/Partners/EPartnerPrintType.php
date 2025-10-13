<?php

namespace App\Enums\Partners;

use App\Enums\EItemSearchCodeType;
use App\Models\Earning;
use App\Traits\EnumExtensionTrait;

enum EPartnerPrintType: string
{
    use EnumExtensionTrait;

    case NONE = 'NONE';
    case JAN = 'JAN';
    case PARTNER_CODE = 'PARTNER_CODE';
    case SDP = 'SDP';

    public function name() : string
    {
        return match($this) {
            self::NONE => 'なし',
            self::JAN => 'JAN',
            self::PARTNER_CODE => '先方コード',
            self::SDP => 'SDP',
        };
    }
    public function getID() : int
    {
        return match($this) {
            self::NONE => 1,
            self::JAN => 2,
            self::PARTNER_CODE => 3,
            self::SDP => 4,
        };
    }

    public function itemCodeType() : ?EItemSearchCodeType
    {
        return match($this) {
            self::JAN => EItemSearchCodeType::JAN,
            self::SDP => EItemSearchCodeType::SDP,
            default => null,
        };
    }
}
