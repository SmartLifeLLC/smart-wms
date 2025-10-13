<?php

namespace App\Models\Sakemaru;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class DashboardItems extends Model
{
    use HasFactory;
    protected $table = 'dashboard_items';

    protected $fillable = [
        'name',
        'type',
        'is_active',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'dashboard_items_users', 'dashboard_item_id', 'user_id')
        ->withTimestamps();
    }
}
