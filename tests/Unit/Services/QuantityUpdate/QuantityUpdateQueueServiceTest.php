<?php

namespace Tests\Unit\Services\QuantityUpdate;

use App\Models\QuantityUpdateQueue;
use App\Models\Sakemaru\Trade;
use App\Models\WmsPickingItemResult;
use App\Models\WmsShortage;
use App\Services\QuantityUpdate\QuantityUpdateQueueService;
use Tests\TestCase;

class QuantityUpdateQueueServiceTest extends TestCase
{
    public function test_shortage_approval_without_source_pick_result_does_not_create_quantity_update_queue(): void
    {
        $shortage = new WmsShortage([
            'trade_id' => 16430,
            'trade_item_id' => 173922,
            'order_qty' => 4,
            'planned_qty' => 0,
            'picked_qty' => 0,
            'shortage_qty' => 4,
            'qty_type_at_order' => 'PIECE',
        ]);
        $shortage->id = 1323;

        $queue = (new QuantityUpdateQueueService)->createQueueForShortageApproval($shortage);

        $this->assertNull($queue);
    }

    public function test_picking_quantity_correction_does_not_create_queue_when_trade_category_mismatches(): void
    {
        $pickResult = new WmsPickingItemResult([
            'source_type' => WmsPickingItemResult::SOURCE_TYPE_EARNING,
            'trade_id' => 4571,
            'trade_item_id' => 50015,
            'picked_qty' => 0,
            'picked_qty_type' => 'CASE',
            'ordered_qty_type' => 'CASE',
        ]);
        $pickResult->id = 12485;
        $pickResult->setRelation('trade', new Trade([
            'client_id' => 6,
            'trade_category' => QuantityUpdateQueue::TRADE_CATEGORY_STOCK_TRANSFER,
        ]));

        $queue = (new QuantityUpdateQueueService)->createQueueForPickingQuantityCorrection($pickResult);

        $this->assertNull($queue);
    }

    public function test_shortage_quantity_queue_rejects_trade_category_mismatch(): void
    {
        $shortage = new WmsShortage([
            'source_pick_result_id' => 12485,
            'trade_id' => 4571,
            'trade_item_id' => 50015,
        ]);
        $shortage->id = 597;
        $shortage->setRelation('trade', new Trade([
            'trade_category' => QuantityUpdateQueue::TRADE_CATEGORY_EARNING,
        ]));
        $shortage->setRelation('sourcePickResult', new WmsPickingItemResult([
            'source_type' => WmsPickingItemResult::SOURCE_TYPE_STOCK_TRANSFER,
        ]));

        $method = new \ReflectionMethod(QuantityUpdateQueueService::class, 'assertTradeCategoryMatches');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $method->invoke(
            new QuantityUpdateQueueService,
            $shortage,
            QuantityUpdateQueue::TRADE_CATEGORY_STOCK_TRANSFER,
            '12485',
            'test'
        );
    }
}
