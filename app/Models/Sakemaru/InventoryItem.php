<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class InventoryItem extends CustomModel
{
    use HasFactory;

    protected $guarded = [];
    protected $casts = [];

    public function inventory()
    {
        return $this->belongsTo(Inventory::class);
    }
    public function floor()
    {
        return $this->belongsTo(Floor::class);
    }
    public function location()
    {
        return $this->belongsTo(Location::class);
    }
    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}
