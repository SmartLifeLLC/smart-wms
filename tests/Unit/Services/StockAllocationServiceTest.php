<?php

namespace Tests\Unit\Services;

use App\Services\StockAllocationService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StockAllocationServiceTest extends TestCase
{
    #[Test]
    public function case_allocation_units_are_capped_by_available_pieces(): void
    {
        $service = new class extends StockAllocationService
        {
            public function unitSize(string $quantityType, object $item): int
            {
                return $this->unitSizeFor($quantityType, $item);
            }

            public function unitsFromPieces(int $availablePieces, int $unitSize): int
            {
                return $this->allocatableUnitsFromPieces($availablePieces, $unitSize);
            }
        };

        $unitSize = $service->unitSize('CASE', (object) ['capacity_case' => 24]);

        $this->assertSame(24, $unitSize);
        $this->assertSame(4, $service->unitsFromPieces(96, $unitSize));
        $this->assertSame(0, $service->unitsFromPieces(23, $unitSize));
    }

    #[Test]
    public function piece_allocation_uses_piece_count_as_units(): void
    {
        $service = new class extends StockAllocationService
        {
            public function unitSize(string $quantityType, object $item): int
            {
                return $this->unitSizeFor($quantityType, $item);
            }

            public function unitsFromPieces(int $availablePieces, int $unitSize): int
            {
                return $this->allocatableUnitsFromPieces($availablePieces, $unitSize);
            }
        };

        $unitSize = $service->unitSize('PIECE', (object) ['capacity_case' => 24]);

        $this->assertSame(1, $unitSize);
        $this->assertSame(96, $service->unitsFromPieces(96, $unitSize));
    }

    #[Test]
    public function lot_earning_quantity_is_already_piece_quantity(): void
    {
        $service = new class extends StockAllocationService
        {
            public function lotEarningPiecesSql(): string
            {
                return $this->lotEarningPiecesExpression();
            }
        };

        $sql = $service->lotEarningPiecesSql();

        $this->assertStringContainsString('rsle.quantity', $sql);
        $this->assertStringNotContainsString('quantity_type', $sql);
        $this->assertStringNotContainsString('capacity_case', $sql);
        $this->assertStringNotContainsString('capacity_carton', $sql);
    }
}
