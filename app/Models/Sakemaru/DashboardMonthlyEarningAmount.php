<?php

namespace App\Models\Sakemaru;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DashboardMonthlyEarningAmount extends Model
{
    protected $table = 'dashboard_monthly_earning_amounts';

    protected $fillable = [
        'client_id',
        'target_month',
        'amount'
    ];
}
