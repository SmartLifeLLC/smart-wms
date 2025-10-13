<?php

namespace App\Enums\Partners;

use App\Enums\BillingType;
use App\Models\Bill;
use App\Traits\EnumExtensionTrait;
use Illuminate\Support\Arr;

enum EPaymentMethod: string
{
    use EnumExtensionTrait;

    // 変更時はhubも考慮
    case DEPOSIT = 'DEPOSIT';
//    case DOWN_PAYMENT = 'DOWN_PAYMENT';
    case CASH = 'CASH';


    public function name() : string
    {
        return match($this) {
            self::DEPOSIT => '買掛',
//            self::DOWN_PAYMENT => '即金',
            self::CASH => '現金',
        };
    }
    public function getID() : int
    {
        return match($this) {
            self::DEPOSIT => 0,
//            self::DOWN_PAYMENT => 2,
            self::CASH => 1,
        };
    }
    public function supplierName() : string
    {
        return match($this) {
            self::DEPOSIT => '買掛',
            self::CASH => '現金',
        };
    }
    public function buyerName() : string
    {
        return match($this) {
            self::DEPOSIT => '売掛',
            self::CASH => '現金',
        };
    }
    public static function partnerOptions(bool $is_supplier) : array
    {
        if($is_supplier) {
            return Arr::mapWithKeys(self::cases(), function ($case) {
                return [$case->getID() => $case->supplierName()];
            });
        }
        else {
            return Arr::mapWithKeys(self::cases(), function ($case) {
                return [$case->getID() => $case->buyerName()];
            });
        }
    }

    public static function partnerNameOptions(bool $is_supplier): array {
        if ($is_supplier) {
            return Arr::mapWithKeys(self::cases(), function ($case) {
                return [$case->name => $case->supplierName()];
            });
        } else {
            return Arr::mapWithKeys(self::cases(), function ($case) {
                return [$case->name => $case->buyerName()];
            });
        }
    }

    public function billingType() : BillingType
    {
        return match($this) {
            self::CASH => BillingType::CASH,
            self::DEPOSIT => BillingType::DEFERRED,
        };
    }
}
