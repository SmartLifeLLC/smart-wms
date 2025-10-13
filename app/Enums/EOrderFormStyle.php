<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum EOrderFormStyle: string
{
    use EnumExtensionTrait;

    case A4_1C20R = '1c20r';
    case A4_1C40R = '1c40r';
    case A4_2C90R = '2c90r';

    public static function fromRowCount(int $row_count): EOrderFormStyle
    {
        if($row_count >= 40) {
            return self::A4_2C90R;
        } else if ($row_count >= 20) {
            return self::A4_1C40R;
        } else {
            return self::A4_1C20R;
        }
    }

    public function name() : string
    {
        return match($this) {
            self::A4_1C20R => 'A4 1列 20明細',
            self::A4_1C40R => 'A4 1列 40明細',
            self::A4_2C90R => 'A4 2列 90明細',
        };
    }

    public function getID() : int
    {
        return match($this) {
            self::A4_1C20R => 1,
            self::A4_1C40R => 2,
            self::A4_2C90R => 3,
        };
    }
}
