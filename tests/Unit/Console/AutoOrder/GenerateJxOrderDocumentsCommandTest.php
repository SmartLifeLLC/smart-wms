<?php

namespace Tests\Unit\Console\AutoOrder;

use App\Console\Commands\AutoOrder\GenerateJxOrderDocumentsCommand;
use App\Models\WmsContractorSetting;
use Carbon\Carbon;
use ReflectionMethod;
use Tests\TestCase;

class GenerateJxOrderDocumentsCommandTest extends TestCase
{
    public function test_target_modified_at_range_starts_from_previous_weekday_cutoff(): void
    {
        [$from, $until] = $this->targetModifiedAtRange('2026-07-17', '13:20');

        $this->assertSame('2026-07-16 13:20:00', $from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-17 13:20:00', $until->format('Y-m-d H:i:s'));
    }

    public function test_target_modified_at_range_for_sunday_starts_from_saturday_cutoff(): void
    {
        [$from, $until] = $this->targetModifiedAtRange('2026-07-19', '23:00');

        $this->assertSame('2026-07-18 13:20:00', $from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-19 23:00:00', $until->format('Y-m-d H:i:s'));
    }

    public function test_target_modified_at_range_for_monday_starts_from_sunday_cutoff(): void
    {
        [$from, $until] = $this->targetModifiedAtRange('2026-07-20', '13:20');

        $this->assertSame('2026-07-19 23:00:00', $from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-20 13:20:00', $until->format('Y-m-d H:i:s'));
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function targetModifiedAtRange(string $targetDate, string $cutoffTime): array
    {
        $setting = (new WmsContractorSetting)->forceFill([
            'jx_generation_cutoff_time' => '13:20',
            'jx_generation_sunday_cutoff_time' => '23:00',
        ]);

        $method = new ReflectionMethod(GenerateJxOrderDocumentsCommand::class, 'targetModifiedAtRange');
        $method->setAccessible(true);

        return $method->invoke(new GenerateJxOrderDocumentsCommand, $setting, Carbon::parse($targetDate), $cutoffTime);
    }
}
