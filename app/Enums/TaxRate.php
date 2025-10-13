<?php


namespace App\Enums;


namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum TaxRate: string
{
    use EnumExtensionTrait;

    case PERCENT_8 = 'PERCENT_8';
    case PERCENT_10 = 'PERCENT_10';
    case EXEMPT = 'EXEMPT';

    public function name() : string
    {
        return match ($this) {
            self::PERCENT_8 => '8 %',
            self::PERCENT_10 => '10 %',
            self::EXEMPT => '非課税',
        };
    }

    public function getID() : int
    {
        return match ($this) {
            self::PERCENT_8 => 0,
            self::PERCENT_10 => 1,
            self::EXEMPT => 2,
        };
    }

    public function rate(): float
    {
        return match ($this) {
            self::PERCENT_8 => 0.08,
            self::PERCENT_10 => 0.10,
            self::EXEMPT => 0.00,
        };
    }

    public function percent(): int
    {
        return (int)($this->rate() * 100);
    }

    public function calculate(float $value, string|TaxType $tax_type = TaxType::POST_TAX) : float
    {
        if(TaxType::PRE_TAX->isSameAs($tax_type)) {
            // 浮動小数点対応のために整数に直してから演算
            $value = $value * 100 / (100 + $this->percent());
        }
        return bcmul($value, $this->rate(), 2);
    }
}
