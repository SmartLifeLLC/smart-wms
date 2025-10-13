<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class TradeCandidateItem extends CustomModel
{
    use HasFactory;

    protected $guarded = [];
    protected $casts = [];

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}
