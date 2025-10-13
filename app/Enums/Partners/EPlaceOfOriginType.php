<?php

namespace App\Enums\Partners;

use App\Traits\EnumExtensionTrait;
use Illuminate\Support\Arr;

enum EPlaceOfOriginType: string
{
    use EnumExtensionTrait;

    case COUNTRY = 'COUNTRY';
    case OTHER = 'OTHER';


    public function name() : string
    {
        return match($this) {
            self::COUNTRY => '国',
            self::OTHER => 'その他',
        };
    }
}
