<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WmsEosIncomingReceiveSchedule extends WmsModel
{
    protected $table = 'wms_eos_incoming_receive_schedules';

    public const TYPE_DAILY = 'DAILY';

    public const TYPE_WEEKLY_EXTRA = 'WEEKLY_EXTRA';

    protected $fillable = [
        'setting_id',
        'schedule_type',
        'day_of_week',
        'slot_no',
        'receive_time',
        'is_enabled',
        'auto_purchase_transmission_enabled',
    ];

    protected $casts = [
        'day_of_week' => 'integer',
        'slot_no' => 'integer',
        'is_enabled' => 'boolean',
        'auto_purchase_transmission_enabled' => 'boolean',
    ];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(WmsEosIncomingReceiveSetting::class, 'setting_id');
    }

    public function scopeDueAt(Builder $query, CarbonInterface $now): Builder
    {
        $time = $now->format('H:i:00');
        $dayOfWeek = (int) $now->format('w');

        return $query
            ->where('is_enabled', true)
            ->whereNotNull('receive_time')
            ->whereTime('receive_time', $time)
            ->where(function (Builder $query) use ($dayOfWeek): void {
                $query
                    ->where('schedule_type', self::TYPE_DAILY)
                    ->orWhere(function (Builder $query) use ($dayOfWeek): void {
                        $query
                            ->where('schedule_type', self::TYPE_WEEKLY_EXTRA)
                            ->where('day_of_week', $dayOfWeek);
                    });
            });
    }

    public function label(): string
    {
        if ($this->schedule_type === self::TYPE_DAILY) {
            return '毎日 '.substr((string) $this->receive_time, 0, 5);
        }

        $day = self::dayLabels()[(int) $this->day_of_week] ?? '-';

        return "{$day}曜 追加{$this->slot_no} ".substr((string) $this->receive_time, 0, 5);
    }

    public static function dayLabels(): array
    {
        return [
            0 => '日',
            1 => '月',
            2 => '火',
            3 => '水',
            4 => '木',
            5 => '金',
            6 => '土',
        ];
    }
}
