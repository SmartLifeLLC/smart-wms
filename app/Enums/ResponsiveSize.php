<?php


namespace App\Enums;


namespace App\Enums;

enum ResponsiveSize: string
{
    case SMALL = 'SMALL';
    case MEDIUM = 'MEDIUM';
    case LARGE = 'LARGE';
    case XLARGE = 'XLARGE';
    case X2LARGE = 'X2LARGE';


    public function width() : string
    {
        return match ($this) {
            self::SMALL => '640',
            self::MEDIUM => '768',
            self::LARGE => '1024',
            self::XLARGE => '1280',
            self::X2LARGE => '1536',
        };
    }

}
