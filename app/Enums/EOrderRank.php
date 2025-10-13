<?php

namespace App\Enums;

use App\Traits\EnumExtensionTrait;
use Illuminate\Support\Arr;
use function Aws\map;

enum EOrderRank: string
{
    use EnumExtensionTrait;

    case ORDER_POINT_AUTO = 'ORDER_POINT_AUTO';
    case ORDER_POINT_PRE = 'ORDER_POINT_PRE';
    case ORDER_AUTO = 'ORDER_AUTO';
    case ORDER_MANUAL = 'ORDER_MANUAL';
    case ORDER_STOP = 'ORDER_STOP';
    case ORDER_INPUT = 'ORDER_INPUT';


    public static function fromSymbol($symbol)
    {
        return match ($symbol) {
            'A' => self::ORDER_POINT_AUTO,
            'B' => self::ORDER_POINT_PRE,
            'C' => self::ORDER_AUTO,
            'D' => self::ORDER_MANUAL,
            'E' => self::ORDER_STOP,
            'F' => self::ORDER_INPUT,
            default => self::ORDER_POINT_PRE,
        };
    }

    public function name(): string
    {
        return match ($this) {
            self::ORDER_POINT_AUTO => '発注点（自動）',
            self::ORDER_POINT_PRE => '発注点（事前）',
            self::ORDER_AUTO => '受発注（自動）',
            self::ORDER_MANUAL => '受発注（手動）',
            self::ORDER_STOP => '発注停止',
            self::ORDER_INPUT => '手入力発注',
        };
    }

    public function symbol(): string
    {
        return match ($this) {
            self::ORDER_POINT_AUTO => 'A',
            self::ORDER_POINT_PRE => 'B',
            self::ORDER_AUTO => 'C',
            self::ORDER_MANUAL => 'D',
            self::ORDER_STOP => 'E',
            self::ORDER_INPUT => 'F',
        };
    }

    public static function symbolNames() : array
    {
        return Arr::mapWithKeys(self::cases(), function ($case) {
            return [$case->symbol() => $case->name()];
        });
    }

    public function getID(): int
    {
        return match ($this) {
            self::ORDER_POINT_AUTO => 1,
            self::ORDER_POINT_PRE => 2,
            self::ORDER_AUTO => 3,
            self::ORDER_MANUAL => 4,
            self::ORDER_STOP => 5,
            self::ORDER_INPUT => 6,
        };
    }
}
