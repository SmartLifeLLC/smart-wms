<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;
use Illuminate\Support\Arr;

enum EAllocationType: string
{
    use EnumExtensionTrait;

    case DEPOSIT = 'DEPOSIT';
    case DISCOUNT = 'DISCOUNT';
    case CONTAINER_PICKUP = 'CONTAINER_PICKUP';

    public function name() : string
    {
        return match ($this) {
            self::DEPOSIT => '入金',
            self::DISCOUNT => '値引',
            self::CONTAINER_PICKUP => '容器回収',
        };
    }

    public function getID() : int
    {
        return match ($this) {
            self::DEPOSIT => 0,
            self::DISCOUNT => 1,
            self::CONTAINER_PICKUP => 2,
        };
    }

    public function allocationCol() : string
    {
        return match ($this) {
            self::DEPOSIT => 'allocation_amount',
            self::DISCOUNT => 'discount_amount',
            self::CONTAINER_PICKUP => 'container_pickup_amount',
        };
    }

    public static function idNames(bool $with_unspecified = false) : array
    {
        if ($with_unspecified) {
            $cases = self::cases();
        } else {
            $cases = [
                self::DEPOSIT,
                self::DISCOUNT,
            ];
        }

        return Arr::mapWithKeys($cases, function ($case) {
            return [$case->getID() => $case->name()];
        });
    }
}
