<?php

namespace Tests\Unit\Services;

use App\Services\StockTransferLotAllocationService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StockTransferLotAllocationServiceTest extends TestCase
{
    #[Test]
    public function negative_piece_allocations_use_piece_shortage_quantity(): void
    {
        $service = $this->service();

        $this->assertSame(192, $service->shortageQuantity(192, 1, 192));
    }

    #[Test]
    public function negative_case_allocations_convert_to_case_shortage_quantity(): void
    {
        $service = $this->service();

        $unitSize = $service->unitSize('CASE', (object) ['capacity_case' => 24], null);

        $this->assertSame(24, $unitSize);
        $this->assertSame(2, $service->shortageQuantity(48, $unitSize, 8));
    }

    #[Test]
    public function negative_case_allocations_fall_back_to_full_shortage_when_not_representable(): void
    {
        $service = $this->service();

        $this->assertSame(8, $service->shortageQuantity(47, 24, 8));
    }

    #[Test]
    public function expected_pieces_prefers_trade_item_total_piece_quantity_snapshot(): void
    {
        $service = $this->service();

        $this->assertSame(
            192,
            $service->expectedPiecesFor((object) ['total_piece_quantity' => 192], 8, 24)
        );
    }

    #[Test]
    public function negative_source_lot_is_treated_as_stock_transfer_shortage(): void
    {
        $service = $this->service();

        $this->assertTrue($service->isShortageSourceLot((object) ['source_lot_current_quantity' => -2]));
        $this->assertFalse($service->isShortageSourceLot((object) ['source_lot_current_quantity' => 0]));
        $this->assertFalse($service->isShortageSourceLot((object) ['source_lot_current_quantity' => 48]));
    }

    #[Test]
    public function unmanaged_item_negative_source_lot_is_allocatable(): void
    {
        $service = $this->service();

        $this->assertFalse($service->isShortageSourceLot(
            (object) ['source_lot_current_quantity' => -2],
            (object) ['is_managed_stock' => 0]
        ));
    }

    #[Test]
    public function allocation_must_point_to_the_transfer_source_stock(): void
    {
        $service = $this->service();

        $this->assertTrue($service->matchesTransferLine((object) [
            'lot_id' => 1,
            'real_stock_id' => 10,
            'warehouse_id' => 91,
            'item_id' => 143085,
        ], 91, 143085));

        $this->assertFalse($service->matchesTransferLine((object) [
            'lot_id' => null,
            'real_stock_id' => null,
            'warehouse_id' => 91,
            'item_id' => 143085,
        ], 91, 143085));

        $this->assertFalse($service->matchesTransferLine((object) [
            'lot_id' => 1,
            'real_stock_id' => 10,
            'warehouse_id' => 2,
            'item_id' => 143085,
        ], 91, 143085));
    }

    private function service(): object
    {
        return new class extends StockTransferLotAllocationService
        {
            public function shortageQuantity(int $pieces, int $unitSize, int $needQty): int
            {
                return $this->shortageQuantityFromPieces($pieces, $unitSize, $needQty);
            }

            public function unitSize(string $quantityType, object $tradeItem, ?object $item): int
            {
                return $this->unitSizeFor($quantityType, $tradeItem, $item);
            }

            public function expectedPiecesFor(object $tradeItem, int $needQty, int $unitSize): int
            {
                return $this->expectedPieces($tradeItem, $needQty, $unitSize);
            }

            public function isShortageSourceLot(object $allocation, ?object $item = null): bool
            {
                return $this->sourceLotRepresentsShortage($allocation, $item);
            }

            public function matchesTransferLine(object $allocation, int $warehouseId, int $itemId): bool
            {
                return $this->allocationMatchesTradeItem($allocation, $warehouseId, $itemId);
            }
        };
    }
}
