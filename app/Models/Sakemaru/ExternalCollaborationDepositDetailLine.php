<?php

namespace App\Models\Sakemaru;


use App\Enums\PrintType;
use App\Traits\SyncTradeTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;


class ExternalCollaborationDepositDetailLine extends CustomModel
{
    use HasFactory;
    use SyncTradeTrait;


    protected $guarded = [];
    protected $casts = [];
    protected bool $is_active_activate = false;
    // protected PrintType $checklist_print_type = PrintType::EXTERNAL_DATA_IMPORT_EARNING_CHECK;

    public function external_collaboration_deposit(){
            return $this->belongsTo(ExternalCollaborationDeposit::class);
    }

    public function detail_line_trade_balances(){
        return $this->hasMany(ExternalCollaborationDepositDetailLineTradeBalance::class);
    }
}
