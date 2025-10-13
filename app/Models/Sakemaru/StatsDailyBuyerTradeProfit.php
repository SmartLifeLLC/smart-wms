<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Model;


//基本的にはLEFT JOINを利用(データが多くなるのを想定）
class StatsDailyBuyerTradeProfit extends StatsModel
{
    public $timestamps = false;
    protected $guarded = [];
    public static function getTableName(): string
    {
        return 'stats_daily_buyer_trade_profits';
    }
}
