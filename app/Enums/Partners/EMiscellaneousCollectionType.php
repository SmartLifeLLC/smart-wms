<?php

namespace App\Enums\Partners;

use App\Traits\EnumExtensionTrait;

enum EMiscellaneousCollectionType: string
{
    use EnumExtensionTrait;

    case NONE = 'NONE';
    case FREE = 'FREE';
    case PAID = 'PAID';

    public function name() : string
    {
        return match ($this) {
            self::NONE => '回収しない',
            self::FREE => '無償回収',
            self::PAID => '有償回収',
        };
    }

    public function getID() : int
    {
        return match ($this) {
            self::NONE => 0,
            self::FREE => 1,
            self::PAID => 2,
        };
    }

    public function mark(): string
    {
        return match ($this) {
            self::NONE => '▲',
            self::FREE => '○',
            self::PAID => '◎',
        };
    }

    public  static function fromMSDID(int $id){
        return match ($id){
            1=>self::FREE,
            2=>self::NONE,
            3=>self::PAID,
            default=>self::NONE,
        };
    }
}
