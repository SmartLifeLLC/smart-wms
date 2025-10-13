<?php

namespace App\Enums\Partners;

use App\Traits\EnumExtensionTrait;

enum ECandidateSort: string
{
    use EnumExtensionTrait;

    case TRADE_COUNT_DESC = 'TRADE_COUNT_DESC';
    case LAST_PROCESS_DATE_DESC = 'LAST_PROCESS_DATE_DESC';
    case ITEM_TYPE_ASC = 'ITEM_TYPE_ASC';
    case ITEM_KANA_ASC = 'ITEM_KANA_ASC';
    case ITEM_CODE_ASC = 'ITEM_CODE_ASC';
    public function name() : string
    {
        return match ($this) {
            self::TRADE_COUNT_DESC => '取引回数降順',
            self::LAST_PROCESS_DATE_DESC => '最終取引日降順',
            self::ITEM_TYPE_ASC => '商品分類',
            self::ITEM_KANA_ASC => '商品カナ',
            self::ITEM_CODE_ASC => '品番',
        };
    }

    public function getID() : int
    {
        return match ($this) {
            self::TRADE_COUNT_DESC => 1,
            self::LAST_PROCESS_DATE_DESC => 2,
            self::ITEM_TYPE_ASC => 3,
            self::ITEM_KANA_ASC => 4,
            self::ITEM_CODE_ASC => 5,
        };
    }
}
