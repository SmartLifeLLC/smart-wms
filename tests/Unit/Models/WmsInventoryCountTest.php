<?php

namespace Tests\Unit\Models;

use App\Models\WmsInventoryCount;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WmsInventoryCountTest extends TestCase
{
    public function test_counting_with_current_stock_saved_at_uses_current_saved_display_status(): void
    {
        $count = new WmsInventoryCount([
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'current_stock_saved_at' => Carbon::parse('2026-06-09 12:00:00'),
        ]);

        $this->assertTrue($count->isCurrentStockSaved());
        $this->assertSame(WmsInventoryCount::STATUS_CURRENT_STOCK_SAVED, $count->display_status);
        $this->assertSame('現状保存', $count->status_label);
        $this->assertSame('success', $count->status_color);
        $this->assertFalse($count->canSaveCurrentStock());
        $this->assertTrue($count->canResumeCurrentStockSaved());
        $this->assertFalse($count->canRefreshSystemQuantities());
    }

    public function test_checked_with_current_stock_saved_at_keeps_checked_display_status(): void
    {
        $count = new WmsInventoryCount([
            'status' => WmsInventoryCount::STATUS_CHECKED,
            'current_stock_saved_at' => Carbon::parse('2026-06-09 12:00:00'),
        ]);

        $this->assertFalse($count->isCurrentStockSaved());
        $this->assertSame(WmsInventoryCount::STATUS_CHECKED, $count->display_status);
        $this->assertSame('差異確認済', $count->status_label);
        $this->assertFalse($count->canResumeCurrentStockSaved());
    }

    public function test_apply_display_status_filter_separates_counting_and_current_saved(): void
    {
        $countingQuery = WmsInventoryCount::query();
        WmsInventoryCount::applyDisplayStatusFilter($countingQuery, [WmsInventoryCount::STATUS_COUNTING]);

        $savedQuery = WmsInventoryCount::query();
        WmsInventoryCount::applyDisplayStatusFilter($savedQuery, [WmsInventoryCount::STATUS_CURRENT_STOCK_SAVED]);

        $this->assertStringContainsString('`status` = ?', $countingQuery->toSql());
        $this->assertStringContainsString('`current_stock_saved_at` is null', strtolower($countingQuery->toSql()));
        $this->assertSame([WmsInventoryCount::STATUS_COUNTING], $countingQuery->getBindings());

        $this->assertStringContainsString('`status` = ?', $savedQuery->toSql());
        $this->assertStringContainsString('`current_stock_saved_at` is not null', strtolower($savedQuery->toSql()));
        $this->assertSame([WmsInventoryCount::STATUS_COUNTING], $savedQuery->getBindings());
    }

    public function test_status_filter_has_no_default_values(): void
    {
        $this->assertSame([], WmsInventoryCount::defaultStatusFilterValues());
    }
}
