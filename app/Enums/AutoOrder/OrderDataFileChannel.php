<?php

namespace App\Enums\AutoOrder;

enum OrderDataFileChannel: string
{
    case EOS = 'EOS';
    case FAX = 'FAX';
    case JX_CONFIRMATION = 'JX_CONFIRMATION';

    public function label(): string
    {
        return match ($this) {
            self::EOS => 'EOS控え',
            self::FAX => 'FAX発注',
            self::JX_CONFIRMATION => 'JX確認',
        };
    }
}
