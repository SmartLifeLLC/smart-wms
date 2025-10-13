<?php


namespace App\Enums\Partners;

use App\Traits\EnumExtensionTrait;

enum EContainerDepositTaxType: string
{
    use EnumExtensionTrait;

    case EXEMPT = 'EXEMPT';
    case TAXATION = 'TAXATION';


    public function name() : string
    {
        return match($this) {
            self::EXEMPT => '不課税扱い',
            self::TAXATION => '課税扱い',
        };
    }
    public function getID() : int
    {
        return match($this) {
            self::EXEMPT => 1,
            self::TAXATION => 2,
        };
    }

    public function hubID() : int
    {
        return match($this) {
            self::TAXATION => 0,
            self::EXEMPT => 1,
        };
    }
}
