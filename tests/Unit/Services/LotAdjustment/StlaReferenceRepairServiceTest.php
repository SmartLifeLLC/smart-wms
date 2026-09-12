<?php

namespace Tests\Unit\Services\LotAdjustment;

use App\Services\LotAdjustment\StlaReferenceRepairService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class StlaReferenceRepairServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['sakemaru'];

    #[Test]
    public function same_shelf_positive_lots_are_merged_once_and_multiple_stla_rows_are_repointed(): void
    {
        $realStockId = $this->createRealStock();
        $oldLotId = $this->createLot($realStockId, 0, 0, 'DEPLETED');
        $sourceLotId = $this->createLot($realStockId, 4, 2, 'ACTIVE', 11);
        $targetLotId = $this->createLot($realStockId, 6, 0, 'ACTIVE', 11);
        $rsleId = $this->createReservedRsle($sourceLotId, 2);

        $stockTransferId = $this->createStockTransfer();
        $firstStlaId = $this->createStla($stockTransferId, 990001, $oldLotId, 3);
        $secondStlaId = $this->createStla($stockTransferId, 990002, $oldLotId, 5);

        $service = new StlaReferenceRepairService;
        $candidates = $service->detectCandidates(91);

        $targets = $candidates
            ->whereIn('stla_id', [$firstStlaId, $secondStlaId])
            ->values();

        $this->assertCount(2, $targets);
        $this->assertTrue((bool) $targets[0]->eligible);
        $this->assertTrue((bool) $targets[1]->eligible);
        $this->assertTrue((bool) $targets[0]->requires_positive_lot_merge);

        $firstChange = $service->applyRepoint($targets[0]);
        $secondChange = $service->applyRepoint($targets[1]);

        $this->assertSame('REPOINT', $firstChange['type']);
        $this->assertSame('REPOINT', $secondChange['type']);
        $this->assertSame($targetLotId, $firstChange['new_lot_id']);
        $this->assertSame($targetLotId, $secondChange['new_lot_id']);
        $this->assertFalse($firstChange['merge_already_applied']);
        $this->assertTrue($secondChange['merge_already_applied']);

        $targetLot = DB::connection('sakemaru')->table('real_stock_lots')->where('id', $targetLotId)->first();
        $sourceLot = DB::connection('sakemaru')->table('real_stock_lots')->where('id', $sourceLotId)->first();

        $this->assertSame(10, (int) $targetLot->current_quantity);
        $this->assertSame(2, (int) $targetLot->reserved_quantity);
        $this->assertSame(0, (int) $sourceLot->current_quantity);
        $this->assertSame(0, (int) $sourceLot->reserved_quantity);
        $this->assertSame('DEPLETED', $sourceLot->status);

        $this->assertSame(
            $targetLotId,
            (int) DB::connection('sakemaru')
                ->table('real_stock_lot_earnings')
                ->where('id', $rsleId)
                ->value('real_stock_lot_id')
        );
        $this->assertSame(
            [$targetLotId, $targetLotId],
            DB::connection('sakemaru')
                ->table('stock_transfer_lot_allocations')
                ->whereIn('id', [$firstStlaId, $secondStlaId])
                ->orderBy('id')
                ->pluck('from_real_stock_lot_id')
                ->map(fn ($id) => (int) $id)
                ->all()
        );
    }

    #[Test]
    public function merge_stops_when_source_lot_reserved_quantity_does_not_match_reserved_rsle_sum(): void
    {
        $realStockId = $this->createRealStock();
        $oldLotId = $this->createLot($realStockId, 0, 0, 'DEPLETED');
        $sourceLotId = $this->createLot($realStockId, 4, 2, 'ACTIVE', 11);
        $targetLotId = $this->createLot($realStockId, 6, 0, 'ACTIVE', 11);
        $this->createReservedRsle($sourceLotId, 1);

        $stockTransferId = $this->createStockTransfer();
        $stlaId = $this->createStla($stockTransferId, 990003, $oldLotId, 3);

        $candidate = (new StlaReferenceRepairService)
            ->detectCandidates(91)
            ->firstWhere('stla_id', $stlaId);

        $this->assertNotNull($candidate);
        $this->assertTrue((bool) $candidate->eligible);

        try {
            (new StlaReferenceRepairService)->applyRepoint($candidate);
            $this->fail('Expected reserved mismatch to stop the merge.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('reservedとRSLE RESERVED合計が一致しません', $e->getMessage());
        }

        $sourceLot = DB::connection('sakemaru')->table('real_stock_lots')->where('id', $sourceLotId)->first();
        $targetLot = DB::connection('sakemaru')->table('real_stock_lots')->where('id', $targetLotId)->first();

        $this->assertSame(4, (int) $sourceLot->current_quantity);
        $this->assertSame(2, (int) $sourceLot->reserved_quantity);
        $this->assertSame('ACTIVE', $sourceLot->status);
        $this->assertSame(6, (int) $targetLot->current_quantity);
        $this->assertSame(0, (int) $targetLot->reserved_quantity);
        $this->assertSame(
            $oldLotId,
            (int) DB::connection('sakemaru')
                ->table('stock_transfer_lot_allocations')
                ->where('id', $stlaId)
                ->value('from_real_stock_lot_id')
        );
    }

    private function createRealStock(): int
    {
        return (int) DB::connection('sakemaru')
            ->table('real_stocks')
            ->insertGetId([
                'client_id' => 1,
                'warehouse_id' => 91,
                'stock_allocation_id' => 0,
                'item_id' => random_int(900000, 999999),
                'current_quantity' => 10,
                'reserved_quantity' => 2,
                'picking_quantity' => 0,
                'order_rank' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function createLot(
        int $realStockId,
        int $currentQuantity,
        int $reservedQuantity,
        string $status,
        ?int $floorId = null,
        ?int $locationId = null
    ): int {
        return (int) DB::connection('sakemaru')
            ->table('real_stock_lots')
            ->insertGetId([
                'real_stock_id' => $realStockId,
                'floor_id' => $floorId,
                'location_id' => $locationId,
                'initial_quantity' => $currentQuantity,
                'current_quantity' => $currentQuantity,
                'reserved_quantity' => $reservedQuantity,
                'status' => $status,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function createReservedRsle(int $lotId, int $quantity): int
    {
        return (int) DB::connection('sakemaru')
            ->table('real_stock_lot_earnings')
            ->insertGetId([
                'real_stock_lot_id' => $lotId,
                'earning_id' => random_int(900000, 999999),
                'trade_item_id' => random_int(900000, 999999),
                'quantity' => $quantity,
                'status' => 'RESERVED',
                'reserved_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function createStockTransfer(): int
    {
        return (int) DB::connection('sakemaru')
            ->table('stock_transfers')
            ->insertGetId([
                'trade_id' => random_int(900000, 999999),
                'client_id' => 1,
                'from_warehouse_id' => 91,
                'to_warehouse_id' => 1,
                'picking_status' => 'BEFORE',
                'is_delivered' => 0,
                'is_confirmed' => 0,
                'is_active' => 1,
                'delivered_date' => '2026-07-01',
                'picking_date' => '2026-07-01',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function createStla(int $stockTransferId, int $tradeItemId, int $fromLotId, int $quantity): int
    {
        return (int) DB::connection('sakemaru')
            ->table('stock_transfer_lot_allocations')
            ->insertGetId([
                'stock_transfer_id' => $stockTransferId,
                'trade_item_id' => $tradeItemId,
                'from_real_stock_lot_id' => $fromLotId,
                'quantity' => $quantity,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
