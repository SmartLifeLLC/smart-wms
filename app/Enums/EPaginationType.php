<?php


namespace App\Enums;


namespace App\Enums;

use App\Traits\EnumExtensionTrait;
use Illuminate\Database\Eloquent\Builder;

enum EPaginationType: string
{
    use EnumExtensionTrait;

    case NORMAL = 'NORMAL';
    case CURSOR = 'CURSOR';
    case SIMPLE = 'SIMPLE';
    case SIMPLE_ASYNC = 'SIMPLE_ASYNC';


    public function name() : string
    {
        return match ($this) {
            self::NORMAL => '通常',
            self::CURSOR => 'カーソル',
            self::SIMPLE => 'シンプル',
            self::SIMPLE_ASYNC => 'シンプル（非同期）'
        };
    }

    public function paginate(Builder $query, int $per_page, string $page_key) : mixed
    {
        return match ($this) {
            self::NORMAL => $query->paginate($per_page, ['*'], $page_key),
            self::CURSOR => $query->cursorPaginate($per_page, ['*'], $page_key),
            self::SIMPLE, self::SIMPLE_ASYNC => $query->simplePaginate($per_page, ['*'], $page_key),
        };
    }
}
