<?php

namespace App\Models\Sakemaru;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DashboardDailyStockAmount extends Model
{
    protected $table = 'dashboard_daily_stock_amounts';

    protected $fillable = [
        'client_id',
        'warehouse_id',
        'target_date',
        'amount'
    ];
}
