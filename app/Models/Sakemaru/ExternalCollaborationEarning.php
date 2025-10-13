<?php

namespace App\Models\Sakemaru;


use App\Traits\LogPdfTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;


class ExternalCollaborationEarning extends CustomModel
{
    use LogPdfTrait;


    protected $guarded = [];
    protected $casts = [
        'log' => 'json',
    ];
    protected bool $is_active_activate = false;

    public function warehouse(){
        return $this->belongsTo(Warehouse::class);
    }

    public function partner(){
        return $this->belongsTo(Partner::class);
    }

    public function external_collaboration_data(){
        return $this->belongsTo(ExternalCollaborationData::class);
    }

    public function stock_allocation(){
        return $this->belongsTo(StockAllocation::class);
    }

    public function item(){
        return $this->belongsTo(Item::class);
    }

    public function trade() : BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }
}
