<?php

namespace App\Domains\Sakemaru;

class SakemaruEarning extends SakemaruModel
{
    protected static function postUrl(): string
    {
        return static::baseUrl() . '/earnings';
    }
}
