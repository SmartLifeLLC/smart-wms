<?php

namespace App\Enums;

enum BadgeColor: string
{
    case PRIMARY = 'PRIMARY';
    case VIVID = 'VIVID';
    case BLACK = 'BLACK';
    case WHITE = 'WHITE';
    case GRAY = 'GRAY';
    case BLUE = 'BLUE';
    case RED = 'RED';
    case GREEN = 'GREEN';
    case YELLOW = 'YELLOW';
    case INDIGO = 'INDIGO';
    case PURPLE = 'PURPLE';
    case PINK = 'PINK';
    case ORANGE = 'ORANGE';


    public function bg() : string
    {
        return match ($this) {
            self::PRIMARY => 'bg-primary-100',
            self::VIVID => 'bg-vivid-100',
            self::BLACK => 'bg-black',
            self::WHITE => 'bg-white',
            self::GRAY => 'bg-gray-100',
            self::BLUE => 'bg-blue-100',
            self::RED => 'bg-red-100',
            self::GREEN => 'bg-green-100',
            self::YELLOW => 'bg-yellow-100',
            self::INDIGO => 'bg-indigo-100',
            self::PURPLE => 'bg-purple-100',
            self::PINK => 'bg-pink-100',
            self::ORANGE => 'bg-orange-100',
        };
    }

    public function border() : string
    {
        return match ($this) {
            self::PRIMARY => 'border-primary-400',
            self::VIVID => 'border-vivid-400',
            self::BLACK,
            self::WHITE => 'border-black',
            self::GRAY => 'border-gray-400',
            self::BLUE => 'border-blue-400',
            self::RED => 'border-red-400',
            self::GREEN => 'border-green-400',
            self::YELLOW => 'border-yellow-400',
            self::INDIGO => 'border-indigo-400',
            self::PURPLE => 'border-purple-400',
            self::PINK => 'border-pink-400',
            self::ORANGE => 'border-orange-400',
        };
    }

    public function text() : string
    {
        return match ($this) {
            self::PRIMARY => 'text-primary-800',
            self::VIVID => 'text-vivid-800',
            self::BLACK => 'text-white',
            self::WHITE => 'text-black',
            self::GRAY => 'text-gray-800',
            self::BLUE => 'text-blue-800',
            self::RED => 'text-red-800',
            self::GREEN => 'text-green-800',
            self::YELLOW => 'text-yellow-800',
            self::INDIGO => 'text-indigo-800',
            self::PURPLE => 'text-purple-800',
            self::PINK => 'text-pink-800',
            self::ORANGE => 'text-orange-800',
        };
    }

    public function all() : string {
        return $this->bg() . ' ' . $this->border() . ' ' . $this->text();
    }
}
