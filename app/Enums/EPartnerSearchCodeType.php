<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum EPartnerSearchCodeType: int
{
    use EnumExtensionTrait;
//return match (intval($item['取引先コード区分'])) {
//0 => $item['企業コード'] . $item['店舗コード'],
//1 => $item['業界統一コード'],
//2 => $item['事業者登録番号'],
//9 => $item['得意先コード'],
//};

    case UNKNOWN = -1;
    case COMPANY_AND_STORE_CODE = 0;
    case INDUSTRY_UNIFORM_CODE = 1;

    case INVOICE_REGISTRATION_NUMBER = 2;

    case BUYER_CODE = 9;


    public function getID() : int
    {
        return match ($this) {
            self::UNKNOWN => -1,
            self::COMPANY_AND_STORE_CODE => 0,
            self::INDUSTRY_UNIFORM_CODE => 1,
            self::INVOICE_REGISTRATION_NUMBER => 2,
            self::BUYER_CODE => 9,
        };
    }
}
