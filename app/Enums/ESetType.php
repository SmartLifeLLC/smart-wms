<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum ESetType: string
{
    use EnumExtensionTrait;


    case NONE = 'NONE';
    case OWNED = 'OWNED';
    case MAKER = 'MAKER';

    public function name() : string
    {
        return match ($this) {
            self::NONE => '-',
            self::OWNED => '自社セット',
            self::MAKER => 'メーカセット',
        };
    }

    public function getID() : int
    {
        return match ($this) {
            self::NONE => 0,
            self::OWNED => 1,
            self::MAKER => 2,
        };
    }

    public static function valueNames(): array
    {
        $array = [];
        foreach (self::cases() as $case) {
            if($case->value == self::NONE->value) {
                continue; // Skip NONE case

            }
            $array[$case->value] = $case->name();
        }
        return $array;
    }
}
