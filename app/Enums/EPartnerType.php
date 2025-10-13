<?php

namespace App\Enums;

use App\Models\Sakemaru\Partner;
use App\Traits\EnumExtensionTrait;

enum EPartnerType: string
{
    use EnumExtensionTrait;

    case BUYER = 'BUYER';
    case SUPPLIER = 'SUPPLIER';
    case NONE = 'NONE';

    public function name() : string
    {
        return match ($this) {
            self::BUYER => '得意先',
            self::SUPPLIER => '仕入先',
            self::NONE => '指定なし',
        };
    }

    public function getID() : int
    {
        return match ($this) {
            self::BUYER => 0,
            self::SUPPLIER => 1,
            self::NONE => 2,
        };
    }

    public static function fromPartner(?Partner $partner) : self
    {
        if(is_null($partner)) {
            return self::NONE;
        } else if ($partner->is_supplier) {
            return self::SUPPLIER;
        } else {
            return self::BUYER;
        }
    }
}
