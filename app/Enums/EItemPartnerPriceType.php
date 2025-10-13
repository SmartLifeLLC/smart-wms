<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum EItemPartnerPriceType: string
{
    use EnumExtensionTrait;

    case PARTNER = 'PARTNER';
    case PARTNER_PRICE_GROUP = 'PARTNER_PRICE_GROUP';
    case PARTNER_PRICE_GROUP2 = 'PARTNER_PRICE_GROUP2';
    case UNKNOWN = 'UNKNOWN';

    public function category(): PriceCategory
    {
        return match ($this) {
            self::PARTNER,
            self::PARTNER_PRICE_GROUP,
            self::PARTNER_PRICE_GROUP2 => PriceCategory::PARTNER,
            self::UNKNOWN => PriceCategory::UNKNOWN,
        };
    }
}
