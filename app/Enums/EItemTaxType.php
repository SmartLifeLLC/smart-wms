<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum EItemTaxType: string
{
    use EnumExtensionTrait;

    case EXEMPT = 'EXEMPT';
    case PRE_TAX_PERCENT_10 = 'PRE_TAX_PERCENT_10';
    case POST_TAX_PERCENT_10 = 'POST_TAX_PERCENT_10';
    case PRE_TAX_PERCENT_8 = 'PRE_TAX_PERCENT_8';
    case POST_TAX_PERCENT_8 = 'POST_TAX_PERCENT_8';

    public function name() : string
    {
        return match ($this) {
            self::EXEMPT => '非課税',
            self::PRE_TAX_PERCENT_10 => '内税',
            self::POST_TAX_PERCENT_10 => '外税',
            self::PRE_TAX_PERCENT_8 => '軽減内税',
            self::POST_TAX_PERCENT_8 => '軽減外税',
        };
    }

    public function percent() : int
    {
        return match ($this) {
            self::EXEMPT => 0,
            self::PRE_TAX_PERCENT_10,
            self::POST_TAX_PERCENT_10 => 10,
            self::PRE_TAX_PERCENT_8,
            self::POST_TAX_PERCENT_8 => 8,
        };
    }

    public function taxType(): ?TaxType
    {
        return match ($this) {
            self::EXEMPT => null,
            self::PRE_TAX_PERCENT_8,
            self::PRE_TAX_PERCENT_10 => TaxType::PRE_TAX,
            self::POST_TAX_PERCENT_8,
            self::POST_TAX_PERCENT_10 => TaxType::POST_TAX,
        };
    }

    public function asPercent() : string
    {
        return $this->percent() . '%';
    }

    public function getID() : int
    {
        return match ($this) {
            self::EXEMPT => 0,
            self::PRE_TAX_PERCENT_10 => 1,
            self::POST_TAX_PERCENT_10 => 2,
            self::PRE_TAX_PERCENT_8 => 3,
            self::POST_TAX_PERCENT_8 => 4,
        };
    }
    public function taxRate():TaxRate
    {
        return match ($this) {
            self::EXEMPT => TaxRate::EXEMPT,
            self::PRE_TAX_PERCENT_10,
            self::POST_TAX_PERCENT_10 => TaxRate::PERCENT_10,
            self::PRE_TAX_PERCENT_8,
            self::POST_TAX_PERCENT_8 => TaxRate::PERCENT_8,
        };
    }
}
