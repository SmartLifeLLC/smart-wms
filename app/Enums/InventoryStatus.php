<?php


namespace App\Enums;

use App\Enums\TaxRate;
use App\Traits\EnumExtensionTrait;

enum InventoryStatus: string
{
    use EnumExtensionTrait;

    case UNCONFIRMED = 'UNCONFIRMED';
    case IN_PROGRESS = 'IN_PROGRESS';
    case CONFIRMED = 'CONFIRMED';
    case CANCELED = 'CANCELED';

    public function name(): string
    {
        return match ($this) {
            self::UNCONFIRMED => '未確定', //棚卸開始処理を実行した状態
            self::IN_PROGRESS => '入力中', //実棚入力中の状態
            self::CONFIRMED => '確定済', //棚卸確定済の状態
            self::CANCELED => '取消', //棚卸処理を取消した状態（未確定のみ取消可）
        };
    }

    public function getID(): int
    {
        return match ($this) {
            self::UNCONFIRMED => 0,
            self::IN_PROGRESS => 1,
            self::CONFIRMED => 2,
            self::CANCELED => 3,
        };
    }

    public function color(): BadgeColor|null
    {
        return match ($this) {
            self::UNCONFIRMED => BadgeColor::YELLOW,
            self::IN_PROGRESS => BadgeColor::BLUE,
            self::CONFIRMED => BadgeColor::GREEN,
            self::CANCELED => BadgeColor::RED,
        };
    }
}
