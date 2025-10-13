<?php


namespace App\Enums\Partners;

use App\Enums\TaxRate;
use App\Enums\TaxType;
use App\Traits\EnumExtensionTrait;

enum EFraction: string
{
    use EnumExtensionTrait;

    case ROUND_DOWN = 'ROUND_DOWN';
    case ROUND = 'ROUND';
    case ROUND_UP = 'ROUND_UP';


    public static function fromPrevID(int $id): self
    {
        return match ($id) {
            0 => EFraction::ROUND_DOWN,
            1 => EFraction::ROUND,
            default => EFraction::ROUND_UP,
        };
    }

    public function name(): string
    {
        return match ($this) {
            self::ROUND_DOWN => '切捨',
            self::ROUND => '四捨五入',
            self::ROUND_UP => '切上',
        };
    }

    public function getID(): int
    {
        return match ($this) {
            self::ROUND_DOWN => 1,
            self::ROUND => 2,
            self::ROUND_UP => 3,
        };
    }

    public function hubID(): int
    {
        return match ($this) {
            self::ROUND => 0,
            self::ROUND_DOWN => 1,
            self::ROUND_UP => 2,
        };
    }

    public function calculate(float $value, ?TaxRate $tax_rate = null, string|TaxType $tax_type = TaxType::POST_TAX): int
    {
        if ($tax_rate) {
            $value = $tax_rate->calculate($value, $tax_type);
        }

        $is_minus = $value < 0;
        $value_abs = abs($value);

        $rounded = match ($this) {
            self::ROUND_DOWN => floor($value_abs),
            self::ROUND => round($value_abs),
            self::ROUND_UP => ceil($value_abs),
        };

        return $rounded * ($is_minus ? -1 : 1);
    }
}
