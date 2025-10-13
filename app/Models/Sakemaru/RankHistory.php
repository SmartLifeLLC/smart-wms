<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RankHistory extends CustomModel {
    use HasFactory;

    protected $guarded = [];

    public function item() {
        return $this->belongsTo(Item::class);
    }

    public function warehouse() {
        return $this->belongsTo(Warehouse::class);
    }
}
