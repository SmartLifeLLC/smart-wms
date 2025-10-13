<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum EAccountClassification: int
{
    use EnumExtensionTrait;


    //現金
    //振込
    //小切手
    //ビール券
    //手形
    //手数料
    //相殺その他
    //引落
    //ｸﾚｼﾞｯﾄｶｰﾄﾞ
    //値引
    //振込手数料
    //今回現金
    //今回振込
    //今回小切手
    //今回ギフト券
    //今回手形
    //今回手数料
    //今回相殺その他
    //仕入値引
    //商品券
    //返戻先不明金
    //現金
    //振込
    //小切手
    //ビール券
    //手形
    //手数料
    //相殺その他
    //引落
    //先払い
    //値引き
    //郵便振替


    case BILL_OF_EXCHANGE = 71000; // 手形
    case POST_DATED_CHECK = 71010; //先付小切手
    case CASH = 72000; //現金
    case BANK_TRANSFER = 72010; // 銀行振込
    case BANK_DEBIT = 72020; // 銀行引落し
    case CREDIT_CARD = 72030; // クレジットカード
    case POSTAL_TRANSFER = 72040; //  郵便振替
    case PREPAYMENT = 72050; // 先払い
    case CHEQUE = 72060; // 小切手
    case BEER_COUPON = 72070; //ビール券
    case GIFT_CERTIFICATE = 72080; // 商品券
    case DISCOUNT = 74000; // 値引き
    case TRANSFER_FEE = 74010; // 振込手数料
    case PURCHASE_DISCOUNT = 74020; // 仕入値引き
    case UNIDENTIFIED_RETURNED_MONEY = 74030; // 返戻先不明金
    case SET_OFF_OTHER = 74040; // 相殺・その他

    function readableName(): string
    {
        return match ($this) {
            self::BILL_OF_EXCHANGE => '手形',
            self::POST_DATED_CHECK => '先付小切手',
            self::CASH => '現金',
            self::BANK_TRANSFER => '銀行振込',
            self::BANK_DEBIT => '銀行引落し',
            self::CREDIT_CARD => 'クレジットカード',
            self::POSTAL_TRANSFER => '郵便振替',
            self::PREPAYMENT => '先払い',
            self::CHEQUE => '小切手',
            self::BEER_COUPON => 'ビール券',
            self::GIFT_CERTIFICATE => '商品券',
            self::DISCOUNT => '値引き',
            self::TRANSFER_FEE => '振込手数料',
            self::PURCHASE_DISCOUNT => '仕入値引き',
            self::UNIDENTIFIED_RETURNED_MONEY => '返戻先不明金',
            self::SET_OFF_OTHER => '相殺・その他',
        };
    }

    function isAllocatable(): bool
    {
        return match ($this) {
            self::BILL_OF_EXCHANGE, self::POST_DATED_CHECK => false,
            default => true,
        };
    }

    function paymentGroup(): int
    {
        return match ($this) {
            self::BILL_OF_EXCHANGE, self::POST_DATED_CHECK => 1,

            self::CASH, self::GIFT_CERTIFICATE, self::BEER_COUPON, self::CHEQUE,
            self::PREPAYMENT, self::POSTAL_TRANSFER, self::CREDIT_CARD, self::BANK_TRANSFER,
            self::BANK_DEBIT => 2,

            self::DISCOUNT, self::SET_OFF_OTHER, self::TRANSFER_FEE, self::PURCHASE_DISCOUNT,
            self::UNIDENTIFIED_RETURNED_MONEY => 4,
        };
    }
}
