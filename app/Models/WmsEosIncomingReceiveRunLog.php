<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WmsEosIncomingReceiveRunLog extends WmsModel
{
    protected $table = 'wms_eos_incoming_receive_run_logs';

    protected $fillable = [
        'run_id',
        'level',
        'step',
        'message',
        'jx_transmission_log_id',
        'incoming_received_file_id',
        'incoming_schedule_id',
        'purchase_queue_id',
        'context',
    ];

    protected $casts = [
        'context' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(WmsEosIncomingReceiveRun::class, 'run_id');
    }
}
