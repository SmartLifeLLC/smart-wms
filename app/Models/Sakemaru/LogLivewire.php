<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Model;

class LogLivewire extends CustomModel
{
    protected $guarded = [];
    protected $casts = [
        'properties' => 'array',
    ];
}
