<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemCategory extends CustomModel
{
    use HasFactory;
    protected $guarded = [];


    public function alcohol_tax_category(): BelongsTo
    {
        return $this->belongsTo(AlcoholTaxCategory::class);
    }
}
