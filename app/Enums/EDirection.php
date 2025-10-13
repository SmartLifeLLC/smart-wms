<?php


namespace App\Enums;


namespace App\Enums;

enum EDirection: string
{
    case UP = 'UP';
    case DOWN = 'DOWN';
    case LEFT = 'LEFT';
    case RIGHT = 'RIGHT';

    public function arrowIcon() : string
    {
        return match ($this) {
            self::UP => 'fa-chevron-up',
            self::DOWN => 'fa-chevron-down',
            self::LEFT => 'fa-chevron-left',
            self::RIGHT => 'fa-chevron-right',
        };
    }

    public function position() : string
    {
        return match ($this) {
            self::UP => '-top-10 left-0',
            self::DOWN => 'top-10 left-0',
            self::LEFT => 'top-0 -left-10',
            self::RIGHT => 'top-0 -right-10',
        };
    }
}
