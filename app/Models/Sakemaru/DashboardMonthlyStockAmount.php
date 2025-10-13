<?php

namespace App\Models\Sakemaru;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DashboardMonthlyStockAmount extends Model
{
    protected $table = 'dashboard_monthly_stock_amounts';

    protected $fillable = [
        'client_id',
        'warehouse_id',
        'target_month',
        'amount'
    ];
}
