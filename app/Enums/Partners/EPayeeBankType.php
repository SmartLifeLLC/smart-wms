<?php

namespace App\Enums\Partners;

use App\Traits\EnumExtensionTrait;

enum EPayeeBankType: string
{
    use EnumExtensionTrait;

    case SAME_BANK_OTHER_BRANCH = 'SAME_BANK_OTHER_BRANCH';
    case SAME_BANK_SAME_BRANCH = 'SAME_BANK_SAME_BRANCH';
    case OTHER_BANK = 'OTHER_BANK';

    public function name() : string
    {
        return match ($this) {
            self::SAME_BANK_OTHER_BRANCH => '同行他店',
            self::SAME_BANK_SAME_BRANCH => '同行同店',
            self::OTHER_BANK => 'その他銀行',
        };
    }

    public function getID() : int
    {
        return match ($this) {
            self::SAME_BANK_OTHER_BRANCH => 0,
            self::SAME_BANK_SAME_BRANCH => 1,
            self::OTHER_BANK => 2,
        };
    }

    public static function fromMSDKubun(int $doukou_taten_kubun)
    {
        return match ($doukou_taten_kubun){
            1=>self::SAME_BANK_OTHER_BRANCH,
            2=>self::SAME_BANK_SAME_BRANCH,
            3=>self::OTHER_BANK,
            default=>self::OTHER_BANK,

        };
    }
}
