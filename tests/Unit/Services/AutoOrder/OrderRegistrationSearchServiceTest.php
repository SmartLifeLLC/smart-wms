<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Models\WmsContractorSetting;
use App\Services\AutoOrder\OrderRegistrationSearchService;
use Carbon\Carbon;
use ReflectionMethod;
use Tests\TestCase;

class OrderRegistrationSearchServiceTest extends TestCase
{
    public function test_jx_generation_cutoff_uses_ten_minutes_before_weekday_generation_time(): void
    {
        $setting = (new WmsContractorSetting)->forceFill([
            'jx_generation_time' => '13:30',
            'jx_generation_cutoff_time' => '13:00',
        ]);

        $cutoffAt = $this->jxGenerationCutoffAt('2026-08-10 09:00:00', $setting);

        $this->assertSame('2026-08-10 13:20:00', $cutoffAt?->format('Y-m-d H:i:s'));
    }

    public function test_jx_generation_cutoff_uses_ten_minutes_before_sunday_generation_time(): void
    {
        $setting = (new WmsContractorSetting)->forceFill([
            'jx_generation_time' => '13:30',
            'jx_generation_cutoff_time' => '13:20',
            'jx_generation_sunday_time' => '23:30',
            'jx_generation_sunday_cutoff_time' => '23:00',
        ]);

        $cutoffAt = $this->jxGenerationCutoffAt('2026-08-09 09:00:00', $setting);

        $this->assertSame('2026-08-09 23:20:00', $cutoffAt?->format('Y-m-d H:i:s'));
    }

    public function test_jx_generation_cutoff_falls_back_to_configured_cutoff_when_generation_time_is_empty(): void
    {
        $setting = (new WmsContractorSetting)->forceFill([
            'jx_generation_time' => null,
            'jx_generation_cutoff_time' => '13:20',
        ]);

        $cutoffAt = $this->jxGenerationCutoffAt('2026-08-10 09:00:00', $setting);

        $this->assertSame('2026-08-10 13:20:00', $cutoffAt?->format('Y-m-d H:i:s'));
    }

    public function test_eos_default_arrival_cutoff_is_reached_at_13_on_weekday(): void
    {
        $this->assertTrue($this->hasReachedEosDefaultArrivalCutoff('2026-08-10 13:00:00'));
    }

    public function test_eos_default_arrival_cutoff_is_not_reached_before_13_on_weekday(): void
    {
        $this->assertFalse($this->hasReachedEosDefaultArrivalCutoff('2026-08-10 12:59:59'));
    }

    public function test_eos_default_arrival_cutoff_is_reached_at_13_on_saturday(): void
    {
        $this->assertTrue($this->hasReachedEosDefaultArrivalCutoff('2026-08-15 13:00:00'));
    }

    public function test_eos_default_arrival_cutoff_is_not_applied_on_sunday(): void
    {
        $this->assertFalse($this->hasReachedEosDefaultArrivalCutoff('2026-08-16 23:59:59'));
    }

    private function jxGenerationCutoffAt(string $currentAt, WmsContractorSetting $setting): ?Carbon
    {
        $method = new ReflectionMethod(OrderRegistrationSearchService::class, 'jxGenerationCutoffAt');
        $method->setAccessible(true);

        return $method->invoke(new OrderRegistrationSearchService, Carbon::parse($currentAt), $setting);
    }

    private function hasReachedEosDefaultArrivalCutoff(string $currentAt): bool
    {
        $method = new ReflectionMethod(OrderRegistrationSearchService::class, 'hasReachedEosDefaultArrivalCutoff');
        $method->setAccessible(true);

        return $method->invoke(new OrderRegistrationSearchService, Carbon::parse($currentAt));
    }
}
