<?php


namespace App\Enums\Partners;

use App\Traits\EnumExtensionTrait;

enum ECalculationUnit: string
{
    use EnumExtensionTrait;

//    case PER_DETAIL = 'PER_DETAIL';
    case PER_SLIP = 'PER_SLIP';
    case PER_BILL = 'PER_BILL';


    public function name() : string
    {
        return match($this) {
//            self::PER_DETAIL => '明細単位',
            self::PER_SLIP => '伝票単位',
            self::PER_BILL => '請求書単位',
        };
    }
    public function getID() : int
    {
        return match($this) {
//            self::PER_DETAIL => 1,
            self::PER_SLIP => 1,
            self::PER_BILL => 2,
        };
    }

    public function hubID() : int
    {
        return match($this) {
            self::PER_SLIP => 0,
//            self::PER_DETAIL => 1,
            self::PER_BILL => 2,
        };
    }
}
