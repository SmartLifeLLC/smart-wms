<?php

namespace Tests\Unit\Console\AutoOrder;

use App\Console\Commands\AutoOrder\TransmitJxOrderDocumentsCommand;
use Carbon\Carbon;
use ReflectionMethod;
use Tests\TestCase;

class TransmitJxOrderDocumentsCommandTest extends TestCase
{
    public function test_target_order_date_range_includes_previous_day(): void
    {
        [$from, $until] = $this->targetOrderDateRange('2026-07-18');

        $this->assertSame('2026-07-17', $from->toDateString());
        $this->assertSame('2026-07-18', $until->toDateString());
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function targetOrderDateRange(string $targetDate): array
    {
        $method = new ReflectionMethod(TransmitJxOrderDocumentsCommand::class, 'targetOrderDateRange');
        $method->setAccessible(true);

        return $method->invoke(new TransmitJxOrderDocumentsCommand, Carbon::parse($targetDate));
    }
}
