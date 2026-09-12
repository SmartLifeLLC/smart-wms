<?php

namespace App\Enums\AutoOrder;

enum OrderEntrySource: string
{
    case SALES_HISTORY = 'SALES_HISTORY';
    case SEARCH = 'SEARCH';

    public function label(): string
    {
        return match ($this) {
            self::SALES_HISTORY => '販売履歴より生成',
            self::SEARCH => '候補検索から生成',
        };
    }
}
