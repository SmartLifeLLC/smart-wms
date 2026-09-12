<?php

namespace Tests\Unit\Services;

use App\Services\Export\ExportService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class ExportServiceTest extends TestCase
{
    #[Test]
    public function it_resolves_has_many_relation_column_values_for_export(): void
    {
        $record = (object) [
            'activeLots' => collect([
                (object) ['expiration_date' => CarbonImmutable::parse('2026-09-01')],
                (object) ['expiration_date' => CarbonImmutable::parse('2026-10-05')],
            ]),
        ];

        $this->assertSame(
            "2026-09-01 00:00:00\n2026-10-05 00:00:00",
            $this->resolveColumnValue($record, 'activeLots.expiration_date')
        );
    }

    #[Test]
    public function it_preserves_scalar_relation_column_resolution(): void
    {
        $record = (object) [
            'warehouse' => (object) ['name' => 'Tokyo Warehouse'],
        ];

        $this->assertSame('Tokyo Warehouse', $this->resolveColumnValue($record, 'warehouse.name'));
    }

    #[Test]
    public function it_keeps_zero_values_when_joining_collection_values(): void
    {
        $record = (object) [
            'activeLots' => collect([
                (object) ['reserved_quantity' => 0],
                (object) ['reserved_quantity' => 5],
            ]),
        ];

        $this->assertSame("0\n5", $this->resolveColumnValue($record, 'activeLots.reserved_quantity'));
    }

    private function resolveColumnValue(mixed $record, string $dbColumn): mixed
    {
        $method = new ReflectionMethod(ExportService::class, 'resolveColumnValue');
        $method->setAccessible(true);

        return $method->invoke(new ExportService, $record, $dbColumn);
    }
}
