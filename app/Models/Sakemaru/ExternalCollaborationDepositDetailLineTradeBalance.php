<?php

namespace App\Models\Sakemaru;


use App\Enums\PrintType;
use App\Traits\LogPdfTrait;
use App\Traits\SyncTradeTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;


class ExternalCollaborationDepositDetailLineTradeBalance extends CustomModel
{
    use HasFactory;
    use SyncTradeTrait;
    use LogPdfTrait;


    protected $guarded = [];
    protected $casts = [];
    protected bool $is_active_activate = false;
    // protected PrintType $checklist_print_type = PrintType::EXTERNAL_DATA_IMPORT_EARNING_CHECK;

    public function ExternalCollaborationDepositDetailLine(){
            return $this->belongsTo(ExternalCollaborationDepositDetailLine::class);
    }

}
