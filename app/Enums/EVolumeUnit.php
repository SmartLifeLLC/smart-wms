<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum EVolumeUnit: string
{
    use EnumExtensionTrait;

    case MILLILITER = 'MILLILITER';
    case GRAM = 'GRAM';
    case INCLUDED_QUANTITY = 'INCLUDED_QUANTITY';

    public function name() : string
    {
        return match ($this) {
            self::MILLILITER => 'ml',
            self::GRAM => 'g',
            self::INCLUDED_QUANTITY => '内数',
        };
    }

    public function getID() : int
    {
        return match ($this) {
            self::MILLILITER => 0,
            self::GRAM => 1,
            self::INCLUDED_QUANTITY => 2,
        };
    }

    public function packagingVolume(int $volume): string {
        $is_kilo_unit  = $volume >= 1000 && $volume % 100 == 0;
        $display_volume = $is_kilo_unit ? $volume * 0.001 : $volume;
        if(auth()->user()->client->setting->uses_custom_packaging_connection){
            return $volume . auth()->user()->client->setting->custom_packaging_connection;
        }

        switch ($this) {
            case self::MILLILITER:
                $display_volume_unit = $is_kilo_unit ? 'L' : 'ml';
                return $display_volume . $display_volume_unit;
            case self::GRAM:
                $display_volume_unit = $is_kilo_unit ? 'Kg' : 'g';
                return $display_volume . $display_volume_unit;
            case self::INCLUDED_QUANTITY:
                if($volume > 0) {
                    return $volume . '×';
                } else {
                    return '×';
                }
        }
    }

    public static function fromPrevID(int $id) : self
    {
        return match($id) {
            0 => self::MILLILITER,
            1 => self::GRAM,
            default => self::INCLUDED_QUANTITY,
        };
    }


    public function calculateLiter(string $value) : string
    {
        return match ($this) {
            self::MILLILITER,
            self::INCLUDED_QUANTITY => bcdiv($value, 1000, 2),
            default => $value,
        };
    }
}
