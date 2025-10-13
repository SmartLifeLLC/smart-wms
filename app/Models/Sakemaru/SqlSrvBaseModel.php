<?php

namespace App\Models\Sakemaru;
use Illuminate\Database\Eloquent\Model;


abstract  class SqlSrvBaseModel extends Model
{
    protected $connection= 'sqlsrv';

    public static function hasClient(): bool
    {
        return false;
    }

    abstract static function getTableName(): string;
}
