<?php

namespace App\Models\Sakemaru;


use App\Traits\LogPdfTrait;
use App\Traits\SyncTradeTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;


class ExternalCollaborationDeposit extends CustomModel
{
    use HasFactory;
    use SyncTradeTrait;
    use LogPdfTrait;


    protected $guarded = [];
    protected $casts = [];
    protected bool $is_active_activate = false;

    public function partner(){
        return $this->belongsTo(Partner::class);
    }

    public function external_collaboration_data(){
        return $this->belongsTo(ExternalCollaborationData::class);
    }

    public function external_collaboration_deposit_detail_lines(){
        return $this->hasMany(ExternalCollaborationDepositDetailLine::class);
    }

}
