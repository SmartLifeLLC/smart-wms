<?php


namespace App\Enums;


namespace App\Enums;

use App\Traits\EnumExtensionTrait;
use Illuminate\Support\Arr;

enum BillingType: string
{
    use EnumExtensionTrait;

    case CASH = 'CASH';
    case DEFERRED = 'DEFERRED';
    case UNDEFINED = 'UNDEFINED';


    public function paymentName() : string
    {
        return match ($this) {
            self::CASH => '現金',
            self::DEFERRED => '買掛',
            self::UNDEFINED => '未割当',
        };
    }

    public function depositName() : string
    {
        return match ($this) {
            self::CASH => '現金',
            self::DEFERRED => '売掛',
            self::UNDEFINED => '未割当',
        };
    }

    public function getID() : int
    {
        return match ($this) {
            self::DEFERRED => 0,
            self::CASH => 1,
            self::UNDEFINED => 3,
        };
    }

    public static function generalCases() : array
    {
        return Arr::where(self::cases(), function ($case) {
            return !$case->isSameAs(self::UNDEFINED);
        });
    }

    public static function paymentNames($is_from_id = true, $included_undefined = false) : array
    {
        $cases = $included_undefined ? self::cases() : self::generalCases();
        return Arr::mapWithKeys($cases, function ($case) use ($is_from_id) {
            if ($is_from_id) {
                $key = $case->getID();
            } else {
                $key = $case->value;
            }
            return [
                $key => $case->paymentName()
            ];
        });
    }

    public static function depositNames($is_from_id = true, $included_undefined = false) : array
    {
        $cases = $included_undefined ? self::cases() : self::generalCases();
        return Arr::mapWithKeys($cases, function ($case) use ($is_from_id) {
            if ($is_from_id) {
                $key = $case->getID();
            } else {
                $key = $case->value;
            }

            return [
                $key => $case->depositName()
            ];
        });
    }


    public function color(): BadgeColor|null
    {
        return match ($this) {
            self::CASH => BadgeColor::PINK,
            self::DEFERRED => BadgeColor::BLUE,
            self::UNDEFINED => BadgeColor::GRAY,
        };
    }

}
