<?php

namespace App\Models\Sakemaru;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contractor extends CustomModel {
    use HasFactory;

    protected $guarded = [];
    protected $casts = [];

    public function supplier(): BelongsTo {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'id');
    }
    public function leadTime(): BelongsTo {
        return $this->belongsTo(LeadTime::class);
    }
    public function warehouse_contractors(): HasMany {
        return $this->hasMany(WarehouseContractor::class, 'contractor_id', 'id');
    }
}
