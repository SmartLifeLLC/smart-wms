<?php


namespace App\Enums;


namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum EditableType: string
{
    use EnumExtensionTrait;

    case DRAWER = 'DRAWER';
    case INLINE = 'INLINE';
}
