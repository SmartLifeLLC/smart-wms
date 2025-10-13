<?php

namespace App\Models\Sakemaru;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DashboardItemsUsers extends Model
{
    use HasFactory;
    protected $table = 'dashboard_items_users';

    protected $fillable = [
        'user_id',
        'dashboard_item_id',
    ];
}
