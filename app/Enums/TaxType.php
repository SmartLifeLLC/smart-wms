<?php


namespace App\Enums;


namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum TaxType: string
{
    use EnumExtensionTrait;

    // 変更時はhubも考慮
    case PRE_TAX = 'PRE_TAX';
    case POST_TAX = 'POST_TAX';

    public function name() : string
    {
        return match ($this) {
            self::PRE_TAX => '内税',
            self::POST_TAX => '外税',
        };
    }

    public function alias() : string
    {
        return match ($this) {
            self::PRE_TAX => '内消費税',
            self::POST_TAX => '外消費税',
        };
    }

    public function short() : string
    {
        return match ($this) {
            self::PRE_TAX => '内',
            self::POST_TAX => '外',
        };
    }

    public function getID() : int
    {
        return match ($this) {
            self::PRE_TAX => 0,
            self::POST_TAX => 1,
        };
    }

    public function hubID() : int
    {
        return match ($this) {
            self::PRE_TAX => 1,
            self::POST_TAX => 0,
        };
    }
}
