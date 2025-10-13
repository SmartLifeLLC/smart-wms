<?php

namespace App\Enums\Partners;

use App\Traits\EnumExtensionTrait;

enum EQuantityConversion: string
{
    use EnumExtensionTrait;

    case STANDARD = 'STANDARD';
    case SALE_PRICE = 'SALE_PRICE';
    case QUANTITY_CONVERSION = 'QUANTITY_CONVERSION';
    case BOX_CONVERSION = 'BOX_CONVERSION';


    public function name() : string
    {
        return match($this) {
            self::STANDARD => '標準',
            self::SALE_PRICE => '本単価使用',
            self::QUANTITY_CONVERSION => '本数変換',
            self::BOX_CONVERSION => '函単価使用',
        };
    }
    public function getID() : int
    {
        return match($this) {
            self::STANDARD => 1,
            self::SALE_PRICE => 2,
            self::QUANTITY_CONVERSION => 3,
            self::BOX_CONVERSION => 4,
        };
    }
}
