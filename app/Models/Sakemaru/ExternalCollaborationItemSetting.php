<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalCollaborationItemSetting extends CustomModel
{
    protected $guarded = [];
    protected $casts = [];

    public function item():belongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function container_return_supplier():belongsTo
    {
        return $this->belongsTo(Supplier::class, 'container_return_supplier_id', 'id');
    }
}
