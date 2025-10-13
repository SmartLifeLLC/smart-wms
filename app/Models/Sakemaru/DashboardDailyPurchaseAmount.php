<?php

namespace App\Models\Sakemaru;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DashboardDailyPurchaseAmount extends Model
{
    protected $table = 'dashboard_daily_purchase_amounts';

    protected $fillable = [
        'client_id',
        'target_date',
        'amount'
    ];
}
