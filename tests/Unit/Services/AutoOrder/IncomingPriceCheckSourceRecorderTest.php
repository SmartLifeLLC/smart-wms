<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Models\Sakemaru\Item;
use App\Models\WmsIncomingReceivedDetail;
use App\Models\WmsOrderIncomingSchedule;
use App\Services\AutoOrder\IncomingPriceCheckSourceRecorder;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class IncomingPriceCheckSourceRecorderTest extends TestCase
{
    public function test_p_box_price_resolves_received_amount_difference(): void
    {
        $payload = $this->comparisonPayload(
            new WmsOrderIncomingSchedule([
                'unit_price' => 90.5,
                'case_price' => 2172,
                'price_type' => 'PIECE',
            ]),
            new Item([
                'capacity_case' => 24,
                'p_box_price' => 200,
            ]),
            new WmsIncomingReceivedDetail([
                'd_pack_quantity' => 24,
                'd_case_quantity' => 0,
                'd_piece_quantity' => 24,
                'total_quantity' => 24,
            ]),
            98.83,
            2372.0,
        );

        $this->assertEqualsWithDelta(8.33, $payload['price_diff'], 0.0001);
        $this->assertEqualsWithDelta(98.8333, $payload['quantity_adjusted_unit_price'], 0.0001);
        $this->assertEqualsWithDelta(8.3333, $payload['quantity_adjusted_price_diff'], 0.0001);
        $this->assertSame(200.0, $payload['purchase_adjustment_amount']);
        $this->assertSame(1.0, $payload['purchase_adjustment_case_equivalent_quantity']);
        $this->assertSame(90.5, $payload['adjustment_adjusted_unit_price']);
        $this->assertSame(0.0, $payload['adjustment_adjusted_price_diff']);
        $this->assertFalse($payload['has_mismatch']);
    }

    public function test_quantity_adjustment_includes_case_and_piece_quantities(): void
    {
        $payload = $this->comparisonPayload(
            new WmsOrderIncomingSchedule([
                'unit_price' => 90.5,
                'case_price' => 2172,
                'price_type' => 'PIECE',
            ]),
            new Item([
                'capacity_case' => 24,
                'p_box_price' => 0,
            ]),
            new WmsIncomingReceivedDetail([
                'd_pack_quantity' => 24,
                'd_case_quantity' => 1,
                'd_piece_quantity' => 2,
                'total_quantity' => 26,
            ]),
            2353.0,
            2353.0,
        );

        $this->assertSame(26.0, $payload['quantity_adjusted_piece_quantity']);
        $this->assertSame(90.5, $payload['quantity_adjusted_unit_price']);
        $this->assertSame(0.0, $payload['quantity_adjusted_price_diff']);
        $this->assertFalse($payload['has_mismatch']);
    }

    private function comparisonPayload(
        WmsOrderIncomingSchedule $schedule,
        Item $item,
        WmsIncomingReceivedDetail $detail,
        ?float $receivedPrice,
        ?float $receivedAmount,
    ): array {
        $schedule->setRelation('item', $item);

        $method = new ReflectionMethod(IncomingPriceCheckSourceRecorder::class, 'comparisonPayload');
        $method->setAccessible(true);

        return $method->invoke(
            new IncomingPriceCheckSourceRecorder,
            $schedule,
            $receivedPrice,
            $receivedAmount,
            $detail,
            null,
        );
    }
}
