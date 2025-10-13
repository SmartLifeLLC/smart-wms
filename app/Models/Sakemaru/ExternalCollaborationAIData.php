<?php

namespace App\Models\Sakemaru;

use App\Enums\EExternalCollaborationEDIType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalCollaborationAIData extends CustomModel
{
    use HasFactory;
    protected $casts = [];
    protected $guarded = [];
    protected $table = 'external_collaboration_ai_data';
    protected bool $is_active_activate = false;
    public function user():belongsTo
    {
        return $this->belongsTo(User::class,'creator_id','id');
    }
}
