<?php

namespace App\Enums\Partners;

use App\Traits\EnumExtensionTrait;

enum EHandleCompany: string
{
    use EnumExtensionTrait;

    case CLIENT = 'CLIENT';
    case PARTNER = 'PARTNER';

    public function name() : string
    {
        return match ($this) {
            self::CLIENT => '自社負担',
            self::PARTNER => '他社負担',
        };
    }

    public function getID() : int
    {
        return match ($this) {
            self::CLIENT => 0,
            self::PARTNER => 1,
        };
    }
}
