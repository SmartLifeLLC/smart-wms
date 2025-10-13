<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum DirectPrintOut: int
{
    use EnumExtensionTrait;

    case DISUSE = 0;
    case USE = 1;
}
