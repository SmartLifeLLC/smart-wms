<?php

namespace App\Console\Commands;

use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Earning;
use App\Models\Wave;
use App\Models\WaveSetting;
use App\Models\WmsPickingItemResult;
use App\Services\StockAllocationService;
use App\Services\StockTransferLotAllocationService;
use App\Services\WarehouseResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateWavesCommand extends Command
{
    protected $signature = 'wms:generate-waves {--date= : Shipping date (YYYY-MM-DD), defaults to today} {--reset : Reset all wave-related data before generating new waves}';

    protected $description = 'Generate WMS waves based on wms_wave_settings for eligible earnings';

    public function handle()
    {
        $shippingDate = $this->option('date') ?? ClientSetting::systemDate()->format('Y-m-d');

        $shouldReset = $this->option('reset');

        $this->info("Generating waves for shipping date: {$shippingDate}");

        // Reset wave-related data if --reset flag is provided
        if ($shouldReset) {
            $this->warn('⚠️  Reset flag detected. Cleaning up all wave-related data...');
            $this->resetWaveData($shippingDate);
            $this->info('✓ Wave data reset completed.');
            $this->newLine();
        }

        // Get current time
        // Wave generation runs every 1 minute, so we only process waves where start time has passed
        $currentTime = now();

        $this->line("Current time: {$currentTime->format('H:i:s')}");
        $this->line('Processing waves with picking start time that has passed...');

        // Get wave settings where picking_start_time has already passed
        // This allows earnings to be entered up until the picking start time
        // NULL picking_start_time means no time restriction (always eligible)
        $waveSettings = WaveSetting::with('deliveryCourse')
            ->where(function ($query) use ($currentTime) {
                $query->whereNull('picking_start_time')
                    ->orWhereTime('picking_start_time', '<=', $currentTime->format('H:i:s'));
            })
            ->get();

        if ($waveSettings->isEmpty()) {
            $this->info('No wave settings found with picking start time before '.$currentTime->format('H:i:s').'. Skipping.');

            return 0;
        }

        $this->info("Found {$waveSettings->count()} wave setting(s) eligible for generation");

        $createdCount = 0;
        $skippedCount = 0;

        foreach ($waveSettings as $setting) {
            // warehouse_id is now resolved via accessor (deliveryCourse->warehouse_id)
            $warehouseId = $setting->warehouse_id;

            if (! $warehouseId) {
                $this->warn("Wave setting {$setting->id} has no associated warehouse (delivery_course_id: {$setting->delivery_course_id}). Skipping.");
                $skippedCount++;

                continue;
            }

            // Check if wave already exists for this setting and date
            // COMPLETED/CLOSED waves are allowed to have a new wave generated (e.g. afternoon shipments)
            $existingWave = Wave::where('wms_wave_setting_id', $setting->id)
                ->where('shipping_date', $shippingDate)
                ->whereNotIn('status', ['COMPLETED', 'CLOSED'])
                ->first();
            if ($existingWave) {
                $skippedCount++;

                continue;
            }

            // Check if there are eligible earnings for this wave
            // Filter by delivery_course_id only (warehouse_id is no longer on wave_setting)
            $earningsCount = Earning::where('delivered_date', $shippingDate)
                ->where('is_active', true)
                ->where('is_delivered', 0)
                ->where('picking_status', 'BEFORE')
                ->where('delivery_course_id', $setting->delivery_course_id)
                ->whereExists(fn ($query) => $this->activeTradeItemsExistsQuery($query))
                ->count();

            // Check if there are eligible stock_transfers for this wave
            // 仮想倉庫間移動は対象外（物理的ピッキング不要）
            $stockTransfersCount = $this->getEligibleStockTransfersQuery(
                $shippingDate,
                $warehouseId,
                $setting->delivery_course_id
            )->count();

            if ($earningsCount === 0 && $stockTransfersCount === 0) {
                $this->line("No eligible earnings or stock_transfers found for course {$setting->delivery_course_id}. Skipping.");
                $skippedCount++;

                continue;
            }

            // Create wave within transaction
            DB::transaction(function () use ($setting, $shippingDate, $earningsCount, $warehouseId, &$createdCount) {
                // Get warehouse and course codes for wave_no generation
                $warehouse = DB::connection('sakemaru')
                    ->table('warehouses')
                    ->where('id', $warehouseId)
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

                // Get earnings for this wave (filter by delivery_course_id only)
                $earnings = Earning::where('delivered_date', $shippingDate)
                    ->where('is_active', true)
                    ->where('is_delivered', 0)
                    ->where('picking_status', 'BEFORE')
                    ->where('delivery_course_id', $setting->delivery_course_id)
                    ->whereExists(fn ($query) => $this->activeTradeItemsExistsQuery($query))
                    ->get();

                // Create picking tasks grouped by warehouse, floor, picking_area, and delivery_course
                // IMPORTANT: All trade_items for same warehouse/floor/area/course go into ONE picking task

                // Get all trade items for all earnings in this wave
                $earningIds = $earnings->pluck('id')->toArray();
                $tradeIds = $earnings->pluck('trade_id')->toArray();

                $tradeItems = DB::connection('sakemaru')
                    ->table('trade_items')
                    ->whereIn('trade_id', $tradeIds)
                    ->where('is_active', true)
                    ->get();

                // Create earning_id and buyer_id lookup for each trade_item
                $tradeIdToEarningId = $earnings->pluck('id', 'trade_id')->toArray();
                $tradeIdToBuyerId = $earnings->pluck('buyer_id', 'trade_id')->toArray();

                // Group trade items by (floor_id, picking_area_id)
                // We determine the floor and picking area by reserving stock first
                $itemsByGroup = [];
                $reservationResults = [];

                foreach ($tradeItems as $tradeItem) {
                    // Get earning_id and buyer_id for this trade_item
                    $earningId = $tradeIdToEarningId[$tradeItem->trade_id] ?? null;
                    $buyerId = $tradeIdToBuyerId[$tradeItem->trade_id] ?? null;
                    if (! $earningId) {
                        continue; // Skip if no matching earning found
                    }

                    // Reserve stock for this trade item using optimized allocation service
                    $allocationService = new StockAllocationService;
                    $result = $allocationService->allocateForItem(
                        $wave->id,
                        $warehouseId,
                        $tradeItem->item_id,
                        $tradeItem->quantity,
                        $tradeItem->quantity_type ?? 'PIECE',
                        $earningId,
                        $tradeItem->id,
                        'EARNING',
                        $buyerId
                    );

                    // Get primary location and real_stock from first reservation
                    // Note: There may be multiple reservations if stock is split across locations
                    // We use the first reservation as the primary picking location
                    $primaryReservation = DB::connection('sakemaru')
                        ->table('wms_reservations')
                        ->where('wave_id', $wave->id)
                        ->where('item_id', $tradeItem->item_id)
                        ->where('source_id', $earningId)
                        ->whereNotNull('location_id')
                        ->orderBy('qty_each', 'desc') // Order by quantity (largest first)
                        ->orderBy('id', 'asc')
                        ->first();

                    $reservationResult = [
                        'allocated_qty' => $result['allocated'],
                        'real_stock_id' => $primaryReservation->real_stock_id ?? null,
                        'location_id' => $primaryReservation->location_id ?? null,
                        'walking_order' => null,
                    ];

                    $reservationResults[$tradeItem->id] = $reservationResult;

                    // Get picking area ID, floor ID, temperature_type, and is_restricted_area from primary location
                    $pickingAreaId = null;
                    $floorId = null;
                    $temperatureType = null;
                    $isRestrictedArea = false;

                    if ($reservationResult['location_id']) {
                        // Get all info from locations table directly
                        $location = DB::connection('sakemaru')
                            ->table('locations')
                            ->where('id', $reservationResult['location_id'])
                            ->first();
                        $floorId = $location->floor_id ?? null;
                        $temperatureType = $location->temperature_type ?? null;
                        $isRestrictedArea = $location->is_restricted_area ?? false;
                        $pickingAreaId = $location->wms_picking_area_id ?? null;
                    }

                    // If no location was found (shortage), try to find any historical location for this item
                    if ($pickingAreaId === null || $floorId === null) {
                        $itemLocation = DB::connection('sakemaru')
                            ->table('real_stocks as rs')
                            ->join('real_stock_lots as rsl', 'rs.id', '=', 'rsl.real_stock_id')
                            ->join('locations as l', 'rsl.location_id', '=', 'l.id')
                            ->where('rs.warehouse_id', $warehouseId)
                            ->where('rs.item_id', $tradeItem->item_id)
                            ->orderByRaw('l.floor_id IS NULL')
                            ->orderByRaw('l.wms_picking_area_id IS NULL')
                            ->select('l.wms_picking_area_id', 'l.floor_id', 'l.temperature_type', 'l.is_restricted_area')
                            ->first();

                        if ($itemLocation) {
                            $pickingAreaId = $pickingAreaId ?? $itemLocation->wms_picking_area_id;
                            $floorId = $floorId ?? $itemLocation->floor_id;
                            $temperatureType = $temperatureType ?? $itemLocation->temperature_type;
                            $isRestrictedArea = $isRestrictedArea ?? $itemLocation->is_restricted_area;
                        } else {
                            // If still no picking area found, assign to first active picking area as default
                            $defaultArea = DB::connection('sakemaru')
                                ->table('wms_picking_areas')
                                ->where('warehouse_id', $warehouseId)
                                ->where('is_active', true)
                                ->orderBy('display_order', 'asc')
                                ->first();

                            $pickingAreaId = $pickingAreaId ?? ($defaultArea->id ?? null);
                        }
                    }

                    // Group by floor_id × picking_area_id
                    // All items on the same floor and picking area go into one picking task
                    $groupKey = ($floorId ?? 'null').'_'.($pickingAreaId ?? 'null');
                    if (! isset($itemsByGroup[$groupKey])) {
                        $itemsByGroup[$groupKey] = [
                            'floor_id' => $floorId,
                            'picking_area_id' => $pickingAreaId, // Use first item's picking area as representative
                            'temperature_type' => $temperatureType, // Use first item's temperature type
                            'is_restricted_area' => $isRestrictedArea, // Use first item's restricted flag
                            'items' => [],
                        ];
                    }
                    $itemsByGroup[$groupKey]['items'][] = $tradeItem;

                    // Note: Shortage detection is now done at picking completion time
                    // (see PickingShortageDetector)
                }

                // Create one picking task per floor_id group
                foreach ($itemsByGroup as $groupData) {
                    // Skip groups with no items (all items had zero allocation)
                    if (empty($groupData['items'])) {
                        continue;
                    }

                    // Filter items to only include those with successful reservations OR valid shortages
                    $validItems = [];
                    $hasRestrictedItem = false;
                    foreach ($groupData['items'] as $tradeItem) {
                        $reservationResult = $reservationResults[$tradeItem->id] ?? null;
                        // Include if we have a reservation result (even if allocated_qty is 0)
                        if ($reservationResult) {
                            $validItems[] = $tradeItem;
                            // Check if this item's location is restricted
                            if ($reservationResult['location_id']) {
                                $location = DB::connection('sakemaru')
                                    ->table('locations')
                                    ->where('id', $reservationResult['location_id'])
                                    ->first();
                                if ($location && $location->is_restricted_area) {
                                    $hasRestrictedItem = true;
                                }
                            }
                        }
                    }

                    // Skip if no valid items remain after filtering
                    if (empty($validItems)) {
                        continue;
                    }

                    // 仮想倉庫判定: 同一実倉庫ならピッキングスキップ
                    // earning.warehouse_id (販売倉庫) と配送コース倉庫が同一実倉庫かチェック
                    $skipPicking = false;

                    $pickingTaskId = DB::connection('sakemaru')->table('wms_picking_tasks')->insertGetId([
                        'wave_id' => $wave->id,
                        'wms_picking_area_id' => $groupData['picking_area_id'],
                        'warehouse_id' => $warehouseId,
                        'warehouse_code' => $warehouse->code,
                        'floor_id' => $groupData['floor_id'],
                        'temperature_type' => $groupData['temperature_type'], // First item's temperature type (for display only)
                        'is_restricted_area' => $hasRestrictedItem, // True if ANY item is in restricted area
                        'delivery_course_id' => $setting->delivery_course_id,
                        'delivery_course_code' => $course->code,
                        'shipment_date' => $shippingDate,
                        'status' => 'PENDING',
                        'task_type' => 'WAVE',
                        'picker_id' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    // Create picking item results for valid items in this group
                    foreach ($validItems as $tradeItem) {
                        $reservationResult = $reservationResults[$tradeItem->id];
                        $earningId = $tradeIdToEarningId[$tradeItem->trade_id] ?? null;

                        // 仮想倉庫判定: earning の warehouse_id と配送コース倉庫が同一実倉庫か
                        $earning = $earnings->firstWhere('trade_id', $tradeItem->trade_id);
                        $earningWarehouseId = $earning->warehouse_id ?? null;
                        $itemSkipPicking = false;

                        if ($earningWarehouseId && $earningWarehouseId != $warehouseId) {
                            $itemSkipPicking = WarehouseResolver::isSameRealWarehouse($earningWarehouseId, $warehouseId);
                        }

                        // quantity_typeは必須フィールド
                        if (! $tradeItem->quantity_type) {
                            throw new \RuntimeException(
                                "quantity_type must be specified for trade_item ID {$tradeItem->id}"
                            );
                        }

                        $itemStatus = $itemSkipPicking ? 'COMPLETED' : 'PENDING';

                        DB::connection('sakemaru')->table('wms_picking_item_results')->insert([
                            'picking_task_id' => $pickingTaskId,
                            'earning_id' => $earningId, // Added: earning_id now tracked at item level
                            'source_type' => WmsPickingItemResult::SOURCE_TYPE_EARNING, // 伝票種別
                            'stock_transfer_id' => null, // 倉庫間移動ではないのでnull
                            'trade_id' => $tradeItem->trade_id, // Added: trade_id now tracked at item level
                            'trade_item_id' => $tradeItem->id,
                            'item_id' => $tradeItem->item_id,
                            'real_stock_id' => $reservationResult['real_stock_id'], // Primary real_stock from reservation
                            'location_id' => $reservationResult['location_id'], // Primary picking location from reservation
                            'walking_order' => $reservationResult['walking_order'], // Warehouse movement sequence for route optimization
                            'ordered_qty' => $tradeItem->quantity, // Original order quantity
                            'ordered_qty_type' => $tradeItem->quantity_type, // From trade_items.quantity_type (must be specified)
                            'planned_qty' => $reservationResult['allocated_qty'], // Allocated quantity from reservations
                            'planned_qty_type' => $tradeItem->quantity_type, // Same as ordered (for now)
                            'picked_qty' => $itemSkipPicking ? $reservationResult['allocated_qty'] : 0, // Auto-complete if same real warehouse
                            'picked_qty_type' => $tradeItem->quantity_type, // Will be set by picker
                            'shortage_qty' => max(0, $tradeItem->quantity - $reservationResult['allocated_qty']),
                            'status' => $itemStatus, // Changed: PENDING is initial state (not PICKING)
                            'picker_id' => null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        // 同一実倉庫の場合は earning の picking_status も COMPLETED に設定
                        if ($itemSkipPicking && $earningId) {
                            DB::connection('sakemaru')
                                ->table('earnings')
                                ->where('id', $earningId)
                                ->update([
                                    'picking_status' => 'COMPLETED',
                                    'updated_at' => now(),
                                ]);
                        }
                    }
                }

                // Update all earnings picking_status to BEFORE_PICKING (except those already set to COMPLETED)
                if (! empty($earningIds)) {
                    DB::connection('sakemaru')
                        ->table('earnings')
                        ->whereIn('id', $earningIds)
                        ->where('picking_status', 'BEFORE')
                        ->update([
                            'picking_status' => 'BEFORE_PICKING',
                            'updated_at' => now(),
                        ]);
                }

                // ============================================================
                // Stock Transfers Processing (倉庫間移動)
                // ============================================================
                $stockTransfers = $this->getEligibleStockTransfersQuery(
                    $shippingDate,
                    $warehouseId,
                    $setting->delivery_course_id
                )->get();

                $stockTransferIds = [];
                if ($stockTransfers->isNotEmpty()) {
                    $stockTransferIds = $stockTransfers->pluck('id')->toArray();
                    $stockTransferTradeIds = $stockTransfers->pluck('trade_id')->toArray();

                    // Get trade_items for stock_transfers
                    $stockTransferTradeItems = DB::connection('sakemaru')
                        ->table('trade_items')
                        ->whereIn('trade_id', $stockTransferTradeIds)
                        ->where('is_active', true)
                        ->get();

                    // Create stock_transfer_id lookup from trade_id
                    $tradeIdToStockTransferId = $stockTransfers->pluck('id', 'trade_id')->toArray();

                    // Process stock_transfer trade items (same logic as earnings)
                    foreach ($stockTransferTradeItems as $tradeItem) {
                        $stockTransferId = $tradeIdToStockTransferId[$tradeItem->trade_id] ?? null;
                        if (! $stockTransferId) {
                            continue;
                        }

                        // Reserve stock for this trade item
                        $allocationService = new StockTransferLotAllocationService;
                        $result = $allocationService->allocateForTradeItem(
                            $wave->id,
                            $warehouseId,
                            $stockTransferId,
                            $tradeItem
                        );

                        // Get primary location and real_stock from first reservation
                        $primaryReservation = DB::connection('sakemaru')
                            ->table('wms_reservations')
                            ->where('wave_id', $wave->id)
                            ->where('item_id', $tradeItem->item_id)
                            ->where('source_id', $stockTransferId)
                            ->where('source_type', 'STOCK_TRANSFER')
                            ->where('source_line_id', $tradeItem->id)
                            ->whereNotNull('location_id')
                            ->orderBy('qty_each', 'desc')
                            ->orderBy('id', 'asc')
                            ->first();

                        $reservationResult = [
                            'allocated_qty' => $result['allocated'],
                            'real_stock_id' => $primaryReservation->real_stock_id ?? null,
                            'location_id' => $primaryReservation->location_id ?? null,
                        ];

                        // Get picking area and floor from locations table
                        $pickingAreaId = null;
                        $floorId = null;

                        if ($reservationResult['location_id']) {
                            $location = DB::connection('sakemaru')
                                ->table('locations')
                                ->where('id', $reservationResult['location_id'])
                                ->first();
                            $floorId = $location->floor_id ?? null;
                            $pickingAreaId = $location->wms_picking_area_id ?? null;
                        }

                        // Default picking area if not found
                        if ($pickingAreaId === null) {
                            $defaultArea = DB::connection('sakemaru')
                                ->table('wms_picking_areas')
                                ->where('warehouse_id', $warehouseId)
                                ->where('is_active', true)
                                ->orderBy('display_order', 'asc')
                                ->first();
                            $pickingAreaId = $defaultArea->id ?? null;
                        }

                        // Find or create picking task for this floor × picking area
                        $groupKey = 'ST_'.($floorId ?? 'null').'_'.($pickingAreaId ?? 'null');
                        $existingTask = DB::connection('sakemaru')
                            ->table('wms_picking_tasks')
                            ->where('wave_id', $wave->id)
                            ->where('floor_id', $floorId)
                            ->where('wms_picking_area_id', $pickingAreaId)
                            ->first();

                        if ($existingTask) {
                            $pickingTaskId = $existingTask->id;
                        } else {
                            $pickingTaskId = DB::connection('sakemaru')->table('wms_picking_tasks')->insertGetId([
                                'wave_id' => $wave->id,
                                'wms_picking_area_id' => $pickingAreaId,
                                'warehouse_id' => $warehouseId,
                                'warehouse_code' => $warehouse->code,
                                'floor_id' => $floorId,
                                'delivery_course_id' => $setting->delivery_course_id,
                                'delivery_course_code' => $course->code,
                                'shipment_date' => $shippingDate,
                                'status' => 'PENDING',
                                'task_type' => 'WAVE',
                                'picker_id' => null,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }

                        // Create picking item result for stock_transfer
                        if (! $tradeItem->quantity_type) {
                            throw new \RuntimeException(
                                "quantity_type must be specified for trade_item ID {$tradeItem->id}"
                            );
                        }

                        DB::connection('sakemaru')->table('wms_picking_item_results')->insert([
                            'picking_task_id' => $pickingTaskId,
                            'earning_id' => null, // Not an earning
                            'source_type' => WmsPickingItemResult::SOURCE_TYPE_STOCK_TRANSFER,
                            'stock_transfer_id' => $stockTransferId,
                            'trade_id' => $tradeItem->trade_id,
                            'trade_item_id' => $tradeItem->id,
                            'item_id' => $tradeItem->item_id,
                            'real_stock_id' => $reservationResult['real_stock_id'],
                            'location_id' => $reservationResult['location_id'],
                            'walking_order' => $reservationResult['walking_order'],
                            'ordered_qty' => $tradeItem->quantity,
                            'ordered_qty_type' => $tradeItem->quantity_type,
                            'planned_qty' => $reservationResult['allocated_qty'],
                            'planned_qty_type' => $tradeItem->quantity_type,
                            'picked_qty' => 0,
                            'picked_qty_type' => $tradeItem->quantity_type,
                            'shortage_qty' => max(0, $tradeItem->quantity - $reservationResult['allocated_qty']),
                            'status' => 'PENDING',
                            'picker_id' => null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    // Update stock_transfers picking_status to BEFORE_PICKING
                    DB::connection('sakemaru')
                        ->table('stock_transfers')
                        ->whereIn('id', $stockTransferIds)
                        ->update([
                            'picking_status' => 'BEFORE_PICKING',
                            'updated_at' => now(),
                        ]);
                }

                $totalCount = $earningsCount + count($stockTransferIds);
                $this->info("Created wave {$waveNo} with {$earningsCount} earnings, ".count($stockTransferIds).' stock_transfers');
                $createdCount++;
            });
        }

        $this->info("Wave generation completed. Created: {$createdCount}, Skipped: {$skippedCount}");

        return 0;
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
            $this->info('  Found '.count($waveIds).' wave(s) to reset.');

            // 1. Get earnings that were part of these waves (via picking_item_results)
            $earningIds = DB::connection('sakemaru')
                ->table('wms_picking_item_results')
                ->whereIn('picking_task_id', function ($query) use ($waveIds) {
                    $query->select('id')
                        ->from('wms_picking_tasks')
                        ->whereIn('wave_id', $waveIds);
                })
                ->whereNotNull('earning_id')
                ->pluck('earning_id')
                ->unique()
                ->toArray();

            if (! empty($earningIds)) {
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

            // 1b. Get stock_transfers that were part of these waves
            $stockTransferIds = DB::connection('sakemaru')
                ->table('wms_picking_item_results')
                ->whereIn('picking_task_id', function ($query) use ($waveIds) {
                    $query->select('id')
                        ->from('wms_picking_tasks')
                        ->whereIn('wave_id', $waveIds);
                })
                ->whereNotNull('stock_transfer_id')
                ->pluck('stock_transfer_id')
                ->unique()
                ->toArray();

            if (! empty($stockTransferIds)) {
                // Reset stock_transfer status back to BEFORE
                $updatedTransfers = DB::connection('sakemaru')
                    ->table('stock_transfers')
                    ->whereIn('id', $stockTransferIds)
                    ->update([
                        'picking_status' => 'BEFORE',
                        'updated_at' => now(),
                    ]);
                $this->info("  ✓ Reset {$updatedTransfers} stock_transfers to BEFORE status");
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

            // 4. Delete reservations
            // Note: real_stocks の数量更新は行わない（Sakemaru側で管理）
            $deletedReservations = DB::connection('sakemaru')
                ->table('wms_reservations')
                ->whereIn('wave_id', $waveIds)
                ->delete();
            $this->info("  ✓ Deleted {$deletedReservations} reservations");

            // 5. Delete shortage allocations (横持ち出荷)
            $deletedShortageAllocations = DB::connection('sakemaru')
                ->table('wms_shortage_allocations')
                ->whereIn('shortage_id', function ($query) use ($waveIds) {
                    $query->select('id')
                        ->from('wms_shortages')
                        ->whereIn('wave_id', $waveIds);
                })
                ->delete();
            $this->info("  ✓ Deleted {$deletedShortageAllocations} shortage allocations");

            // 6. Delete shortages
            $deletedShortages = DB::connection('sakemaru')
                ->table('wms_shortages')
                ->whereIn('wave_id', $waveIds)
                ->delete();
            $this->info("  ✓ Deleted {$deletedShortages} shortages");

            // 7. Delete waves
            $deletedWaves = Wave::whereIn('id', $waveIds)->delete();
            $this->info("  ✓ Deleted {$deletedWaves} waves");

            // 8. Delete idempotency keys for wave reservations
            $deletedKeys = DB::connection('sakemaru')
                ->table('wms_idempotency_keys')
                ->where('scope', 'wave_reservation')
                ->delete();

            if ($deletedKeys > 0) {
                $this->info("  ✓ Deleted {$deletedKeys} idempotency keys");
            }
        });
    }

    /**
     * ピッキング対象の倉庫間移動伝票クエリを取得
     *
     * 仮想倉庫間移動（物理的ピッキング不要）は除外：
     * - from_warehouse.is_virtual = true AND to_warehouse.is_virtual = true
     * - from_warehouse.stock_warehouse_id == to_warehouse.stock_warehouse_id
     */
    protected function getEligibleStockTransfersQuery(
        string $shippingDate,
        int $warehouseId,
        int $deliveryCourseId
    ) {
        // 選択倉庫と同一実倉庫に属する全倉庫IDを取得（仮想倉庫を含む）
        $warehouseIds = WarehouseResolver::resolveAllWarehouseIds($warehouseId);

        return DB::connection('sakemaru')
            ->table('stock_transfers as st')
            ->join('trades as st_trade', 'st.trade_id', '=', 'st_trade.id')
            ->join('warehouses as fw', 'st.from_warehouse_id', '=', 'fw.id')
            ->join('warehouses as tw', 'st.to_warehouse_id', '=', 'tw.id')
            // picking_date を使用（picking_date = ピッキング予定日）
            // ※ picking_date が NULL の場合は delivered_date を使用（後方互換性）
            // ※ 倉庫間移動は移動元のリード日数で picking_date が前倒しになるため、
            //    delivered_date が対象出荷日の伝票も波動対象に含める
            ->where(function ($query) use ($shippingDate) {
                $query->whereRaw('COALESCE(st.picking_date, st.delivered_date) = ?', [$shippingDate])
                    ->orWhereDate('st.delivered_date', $shippingDate);
            })
            ->where('st.is_active', true)
            ->where('st_trade.is_active', true)
            ->where('st.picking_status', 'BEFORE')
            ->where('st.is_delivered', false)
            ->where('st.is_confirmed', false)
            ->whereIn('st.from_warehouse_id', $warehouseIds)
            ->where('st.delivery_course_id', $deliveryCourseId)
            // 仮想倉庫間移動は対象外
            ->where(function ($query) {
                $query->where(function ($q) {
                    // 両方が仮想倉庫の場合は対象外
                    $q->where('fw.is_virtual', false)
                        ->orWhere('tw.is_virtual', false);
                })
                    ->where(function ($q) {
                        // 同じ実倉庫に紐づく仮想倉庫間の場合は対象外
                        $q->whereRaw('COALESCE(fw.stock_warehouse_id, fw.id) != COALESCE(tw.stock_warehouse_id, tw.id)');
                    });
            })
            ->select('st.*');
    }

    protected function activeTradeItemsExistsQuery($query)
    {
        return $query
            ->select(DB::raw(1))
            ->from('trade_items as active_trade_items')
            ->join('trades as active_trades', 'active_trade_items.trade_id', '=', 'active_trades.id')
            ->whereColumn('active_trade_items.trade_id', 'earnings.trade_id')
            ->where('active_trade_items.is_active', true)
            ->where('active_trades.is_active', true);
    }
}
