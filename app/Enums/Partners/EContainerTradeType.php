<?php

namespace App\Enums\Partners;

use App\Enums\ItemTypes;
use App\Models\Earning;
use App\Models\Item;
use App\Traits\EnumExtensionTrait;
use Illuminate\Support\Arr;

enum EContainerTradeType: string
{
    use EnumExtensionTrait;

    // 変更時はhubも考慮
    case NORMAL = 'NORMAL';
    case CONTENTS_ONLY = 'CONTENTS_ONLY';
    case CONTAINER_CONTENTS_ONLY = 'CONTAINER_CONTENTS_ONLY';

    public function name() : string
    {
        return match($this) {
            self::NORMAL => '通常売',
            self::CONTENTS_ONLY => '中身売',
            self::CONTAINER_CONTENTS_ONLY => '回収のみ中身売',
        };
    }
    public function getID() : int
    {
        return match($this) {
            self::NORMAL => 1,
            self::CONTENTS_ONLY => 2,
            self::CONTAINER_CONTENTS_ONLY => 3,
        };
    }

    public static function partnerOptions($is_supplier = false): array
    {
        return Arr::mapWithKeys(self::cases(), function ($case) use ($is_supplier) {
            if ($is_supplier && self::CONTAINER_CONTENTS_ONLY->isSameAs($case)) {
                return [];
            }
            return [$case->getID() => $case->name()];
        });
    }

    public function hubID() : int
    {
        return match($this) {
            self::NORMAL => 0,
            self::CONTENTS_ONLY => 1,
            self::CONTAINER_CONTENTS_ONLY => 2,
        };
    }

    public function isContentsOnly(bool $is_container_pickup) : bool
    {
        return match($this) {
            self::NORMAL => false,
            self::CONTENTS_ONLY => true,
            self::CONTAINER_CONTENTS_ONLY => $is_container_pickup,
        };
    }

    public function shouldSetTaxExemptPrice(?Item $item) : bool
    {
        switch ($this){
            case EContainerTradeType::CONTAINER_CONTENTS_ONLY:
                if(is_null($item)) {
                    return true;
                }

                switch ($item->type) {
                    case ItemTypes::CONTAINER->value:
                        return false;
                    case ItemTypes::ALCOHOL->value:
                    case ItemTypes::NOT_ALCOHOL->value:
                        return true;
                }
                break;
            case EContainerTradeType::CONTENTS_ONLY:
                return false;
        }
        return true;
    }
}
