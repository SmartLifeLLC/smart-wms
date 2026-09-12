<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Services\AutoOrder\Generators\HanaOrderJXFileGenerator;
use App\Services\AutoOrder\LegacyEosSlipNumberService;
use Illuminate\Support\Collection;
use Tests\TestCase;

class HanaOrderJXFileGeneratorLegacySlipNumberTest extends TestCase
{
    public function test_b_record_slip_number_is_allocated_with_legacy_service(): void
    {
        $service = new class extends LegacyEosSlipNumberService
        {
            public function allocateForWarehouse(mixed $warehouse, \Carbon\CarbonInterface|string|null $orderDate = null): array
            {
                return [
                    'slip_number' => '91461018629',
                    'store_code' => '91',
                    'year_code' => 46,
                    'sequence_no' => 18629,
                ];
            }
        };

        $generator = new HanaOrderJXFileGenerator($service);
        $method = new \ReflectionMethod($generator, 'resolveBRecordSlipNumber');
        $method->setAccessible(true);
        $usedSlipNumbers = [];

        $result = $method->invokeArgs($generator, [
            new Collection([$this->candidate()]),
            &$usedSlipNumbers,
        ]);

        $this->assertSame('91461018629', $result['slip_number']);
        $this->assertSame(['91461018629'], $usedSlipNumbers);
    }

    private function candidate(): object
    {
        return (object) [
            'id' => null,
            'warehouse' => (object) [
                'id' => 91,
                'code' => 91,
            ],
        ];
    }
}
