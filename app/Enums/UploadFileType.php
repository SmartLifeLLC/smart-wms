<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum UploadFileType: string
{
    use EnumExtensionTrait;

    case S3 = 'S3';

    public function name() : string
    {
        return match ($this) {
            self::S3 => 'S3'
        };
    }
}
