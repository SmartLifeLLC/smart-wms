<?php

namespace App\Models\Sakemaru;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Company extends CustomModel
{
    use HasFactory;

    protected $guarded = [];
    protected $casts = [];
}
