<?php


namespace App\Enums\Partners;

use App\Traits\EnumExtensionTrait;

enum ECashPaymentMethod: string
{
    use EnumExtensionTrait;

    case COLLECTION = 'COLLECTION';
    case DEPOSIT = 'DEPOSIT';
    case PROMISSORY_NOTE = 'PROMISSORY_NOTE';
    case CHECK = 'CHECK';
    case DIRECT_DEBIT = 'DIRECT_DEBIT';

    public function name() : string
    {
        return match($this) {
            self::COLLECTION => '集金',
            self::DEPOSIT => '振込',
            self::PROMISSORY_NOTE => '手形',
            self::CHECK => '小切手',
            self::DIRECT_DEBIT => '引落',
        };
    }
    public function getID() : int
    {
        return match($this) {
            self::COLLECTION => 0,
            self::DEPOSIT => 1,
            self::PROMISSORY_NOTE => 2,
            self::CHECK => 3,
            self::DIRECT_DEBIT => 4,
        };
    }
}
