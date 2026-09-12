<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Models\WmsOrderCandidate;
use App\Services\AutoOrder\JxOrderArrivalDateAdjustmentService;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class JxOrderArrivalDateAdjustmentServiceTest extends TestCase
{
    #[DataProvider('adjustmentRequirementCases')]
    public function test_requires_adjustment_for_past_or_non_deliverable_future_dates(
        string $expectedArrivalDate,
        ?array $deliveryDays,
        array $warehouseHolidays,
        bool $expected
    ): void {
        $service = new JxOrderArrivalDateAdjustmentService;

        $actual = $this->invokePrivate(
            $service,
            'requiresAdjustment',
            [
                $this->candidate($expectedArrivalDate),
                Carbon::parse('2026-07-18')->startOfDay(),
                $deliveryDays,
                $warehouseHolidays,
            ]
        );

        $this->assertSame($expected, $actual);
    }

    public function test_future_non_deliverable_date_is_moved_after_the_invalid_arrival_date(): void
    {
        $service = new JxOrderArrivalDateAdjustmentService;
        $candidate = $this->candidate('2026-07-19'); // Sunday
        $deliveryDays = [
            0 => false,
            1 => true,
            2 => true,
            3 => true,
            4 => true,
            5 => true,
            6 => true,
        ];

        $baseDay = $this->invokePrivate(
            $service,
            'nextArrivalSearchBaseDay',
            [$candidate, Carbon::parse('2026-07-18')->startOfDay()]
        );
        $nextArrivalDate = $this->invokePrivate(
            $service,
            'findNextArrivalDate',
            [$baseDay, 98, $deliveryDays, []]
        );

        $this->assertSame('2026-07-19', $baseDay->toDateString());
        $this->assertSame('2026-07-20', $nextArrivalDate->toDateString());
    }

    public function test_today_or_past_date_is_moved_after_the_execution_date(): void
    {
        $service = new JxOrderArrivalDateAdjustmentService;
        $candidate = $this->candidate('2026-07-18');

        $baseDay = $this->invokePrivate(
            $service,
            'nextArrivalSearchBaseDay',
            [$candidate, Carbon::parse('2026-07-18')->startOfDay()]
        );

        $this->assertSame('2026-07-18', $baseDay->toDateString());
    }

    public static function adjustmentRequirementCases(): array
    {
        $monToSat = [
            0 => false,
            1 => true,
            2 => true,
            3 => true,
            4 => true,
            5 => true,
            6 => true,
        ];

        return [
            'past date still requires adjustment' => [
                '2026-07-17',
                $monToSat,
                [],
                true,
            ],
            'future sunday is not deliverable' => [
                '2026-07-19',
                $monToSat,
                [],
                true,
            ],
            'future monday is deliverable' => [
                '2026-07-20',
                $monToSat,
                [],
                false,
            ],
            'future allowed weekday but warehouse holiday is not deliverable' => [
                '2026-07-20',
                $monToSat,
                [98 => ['2026-07-20' => true]],
                true,
            ],
            'future date without delivery setting keeps existing behavior' => [
                '2026-07-19',
                null,
                [],
                false,
            ],
            'future date with all delivery days disabled is not deliverable' => [
                '2026-07-19',
                [
                    0 => false,
                    1 => false,
                    2 => false,
                    3 => false,
                    4 => false,
                    5 => false,
                    6 => false,
                ],
                [],
                true,
            ],
        ];
    }

    private function candidate(string $expectedArrivalDate): WmsOrderCandidate
    {
        return new WmsOrderCandidate([
            'id' => 1,
            'contractor_id' => 1680,
            'warehouse_id' => 98,
            'expected_arrival_date' => $expectedArrivalDate,
        ]);
    }

    private function invokePrivate(object $object, string $method, array $args): mixed
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $args);
    }
}
