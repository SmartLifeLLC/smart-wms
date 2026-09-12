<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class WmsEosIncomingReceiveSetting extends WmsModel
{
    protected $table = 'wms_eos_incoming_receive_settings';

    public const UNKNOWN_SLIP_REVIEW_ONLY = 'REVIEW_ONLY';

    protected $fillable = [
        'name',
        'is_enabled',
        'shortage_completion_days',
        'exclude_purchase_warehouse_code',
        'unknown_slip_policy',
        'last_run_at',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'shortage_completion_days' => 'integer',
        'last_run_at' => 'datetime',
    ];

    public function schedules(): HasMany
    {
        return $this->hasMany(WmsEosIncomingReceiveSchedule::class, 'setting_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(WmsEosIncomingReceiveRun::class, 'setting_id');
    }

    public static function ensureDefault(): self
    {
        $setting = self::query()->orderBy('id')->first();

        if (! $setting) {
            $setting = self::query()->create([
                'name' => 'EOSデータ受信設定',
                'is_enabled' => false,
                'shortage_completion_days' => 14,
                'exclude_purchase_warehouse_code' => '91',
                'unknown_slip_policy' => self::UNKNOWN_SLIP_REVIEW_ONLY,
            ]);
        }

        $setting->ensureDefaultSchedules();

        return $setting->fresh(['schedules']);
    }

    public function ensureDefaultSchedules(): void
    {
        $this->schedules()->updateOrCreate([
            'schedule_type' => WmsEosIncomingReceiveSchedule::TYPE_DAILY,
            'day_of_week' => 0,
            'slot_no' => 1,
        ], [
            'receive_time' => $this->normalizeTime($this->dailySchedule()?->receive_time) ?? '22:00:00',
            'is_enabled' => $this->dailySchedule()?->is_enabled ?? true,
            'auto_purchase_transmission_enabled' => true,
        ]);

        foreach ([1 => '10:00:00', 2 => '16:00:00'] as $slotNo => $time) {
            $this->schedules()->updateOrCreate([
                'schedule_type' => WmsEosIncomingReceiveSchedule::TYPE_WEEKLY_EXTRA,
                'day_of_week' => 1,
                'slot_no' => $slotNo,
            ], [
                'receive_time' => $this->weeklyExtraSchedule(1, $slotNo)?->receive_time ?? $time,
                'is_enabled' => $this->weeklyExtraSchedule(1, $slotNo)?->is_enabled ?? true,
                'auto_purchase_transmission_enabled' => true,
            ]);
        }
    }

    public function settingsFormData(): array
    {
        $this->loadMissing('schedules');

        $data = [
            'is_enabled' => (bool) $this->is_enabled,
            'daily_receive_time' => $this->timeForForm($this->dailySchedule()?->receive_time),
            'shortage_completion_days' => (int) $this->shortage_completion_days,
            'exclude_purchase_warehouse_code' => (string) $this->exclude_purchase_warehouse_code,
        ];

        foreach (WmsEosIncomingReceiveSchedule::dayLabels() as $day => $label) {
            for ($slot = 1; $slot <= 2; $slot++) {
                $schedule = $this->weeklyExtraSchedule((int) $day, $slot);
                $data["weekday_{$day}_slot_{$slot}_time"] = $this->timeForForm($schedule?->receive_time);
            }
        }

        return $data;
    }

    public function saveSettingsFormData(array $data): self
    {
        $this->update([
            'is_enabled' => (bool) ($data['is_enabled'] ?? false),
            'shortage_completion_days' => max(1, min(60, (int) ($data['shortage_completion_days'] ?? 14))),
            'exclude_purchase_warehouse_code' => trim((string) ($data['exclude_purchase_warehouse_code'] ?? '91')) ?: '91',
            'unknown_slip_policy' => self::UNKNOWN_SLIP_REVIEW_ONLY,
        ]);

        $this->schedules()->updateOrCreate([
            'schedule_type' => WmsEosIncomingReceiveSchedule::TYPE_DAILY,
            'day_of_week' => 0,
            'slot_no' => 1,
        ], [
            'receive_time' => $this->normalizeTime($data['daily_receive_time'] ?? null) ?? '22:00:00',
            'is_enabled' => true,
            'auto_purchase_transmission_enabled' => true,
        ]);

        foreach (WmsEosIncomingReceiveSchedule::dayLabels() as $day => $label) {
            for ($slot = 1; $slot <= 2; $slot++) {
                $time = $this->normalizeTime($data["weekday_{$day}_slot_{$slot}_time"] ?? null);

                $this->schedules()->updateOrCreate([
                    'schedule_type' => WmsEosIncomingReceiveSchedule::TYPE_WEEKLY_EXTRA,
                    'day_of_week' => (int) $day,
                    'slot_no' => $slot,
                ], [
                    'receive_time' => $time,
                    'is_enabled' => $time !== null,
                    'auto_purchase_transmission_enabled' => true,
                ]);
            }
        }

        return $this->fresh(['schedules']);
    }

    private function dailySchedule(): ?WmsEosIncomingReceiveSchedule
    {
        return $this->schedules
            ->first(fn (WmsEosIncomingReceiveSchedule $schedule): bool => $schedule->schedule_type === WmsEosIncomingReceiveSchedule::TYPE_DAILY);
    }

    private function weeklyExtraSchedule(int $dayOfWeek, int $slotNo): ?WmsEosIncomingReceiveSchedule
    {
        return $this->schedules
            ->first(fn (WmsEosIncomingReceiveSchedule $schedule): bool => $schedule->schedule_type === WmsEosIncomingReceiveSchedule::TYPE_WEEKLY_EXTRA
                && (int) $schedule->day_of_week === $dayOfWeek
                && (int) $schedule->slot_no === $slotNo);
    }

    private function timeForForm(mixed $value): ?string
    {
        $time = $this->normalizeTime($value);

        return $time ? substr($time, 0, 5) : null;
    }

    private function normalizeTime(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->format('H:i:00');
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{2}:\d{2}$/', $value)) {
            return "{$value}:00";
        }

        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $value)) {
            return $value;
        }

        return Carbon::parse($value)->format('H:i:00');
    }
}
