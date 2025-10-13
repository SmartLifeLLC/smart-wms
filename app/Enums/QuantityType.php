<?php


namespace App\Enums;


namespace App\Enums;

use App\Traits\EnumExtensionTrait;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

enum QuantityType: string
{
    use EnumExtensionTrait;

    case CASE = 'CASE';
    case PIECE = 'PIECE';
    case CARTON = 'CARTON';
    case UNKNOWN = 'UNKNOWN';

    public function name() : string
    {
        return match ($this) {
            self::CASE => 'ケース',
            self::PIECE => 'バラ',
            self::CARTON => 'ボール',
            self::UNKNOWN => '無し',
        };
    }

    public function getID() : int
    {
        return match ($this) {
            self::CASE => 0,
            self::PIECE => 1,
            self::CARTON => 2,
            self::UNKNOWN => 99,
        };
    }

    public function taxExemptPriceCol() : string
    {
        return match ($this) {
            self::CASE => 'tax_exempt_case_price',
            self::PIECE => 'tax_exempt_unit_price',
            self::CARTON => 'tax_exempt_crate_price',
            self::UNKNOWN => 'none',
        };
    }

    public function capacityCol() : ?string
    {
        return match ($this) {
            self::CASE => 'capacity_case',
            self::CARTON => 'capacity_carton',
            default => null,
        };
    }

    public static function generalCases(): array
    {
        return [
            self::CASE,
            self::PIECE,
        ];
    }

    public function aliasCol() : string
    {
        return match ($this) {
            self::PIECE => 'unit',
            default => Str::lower($this->value)
        };
    }

    public static function generalIdNames(): array
    {
        return Arr::mapWithKeys(self::generalCases(), fn($case) => [$case->getID() => $case->name()]);
    }

    public static function generalValueNames(): array
    {
        return Arr::mapWithKeys(self::generalCases(), fn($case) => [$case->value => $case->name()]);
    }
}
