<?php

namespace App\Enums\AutoOrder;

enum OrderChannel: string
{
    case EOS = 'EOS';
    case FAX = 'FAX';

    public function label(): string
    {
        return match ($this) {
            self::EOS => 'EOS発注',
            self::FAX => 'FAX発注',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::EOS => 'info',
            self::FAX => 'warning',
        };
    }
}
