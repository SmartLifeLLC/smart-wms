<?php

namespace Tests\Unit\Models;

use App\Models\WmsEosIncomingReceiveSchedule;
use App\Models\WmsEosIncomingReceiveSetting;
use Carbon\Carbon;
use Tests\TestCase;

class WmsEosIncomingReceiveSettingTest extends TestCase
{
    private ?int $settingId = null;

    protected function tearDown(): void
    {
        if ($this->settingId !== null) {
            WmsEosIncomingReceiveSchedule::query()
                ->where('setting_id', $this->settingId)
                ->delete();

            WmsEosIncomingReceiveSetting::query()
                ->whereKey($this->settingId)
                ->delete();
        }

        parent::tearDown();
    }

    public function test_default_schedule_registration_and_due_scope(): void
    {
        $setting = WmsEosIncomingReceiveSetting::query()->create([
            'name' => 'test-eos-incoming-setting',
            'is_enabled' => true,
            'shortage_completion_days' => 14,
            'exclude_purchase_warehouse_code' => '91',
            'unknown_slip_policy' => WmsEosIncomingReceiveSetting::UNKNOWN_SLIP_REVIEW_ONLY,
        ]);
        $this->settingId = (int) $setting->id;

        $setting->ensureDefaultSchedules();

        $mondayMorning = WmsEosIncomingReceiveSchedule::query()
            ->where('setting_id', $setting->id)
            ->dueAt(Carbon::parse('2026-07-27 10:00:00'))
            ->pluck('receive_time')
            ->map(fn ($time): string => substr((string) $time, 0, 5))
            ->all();

        $mondayEvening = WmsEosIncomingReceiveSchedule::query()
            ->where('setting_id', $setting->id)
            ->dueAt(Carbon::parse('2026-07-27 16:00:00'))
            ->pluck('receive_time')
            ->map(fn ($time): string => substr((string) $time, 0, 5))
            ->all();

        $mondayNight = WmsEosIncomingReceiveSchedule::query()
            ->where('setting_id', $setting->id)
            ->dueAt(Carbon::parse('2026-07-27 22:00:00'))
            ->pluck('receive_time')
            ->map(fn ($time): string => substr((string) $time, 0, 5))
            ->all();

        $tuesdayMorning = WmsEosIncomingReceiveSchedule::query()
            ->where('setting_id', $setting->id)
            ->dueAt(Carbon::parse('2026-07-28 10:00:00'))
            ->count();

        $this->assertSame(['10:00'], $mondayMorning);
        $this->assertSame(['16:00'], $mondayEvening);
        $this->assertSame(['22:00'], $mondayNight);
        $this->assertSame(0, $tuesdayMorning);
    }
}
