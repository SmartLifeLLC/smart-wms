<?php

namespace App\Models\Sakemaru;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DashboardDailyEarningAmount extends Model
{
    protected $table = 'dashboard_daily_earning_amounts';

    protected $fillable = [
        'client_id',
        'target_date',
        'amount'
    ];
}
