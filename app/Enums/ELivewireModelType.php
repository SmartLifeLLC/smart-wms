<?php


namespace App\Enums;


namespace App\Enums;

use App\Traits\EnumExtensionTrait;

enum ELivewireModelType: string
{
    use EnumExtensionTrait;

    case LIVE = 'LIVE';
    case BLUR = 'BLUR';
    case DEFER = 'DEFER';
    case LAZY = 'LAZY';


    public function attribute() : string
    {
        return match ($this) {
            self::LIVE => 'wire:model.live',
            self::BLUR => 'wire:model.blur',
            self::LAZY => 'wire:model.lazy',
            self::DEFER => 'wire:model',
        };
    }

}
