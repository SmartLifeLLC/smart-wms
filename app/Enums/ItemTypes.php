<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum ItemTypes: string
{
    use EnumExtensionTrait;

    case ALCOHOL = 'ALCOHOL';
    case NOT_ALCOHOL = 'NOT_ALCOHOL';
    case CONTAINER = 'CONTAINER';
    case COMMENT = 'COMMENT';

    public function name() : string
    {
        return match ($this) {
            self::ALCOHOL => '酒類',
            self::NOT_ALCOHOL => '酒以外',
            self::CONTAINER => '容器',
            self::COMMENT => 'コメント',
        };
    }

    /**
     * @return int
     */
    public function getID(): int
    {
        return match ($this) {
            self::ALCOHOL => 1,
            self::NOT_ALCOHOL => 2,
            self::CONTAINER => 3,
            self::COMMENT => 4,
        };
    }

    // 通常商品のID群
    public static function basicIds(): array
    {
        return [
            self::ALCOHOL->getID(),
            self::NOT_ALCOHOL->getID(),
        ];
    }
}
