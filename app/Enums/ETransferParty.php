<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;
use Illuminate\Support\Str;

enum ETransferParty: string
{
    use EnumExtensionTrait;

    case SUMA_ORDER = 'SUMA_ORDER';
    case SUMA_CONTAINER = 'SUMA_CONTAINER';
    case INFOMART = 'INFOMART';

    public function name(): string
    {
        return match ($this) {
            self::SUMA_ORDER => 'スマ発注',
            self::SUMA_CONTAINER => 'スマ回収',
            self::INFOMART => 'インフォマート',
        };
    }

    public static function forPartners() : array
    {
        return [
            self::SUMA_ORDER,
            self::SUMA_CONTAINER,
            self::INFOMART,
        ];
    }

    public function key() : string
    {
        return Str::lower($this->value);
    }
}
