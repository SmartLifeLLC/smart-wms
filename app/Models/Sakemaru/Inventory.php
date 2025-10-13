<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class Inventory extends CustomModel
{
    use HasFactory;

    protected $guarded = [];
    protected $casts = [];
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
}
