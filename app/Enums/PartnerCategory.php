<?php


namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum PartnerCategory: string
{
    use EnumExtensionTrait;

    case SUPPLIER = 'SUPPLIER';
    case DELIVERY_ALLY = 'DELIVERY_ALLY';
    case WHOLESALER = 'WHOLESALER';
//    case DELIVERY_DESTINATION = 'DELIVERY_DESTINATION';
    case SECONDARY_WHOLESALER = 'SECONDARY_WHOLESALER';
    case RETAILER = 'RETAILER';
    case ADJUSTMENT = 'ADJUSTMENT';

    public function name(): string
    {
        return match ($this) {
            self::SUPPLIER => '仕入先',
            self::DELIVERY_ALLY => '代配先',
            self::WHOLESALER => '卸売業者',
//            self::DELIVERY_DESTINATION => '届け先',
            self::SECONDARY_WHOLESALER => '小売業者',
            self::RETAILER => '小売先',
            self::ADJUSTMENT => '調整用',
        };
    }
    public function getID() : int
    {
        return match($this) {
            self::SUPPLIER => 1,
            self::DELIVERY_ALLY => 2,
            self::WHOLESALER => 3,
//            self::DELIVERY_DESTINATION => 4,
            self::SECONDARY_WHOLESALER => 4,
            self::RETAILER => 5,
            self::ADJUSTMENT => 6,
        };
    }

    public static function fromPrevID(int $id) : self
    {
        return match($id) {
            0 => self::SUPPLIER,
            10 => self::WHOLESALER,
//            20 => self::DELIVERY_DESTINATION,
            30 => self::SECONDARY_WHOLESALER,
            40 => self::RETAILER,
            default => self::ADJUSTMENT,
        };
    }

    public static function supplierCategories(): array
    {
        return [
            self::SUPPLIER,
            self::DELIVERY_ALLY,
            self::ADJUSTMENT
        ];
    }

    public static function buyerCategories(): array
    {
        return [
            self::WHOLESALER,
//            self::DELIVERY_DESTINATION,
            self::SECONDARY_WHOLESALER,
            self::RETAILER,
        ];
    }

    public function isSupplier()
    {
        return match ($this) {
            self::SUPPLIER,
            self::DELIVERY_ALLY,
            self::ADJUSTMENT => true,
            default => false,
        };
    }
    public function isBuyer()
    {
        return match ($this) {
            self::WHOLESALER,
//            self::DELIVERY_DESTINATION,
            self::SECONDARY_WHOLESALER,
            self::RETAILER => true,
            default => false,
        };
    }
}
