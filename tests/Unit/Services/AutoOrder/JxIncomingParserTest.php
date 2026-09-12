<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Services\AutoOrder\IncomingParsers\JxIncomingParser;
use App\Services\AutoOrder\IncomingReceivedQuantityNormalizer;
use Tests\TestCase;

class JxIncomingParserTest extends TestCase
{
    public function test_six_pack_ordering_code_quantity_is_converted_to_piece_quantity(): void
    {
        $this->bindQuantityNormalizer([
            '4901411004754' => 6,
        ]);
        $parser = new JxIncomingParser;

        $quantity = $this->invokeCalculateTotalQuantity($parser, 1, 0, 4, '4901411004754');

        $this->assertSame(24, $quantity);
    }

    public function test_regular_jan_quantity_uses_pack_quantity_as_piece_count(): void
    {
        $this->bindQuantityNormalizer([
            '4901411004754' => null,
        ]);
        $parser = new JxIncomingParser;

        $quantity = $this->invokeCalculateTotalQuantity($parser, 1, 0, 24, '4901411004754');

        $this->assertSame(24, $quantity);
    }

    public function test_six_pack_piece_column_is_also_converted_to_piece_quantity(): void
    {
        $this->bindQuantityNormalizer([
            '4901411004754' => 6,
        ]);
        $parser = new JxIncomingParser;

        $quantity = $this->invokeCalculateTotalQuantity($parser, 0, 8, 4, '4901411004754');

        $this->assertSame(48, $quantity);
    }

    private function invokeCalculateTotalQuantity(
        JxIncomingParser $parser,
        int $caseQty,
        int $pieceQty,
        int $packQty,
        string $janCode,
    ): int {
        $reflection = new \ReflectionClass($parser);
        $method = $reflection->getMethod('calculateTotalQuantity');
        $method->setAccessible(true);

        return $method->invoke($parser, $caseQty, $pieceQty, $packQty, $janCode);
    }

    private function bindQuantityNormalizer(array $cache): void
    {
        $normalizer = new IncomingReceivedQuantityNormalizer;
        $this->setPrivateProperty($normalizer, 'orderingUnitQuantityCache', $cache);

        $this->app->instance(IncomingReceivedQuantityNormalizer::class, $normalizer);
    }

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionClass($object);
        $property = $reflection->getProperty($property);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }
}
