<?php


namespace App\Enums;


namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum FieldFormat: string
{
    use EnumExtensionTrait;

    case DATETIME = 'datetime';
    case DATE = 'date';
    case MONTH = 'month';
    case TEXT = 'text';
    case TEXTAREA = 'text_area';
    case PASSWORD = 'password';
    case NUMBER = 'number';
    case INTEGER = 'integer';
    case CODE = 'code';
    case BOOLEAN = 'boolean';
    case SELECTOR = 'selector';
    case FOREIGN_KEY = 'foreign_key';
    case MULTIPLE_FOREIGN_KEY = 'multiple_foreign_key';
    case HAS_MANY = 'has_many';
    case UNDEFINED = 'undefined';
    case FOREIGN_LABEL = 'foreign_label';
    case NUMBER_SCALE_4 = 'number_scale_4';

    public function filterType(): FilterType
    {
        return match ($this) {
            self::SELECTOR => FilterType::CHECKBOXES,
            self::BOOLEAN => FilterType::TOGGLE,
            self::DATETIME,
            self::DATE,
            self::MONTH,
            self::NUMBER,
            self::NUMBER_SCALE_4,
            self::INTEGER => FilterType::RANGE,
            self::FOREIGN_KEY => FilterType::TABLE_SELECTOR,
            self::HAS_MANY,
            self::MULTIPLE_FOREIGN_KEY => FilterType::MULTIPLE_SELECTOR,
            default => FilterType::INPUT,
        };
    }

    public function inputType(): string
    {
        return match ($this) {
            self::DATETIME,
            self::DATE => 'date',
            self::MONTH => 'month',
            self::PASSWORD => 'password',
            default => 'text',
        };
    }

    public function displayFormat(mixed $value): mixed
    {
        return match ($this) {
            self::NUMBER => numberOrNull($value, 2),
            self::NUMBER_SCALE_4 => numberOrNull($value, 4),
            self::INTEGER => numberOrNull($value),
            self::BOOLEAN => (bool)$value,
            self::DATE => $value ? toCarbon($value)->toDateString() : null,
            default => $value,
        };
    }

    public function reformat(mixed $value): mixed
    {
        return match ($this) {
            self::INTEGER,
            self::NUMBER,
            self::NUMBER_SCALE_4,
            self::FOREIGN_KEY => convertToHalf($value),
            default => $value,
        };
    }

    public function textPosition(): string
    {
        return match ($this) {
            self::CODE,
            self::NUMBER,
            self::NUMBER_SCALE_4,
            self::FOREIGN_KEY,
            self::MULTIPLE_FOREIGN_KEY,
            self::INTEGER => 'text-right',
            self::SELECTOR => 'text-center',
            default => 'text-left',
        };
    }

    public function inputPosition(): string
    {
        return match ($this) {
            self::CODE => 'text-left',
            default => $this->textPosition(),
        };
    }
}
