<?php


namespace App\Enums\Partners;

use App\Enums\TaxRate;
use App\Traits\EnumExtensionTrait;

enum ETradeDirection: string
{
    use EnumExtensionTrait;

    case NORMAL = 'NORMAL';
    case RETURN = 'RETURN';
    case SPONSOR = 'SPONSOR';
    case INVENTORY = 'INVENTORY';
    case ITEM_SET = 'ITEM_SET';


    public function name(): string
    {
        return match ($this) {
            self::NORMAL => '通常',
            self::RETURN => '返品',
            self::SPONSOR => '協賛',
            self::INVENTORY => '在庫調整',
            self::ITEM_SET => 'セット調整',
        };
    }

    public function getID(): int
    {
        return match ($this) {
            self::NORMAL => 0,
            self::RETURN => 1,
            self::SPONSOR => 2,
            self::INVENTORY => 3,
            self::ITEM_SET => 4,
        };
    }

    public function getIsShowsMinusMark(): string
    {
        return match ($this) {
            self::NORMAL, self::SPONSOR => "0",
            self::RETURN => "1",self::INVENTORY => "0",
            self::ITEM_SET => "0",
        };
    }

    public static function fromBool(bool $is_returned) : self
    {
        return $is_returned ? self::RETURN : self::NORMAL;
    }

    public static function idNamesForTradeItemsDirect(): array
    {
        return [
            self::NORMAL->getID() => self::NORMAL->name(),
            self::RETURN->getID() => self::RETURN->name(),
        ];
    }

    public static function idNamesForTradeItems(): array
    {
        return [
            self::NORMAL->getID() => self::NORMAL->name(),
            self::RETURN->getID() => self::RETURN->name(),
            self::SPONSOR->getID() => self::SPONSOR->name(),
            self::INVENTORY->getID() => self::INVENTORY->name(),
        ];
    }
}
