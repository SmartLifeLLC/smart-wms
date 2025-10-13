<?php

namespace App\Models\Sakemaru;


use App\Enums\PrintType;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class LogPdfExport extends CustomModel
{
    protected $guarded = [];

    public function trades(): MorphMany
    {
        return $this->morphMany(Trade::class, 'model');
    }

}
