<?php


namespace App\Enums;


namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum FilterType: string
{
    use EnumExtensionTrait;

    case INPUT = 'input';
    case RANGE = 'range';
    case TOGGLE = 'toggle';
    case CHECKBOXES = 'checkboxes';
    case SIMPLE_SELECTOR = 'simple_selector';
    case TABLE_SELECTOR = 'table_selector';
    case MULTIPLE_SELECTOR = 'multiple_selector';
}
