<?php

namespace App\Console\Commands;

use App\Models\Sakemaru\Earning;
use App\Models\Wave;
use App\Models\WaveSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateWavesCommand extends Command
{
    protected $signature = 'wms:generate-waves {--date= : Shipping date (YYYY-MM-DD), defaults to today} {--reset : Reset all wave-related data before generating new waves}';

    protected $description = 'Generate WMS waves based on wms_wave_settings for eligible earnings';

    public function handle()
    {
        $shippingDate = $this->option('date') ?? now()->format('Y-m-d');
        $shouldReset = $this->option('reset');

        $this->info("Generating waves for shipping date: {$shippingDate}");

        // Reset wave-related data if --reset flag is provided
        if ($shouldReset) {
            $this->warn("⚠️  Reset flag detected. Cleaning up all wave-related data...");
            $this->resetWaveData($shippingDate);
            $this->info("✓ Wave data reset completed.");
            $this->newLine();
        }

        // Get current time minus 10 minutes (time limit for earning entry)
        $currentTime = now();
        $timeLimitForEntry = $currentTime->copy()->addMinutes(10);

        $this->line("Current time: {$currentTime->format('H:i:s')}");
        $this->line("Processing waves with picking start time before: {$timeLimitForEntry->format('H:i:s')}");

        // Get wave settings where picking_start_time is within the time limit
        // Only process waves where picking starts within 10 minutes from now
        $waveSettings = WaveSetting::whereTime('picking_start_time', '<=', $timeLimitForEntry->format('H:i:s'))
            ->get();

        if ($waveSettings->isEmpty()) {
            $this->warn('No wave settings found with picking start time before ' . $timeLimitForEntry->format('H:i:s') . '. Please create wave settings first.');
            return 1;
        }

        $this->info("Found {$waveSettings->count()} wave setting(s) eligible for generation");

        $createdCount = 0;
        $skippedCount = 0;

        foreach ($waveSettings as $setting) {
            // Check if wave already exists for this setting and date
            $existingWave = Wave::where('wms_wave_setting_id', $setting->id)
                ->where('shipping_date', $shippingDate)
                ->first();
            if ($existingWave) {
                $skippedCount++;
                continue;
            }

            // Check if there are eligible earnings for this wave
            $earningsCount = Earning::where('delivered_date', $shippingDate)
                ->where('is_delivered', 0)
                ->where('picking_status', 'BEFORE')
                ->where('warehouse_id', $setting->warehouse_id)
                ->where('delivery_course_id', $setting->delivery_course_id)
                ->count();

            if ($earningsCount === 0) {
                $this->line("No eligible earnings found for warehouse {$setting->warehouse_id}, course {$setting->delivery_course_id}. Skipping.");
                $skippedCount++;
                continue;
            }

            // Create wave within transaction
            DB::transaction(function () use ($setting, $shippingDate, $earningsCount, &$createdCount) {
                // Get warehouse and course codes for wave_no generation
                $warehouse = DB::connection('sakemaru')
                    ->table('warehouses')
                    ->where('id', $setting->warehouse_id)
                    ->first();

                $course = DB::connection('sakemaru')
                    ->table('delivery_courses')
                    ->where('id', $setting->delivery_course_id)
                    ->first();

                // Create wave
                $wave = Wave::create([
                    'wms_wave_setting_id' => $setting->id,
                    'wave_no' => uniqid('TEMP_'), // Temporary, will update after getting ID
                    'shipping_date' => $shippingDate,
                    'status' => 'PENDING',
                ]);

                // Update wave_no with actual ID
                $waveNo = Wave::generateWaveNo(
                    $warehouse->code ?? 0,
                    $course->code ?? 0,
                    $shippingDate,
                    $wave->id
                );

                $wave->update(['wave_no' => $waveNo]);

                // Get earnings for this wave
                $earnings = Earning::where('delivered_date', $shippingDate)
                    ->where('is_delivered', 0)
                    ->where('picking_status', 'BEFORE')
                    ->where('warehouse_id', $setting->warehouse_id)
                    ->where('delivery_course_id', $setting->delivery_course_id)
                    ->get();

                // Create picking tasks for each earning
                foreach ($earnings as $earning) {
                    // Create picking task
                    $pickingTaskId = DB::connection('sakemaru')->table('wms_picking_tasks')->insertGetId([
                        'wave_id' => $wave->id,
                        'warehouse_id' => $setting->warehouse_id,
                        'earning_id' => $earning->id,
                        'trade_id' => $earning->trade_id,
                        'status' => 'PENDING',
                        'task_type' => 'WAVE',
                        'picker_id' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    // Get trade items for this earning
                    $tradeItems = DB::connection('sakemaru')
                        ->table('trade_items')
                        ->where('trade_id', $earning->trade_id)
                        ->get();

                    // Create picking item results and reserve stock for each trade item
                    foreach ($tradeItems as $tradeItem) {
                        // Reserve stock for this trade item (FEFO logic) and get allocated quantity
                        $allocatedQty = $this->reserveStockForTradeItem($wave->id, $earning, $tradeItem);

                        // Create picking item result
                        DB::connection('sakemaru')->table('wms_picking_item_results')->insert([
                            'picking_task_id' => $pickingTaskId,
                            'trade_item_id' => $tradeItem->id,
                            'item_id' => $tradeItem->item_id,
                            'real_stock_id' => null, // Will be set during actual picking
                            'ordered_qty' => $tradeItem->quantity, // Original order quantity
                            'ordered_qty_type' => $tradeItem->quantity_type ?? 'PIECE', // From trade_items.quantity_type
                            'planned_qty' => $allocatedQty, // Allocated quantity from reservations
                            'planned_qty_type' => $tradeItem->quantity_type ?? 'PIECE', // Same as ordered (for now)
                            'picked_qty' => 0, // Will be set by picker during picking
                            'picked_qty_type' => $tradeItem->quantity_type ?? 'PIECE', // Will be set by picker
                            'shortage_qty' => 0, // Will be set by picker during picking
                            'status' => 'PICKING',
                            'picker_id' => null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    // Update earning picking_status to PICKING
                    DB::connection('sakemaru')
                        ->table('earnings')
                        ->where('id', $earning->id)
                        ->update([
                            'picking_status' => 'PICKING',
                            'updated_at' => now(),
                        ]);
                }

                $this->info("Created wave {$waveNo} with {$earningsCount} earnings and picking tasks");
                $createdCount++;
            });
        }

        $this->info("Wave generation completed. Created: {$createdCount}, Skipped: {$skippedCount}");

        return 0;
    }

    /**
     * Reserve stock for a trade item using FEFO logic
     *
     * @return int The total allocated quantity
     */
    protected function reserveStockForTradeItem($waveId, $earning, $tradeItem): int
    {
        $needQty = $tradeItem->quantity;
        $warehouseId = $earning->warehouse_id;
        $itemId = $tradeItem->item_id;
        $totalAllocated = 0;

        // Get available stocks ordered by FEFO (expiry date first) and location
        // Join with wms_real_stocks to get reserved quantities
        $stocks = DB::connection('sakemaru')
            ->table('real_stocks as rs')
            ->leftJoin('wms_real_stocks as wrs', 'rs.id', '=', 'wrs.real_stock_id')
            ->where('rs.warehouse_id', $warehouseId)
            ->where('rs.item_id', $itemId)
            ->whereRaw('rs.available_quantity > COALESCE(wrs.wms_reserved_qty, 0) + COALESCE(wrs.wms_picking_qty, 0)')
            ->select(
                'rs.id as real_stock_id',
                'rs.expiration_date',
                'rs.available_quantity',
                'rs.purchase_id',
                'rs.price',
                DB::raw('COALESCE(wrs.wms_reserved_qty, 0) as reserved_qty'),
                DB::raw('COALESCE(wrs.wms_picking_qty, 0) as picking_qty'),
                DB::raw('rs.available_quantity - COALESCE(wrs.wms_reserved_qty, 0) - COALESCE(wrs.wms_picking_qty, 0) as available_for_wms')
            )
            ->orderByRaw('rs.expiration_date IS NULL') // NULL expiry last
            ->orderBy('rs.expiration_date', 'asc') // FEFO: earliest expiry first
            ->orderBy('rs.id', 'asc') // FIFO: oldest stock first
            ->lockForUpdate() // Pessimistic lock
            ->get();

        // Allocate stock from available inventory
        foreach ($stocks as $stock) {
            if ($needQty <= 0) break;

            $allocQty = min($needQty, $stock->available_for_wms);

            if ($allocQty > 0) {
                // Create reservation record with allocated quantity
                DB::connection('sakemaru')->table('wms_reservations')->insert([
                    'warehouse_id' => $warehouseId,
                    'location_id' => null,
                    'real_stock_id' => $stock->real_stock_id,
                    'item_id' => $itemId,
                    'expiry_date' => $stock->expiration_date,
                    'received_at' => null,
                    'purchase_id' => $stock->purchase_id,
                    'unit_cost' => $stock->price,
                    'qty_each' => $allocQty,
                    'qty_type' => $tradeItem->quantity_type ?? 'PIECE',
                    'shortage_qty' => 0,
                    'source_type' => 'EARNING',
                    'source_id' => $earning->id,
                    'source_line_id' => $tradeItem->id,
                    'wave_id' => $waveId,
                    'status' => 'RESERVED',
                    'created_by' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Update or create wms_real_stocks record to track reserved quantity
                $wmsRealStock = DB::connection('sakemaru')
                    ->table('wms_real_stocks')
                    ->where('real_stock_id', $stock->real_stock_id)
                    ->first();

                if ($wmsRealStock) {
                    // Update existing record
                    DB::connection('sakemaru')
                        ->table('wms_real_stocks')
                        ->where('real_stock_id', $stock->real_stock_id)
                        ->update([
                            'wms_reserved_qty' => DB::raw('wms_reserved_qty + ' . $allocQty),
                            'updated_at' => now(),
                        ]);
                } else {
                    // Create new record
                    DB::connection('sakemaru')->table('wms_real_stocks')->insert([
                        'real_stock_id' => $stock->real_stock_id,
                        'wms_reserved_qty' => $allocQty,
                        'wms_picking_qty' => 0,
                        'wms_lock_version' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $totalAllocated += $allocQty;
                $needQty -= $allocQty;
            }
        }

        // Handle shortage or partial allocation
        if ($needQty > 0) {
            // Determine status based on whether any stock was allocated
            $status = $totalAllocated > 0 ? 'PARTIAL' : 'SHORTAGE';

            // Create a reservation record for the shortage
            DB::connection('sakemaru')->table('wms_reservations')->insert([
                'warehouse_id' => $warehouseId,
                'location_id' => null,
                'real_stock_id' => null, // No real stock for shortage
                'item_id' => $itemId,
                'expiry_date' => null,
                'received_at' => null,
                'purchase_id' => null,
                'unit_cost' => null,
                'qty_each' => 0, // No quantity allocated
                'qty_type' => $tradeItem->quantity_type ?? 'PIECE',
                'shortage_qty' => $needQty, // Record the shortage amount
                'source_type' => 'EARNING',
                'source_id' => $earning->id,
                'source_line_id' => $tradeItem->id,
                'wave_id' => $waveId,
                'status' => $status,
                'created_by' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->warn("  {$status} for item {$itemId}: {$needQty} units could not be reserved (allocated: {$totalAllocated})");
        }

        return $totalAllocated;
    }

    /**
     * Reset all wave-related data for the specified shipping date
     */
    protected function resetWaveData($shippingDate)
    {
        DB::transaction(function () use ($shippingDate) {
            // Get waves for this shipping date
            $waves = Wave::where('shipping_date', $shippingDate)->get();

            if ($waves->isEmpty()) {
                $this->info('  No waves found for this shipping date.');
                return;
            }

            $waveIds = $waves->pluck('id')->toArray();
            $this->info("  Found " . count($waveIds) . " wave(s) to reset.");

            // 1. Get earnings that were part of these waves (via picking_tasks)
            $earningIds = DB::connection('sakemaru')
                ->table('wms_picking_tasks')
                ->whereIn('wave_id', $waveIds)
                ->pluck('earning_id')
                ->unique()
                ->toArray();

            if (!empty($earningIds)) {
                // Reset earning status back to BEFORE
                $updatedEarnings = DB::connection('sakemaru')
                    ->table('earnings')
                    ->whereIn('id', $earningIds)
                    ->update([
                        'picking_status' => 'BEFORE',
                        'updated_at' => now(),
                    ]);
                $this->info("  ✓ Reset {$updatedEarnings} earnings to BEFORE status");
            }

            // 2. Delete picking item results
            $deletedItemResults = DB::connection('sakemaru')
                ->table('wms_picking_item_results')
                ->whereIn('picking_task_id', function ($query) use ($waveIds) {
                    $query->select('id')
                        ->from('wms_picking_tasks')
                        ->whereIn('wave_id', $waveIds);
                })
                ->delete();
            $this->info("  ✓ Deleted {$deletedItemResults} picking item results");

            // 3. Delete picking tasks
            $deletedTasks = DB::connection('sakemaru')
                ->table('wms_picking_tasks')
                ->whereIn('wave_id', $waveIds)
                ->delete();
            $this->info("  ✓ Deleted {$deletedTasks} picking tasks");

            // 4. Delete reservations and restore wms_real_stocks
            $reservations = DB::connection('sakemaru')
                ->table('wms_reservations')
                ->whereIn('wave_id', $waveIds)
                ->get();

            foreach ($reservations as $reservation) {
                if ($reservation->real_stock_id && $reservation->qty_each > 0) {
                    // Decrease reserved quantity in wms_real_stocks
                    DB::connection('sakemaru')
                        ->table('wms_real_stocks')
                        ->where('real_stock_id', $reservation->real_stock_id)
                        ->update([
                            'wms_reserved_qty' => DB::raw('GREATEST(wms_reserved_qty - ' . $reservation->qty_each . ', 0)'),
                            'updated_at' => now(),
                        ]);
                }
            }

            $deletedReservations = DB::connection('sakemaru')
                ->table('wms_reservations')
                ->whereIn('wave_id', $waveIds)
                ->delete();
            $this->info("  ✓ Deleted {$deletedReservations} reservations and restored wms_real_stocks");

            // 5. Delete waves
            $deletedWaves = Wave::whereIn('id', $waveIds)->delete();
            $this->info("  ✓ Deleted {$deletedWaves} waves");

            // 6. Clean up orphaned wms_real_stocks records (where reserved_qty = 0 and picking_qty = 0)
            $cleanedStocks = DB::connection('sakemaru')
                ->table('wms_real_stocks')
                ->where('wms_reserved_qty', 0)
                ->where('wms_picking_qty', 0)
                ->delete();

            if ($cleanedStocks > 0) {
                $this->info("  ✓ Cleaned up {$cleanedStocks} orphaned wms_real_stocks records");
            }

            // 7. Delete idempotency keys for wave reservations
            $deletedKeys = DB::connection('sakemaru')
                ->table('wms_idempotency_keys')
                ->where('scope', 'wave_reservation')
                ->delete();

            if ($deletedKeys > 0) {
                $this->info("  ✓ Deleted {$deletedKeys} idempotency keys");
            }
        });
    }
}
