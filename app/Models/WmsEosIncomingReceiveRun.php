<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WmsEosIncomingReceiveRun extends WmsModel
{
    protected $table = 'wms_eos_incoming_receive_runs';

    public const TRIGGER_SCHEDULED = 'scheduled';

    public const TRIGGER_MANUAL = 'manual';

    public const STATUS_QUEUED = 'QUEUED';

    public const STATUS_RUNNING = 'RUNNING';

    public const STATUS_SUCCEEDED = 'SUCCEEDED';

    public const STATUS_PARTIAL_FAILED = 'PARTIAL_FAILED';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_SKIPPED = 'SKIPPED';

    protected $fillable = [
        'run_key',
        'setting_id',
        'schedule_id',
        'execution_date',
        'scheduled_time',
        'trigger_type',
        'status',
        'started_at',
        'finished_at',
        'active_jx_setting_count',
        'received_jx_document_count',
        'target_jx_log_count',
        'eos_imported_count',
        'incoming_matched_count',
        'incoming_unmatched_count',
        'incoming_confirmed_schedule_count',
        'purchase_queue_count',
        'purchase_transmitted_schedule_count',
        'purchase_skipped_warehouse91_count',
        'purchase_skipped_not_eos_sent_count',
        'unknown_slip_count',
        'shortage_completed_count',
        'error_count',
        'error_summary',
        'metadata',
    ];

    protected $casts = [
        'execution_date' => 'date',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'metadata' => 'array',
        'active_jx_setting_count' => 'integer',
        'received_jx_document_count' => 'integer',
        'target_jx_log_count' => 'integer',
        'eos_imported_count' => 'integer',
        'incoming_matched_count' => 'integer',
        'incoming_unmatched_count' => 'integer',
        'incoming_confirmed_schedule_count' => 'integer',
        'purchase_queue_count' => 'integer',
        'purchase_transmitted_schedule_count' => 'integer',
        'purchase_skipped_warehouse91_count' => 'integer',
        'purchase_skipped_not_eos_sent_count' => 'integer',
        'unknown_slip_count' => 'integer',
        'shortage_completed_count' => 'integer',
        'error_count' => 'integer',
    ];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(WmsEosIncomingReceiveSetting::class, 'setting_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(WmsEosIncomingReceiveSchedule::class, 'schedule_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(WmsEosIncomingReceiveRunLog::class, 'run_id');
    }

    public function addLog(string $level, string $step, string $message, array $context = []): WmsEosIncomingReceiveRunLog
    {
        return $this->logs()->create([
            'level' => $level,
            'step' => $step,
            'message' => $message,
            'jx_transmission_log_id' => $context['jx_transmission_log_id'] ?? null,
            'incoming_received_file_id' => $context['incoming_received_file_id'] ?? null,
            'incoming_schedule_id' => $context['incoming_schedule_id'] ?? null,
            'purchase_queue_id' => $context['purchase_queue_id'] ?? null,
            'context' => $context === [] ? null : $context,
        ]);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_QUEUED => '待機中',
            self::STATUS_RUNNING => '実行中',
            self::STATUS_SUCCEEDED => '完了',
            self::STATUS_PARTIAL_FAILED => '一部失敗',
            self::STATUS_FAILED => '失敗',
            self::STATUS_SKIPPED => 'スキップ',
            default => (string) $this->status,
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            self::STATUS_QUEUED => 'gray',
            self::STATUS_RUNNING => 'warning',
            self::STATUS_SUCCEEDED => 'success',
            self::STATUS_PARTIAL_FAILED => 'warning',
            self::STATUS_FAILED => 'danger',
            self::STATUS_SKIPPED => 'gray',
            default => 'gray',
        };
    }
}
