<?php


namespace App\Enums\Partners;

use App\Traits\EnumExtensionTrait;

enum ECashCollectionMethod: string
{
    use EnumExtensionTrait;

    case SALESMAN = 'SALESMAN';
    case DELIVERY = 'DELIVERY';
    case PROMISSORY_NOTE = 'PROMISSORY_NOTE';
    case DIRECT_DEBIT = 'DIRECT_DEBIT';
    case INDIVIDUAL = 'INDIVIDUAL';
    case CVS = 'CVS';
    case DEPOSIT = 'DEPOSIT';
    case NP_LOAN = 'NP_LOAN';
    case CARD_PAYMENT = 'CARD_PAYMENT';

    public function name() : string
    {
        return match($this) {
            self::SALESMAN => '営業集金',
            self::DELIVERY => '配達集金',
            self::PROMISSORY_NOTE => '手形',
            self::DIRECT_DEBIT => '引落',
            self::INDIVIDUAL => '個別入金消込',
            self::CVS => 'CVS',
            self::DEPOSIT => '振込',
            self::NP_LOAN => 'NP掛払',
            self::CARD_PAYMENT => 'カード払い'
        };
    }
    public function getID() : int
    {
        return match($this) {
            self::SALESMAN => 0,
            self::DELIVERY => 1,
            self::PROMISSORY_NOTE => 2,
            self::DIRECT_DEBIT => 3,
            self::INDIVIDUAL => 4,
            self::CVS => 5,
            self::DEPOSIT => 6,
            self::NP_LOAN => 7,
            self::CARD_PAYMENT => 8
        };
    }
}
