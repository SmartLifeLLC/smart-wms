<?php

namespace App\Services;

use App\Models\Sakemaru\Earning;
use App\Models\Wave;
use App\Models\WaveGroup;
use App\Models\WaveSetting;
use App\Models\WmsPickingItemResult;
use App\Models\WmsQueueProgress;
use App\Services\PickingList\PickingListPdfService;
use App\Services\PickingList\PickingListService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class WaveGroupGenerationService
{
    /**
     * @return array{wave_ids: array<int>, earning_count: int, stock_transfer_count: int, picking_lists: array<string, array<string, mixed>>, timings_ms: array<string, int>}
     */
    /**
     * 波動生成の前段でロット調節を実行する。
     *
     * 【最重要】調節の成否・例外は波動生成に一切影響させない。
     * - LotAdjustmentRunner::runForWaveGeneration() は内部で全例外を握りつぶし、失敗を
     *   ロット調節履歴（wms_lot_adjustment_logs）に残す。
     * - 念のためここでも Throwable を握りつぶし、波動生成を必ず継続する。
     * - 調節は独立したトランザクションで完結しており、波動生成と DB トランザクションを共有しない。
     */
    private function runPreWaveLotAdjustment(int $warehouseId): void
    {
        try {
            app(\App\Services\LotAdjustment\LotAdjustmentRunner::class)
                ->runForWaveGeneration($warehouseId);
        } catch (\Throwable $e) {
            // 二重の安全網: ここで握りつぶし、波動生成は継続する
            Log::error('[LOT_ADJUSTMENT] pre-wave lot adjustment failed; wave generation continues', [
                'warehouse_id' => $warehouseId,
                'error' => $e->getMessage(),
                'error_class' => $e::class,
            ]);
        }
    }

    public function generate(WaveGroup $waveGroup, int $userId, ?WmsQueueProgress $progress = null): array
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(3600);

        $startedAt = microtime(true);
        $timings = [];
        $data = $waveGroup->conditions ?? [];
        $shippingDate = $waveGroup->shipping_date->format('Y-m-d');
        $data['shipping_date'] = $shippingDate;
        $data['shipping_dates'] = $this->normalizeShippingDates($data['shipping_dates'] ?? ($data['shipping_date'] ?? null)) ?: [$shippingDate];
        $data['warehouse_id'] = $waveGroup->warehouse_id;
        $data['generation_type'] = $waveGroup->generation_type;
        $data['target_document_types'] = $waveGroup->target_document_types ?? ['shipment', 'transfer'];

        // 波動生成の前にロット調節を実行する。
        // 【最重要】調節が失敗・例外・部分適用になっても波動生成には一切影響させない。
        // runForWaveGeneration は例外を投げない設計だが、二重の安全網としてここでも握りつぶす。
        $this->runPreWaveLotAdjustment((int) $waveGroup->warehouse_id);

        $phaseStartedAt = microtime(true);
        $progress?->markAsProcessing(100, '波動生成を開始しています');
        $generationResult = $this->createWavesFromCourses($data, $waveGroup, $userId);
        $timings['wave_generation'] = $this->elapsedMilliseconds($phaseStartedAt);

        $waveIds = $generationResult['wave_ids'];
        $progress?->updateProgress(70, '波動生成が完了しました。ピッキングリストを保存しています');

        $phaseStartedAt = microtime(true);
        $pickingLists = empty($waveIds)
            ? []
            : $this->savePickingLists($waveGroup, $waveIds);
        $timings['picking_list_save'] = $this->elapsedMilliseconds($phaseStartedAt);
        $timings['total'] = $this->elapsedMilliseconds($startedAt);

        $result = [
            ...$generationResult,
            'stock_sync' => [
                'skipped' => true,
                'reason' => 'automatic stock lot sync is disabled for wave generation',
            ],
            'picking_lists' => $pickingLists,
            'timings_ms' => $timings,
            'started_at' => now()->toDateTimeString(),
            'completed_at' => now()->toDateTimeString(),
        ];

        $waveGroup->update([
            'generation_result' => $result,
            'picking_lists' => $pickingLists,
        ]);

        Log::info('Wave group generation completed', [
            'wave_group_id' => $waveGroup->id,
            'group_no' => $waveGroup->group_no,
            'wave_count' => count($waveIds),
            'earning_count' => $generationResult['earning_count'],
            'stock_transfer_count' => $generationResult['stock_transfer_count'],
            'timings_ms' => $timings,
        ]);

        $progress?->markAsCompleted($result, '波動生成とピッキングリスト保存が完了しました');

        return $result;
    }

    /**
     * @param  array<int>  $waveIds
     * @return array<string, array<string, mixed>>
     */
    public function savePickingLists(WaveGroup $waveGroup, array $waveIds): array
    {
        $listService = new PickingListService;
        $pdfService = new PickingListPdfService;
        $separateFloors = (bool) (($waveGroup->conditions['separate_floors'] ?? true));
        $shippingDate = $waveGroup->shipping_date->format('Y-m-d');
        $datePath = $waveGroup->shipping_date->format('Y/m/d');

        $renderers = [
            'primary' => fn (): string => $pdfService->renderBatchPrimaryPdf(
                $listService->generatePrimaryCourseListPages($waveIds, $separateFloors)
            ),
            'primary_total' => fn (): string => $pdfService->renderBatchPrimaryPdf(
                $listService->generatePrimaryTotalListPages($waveIds, $separateFloors)
            ),
            'shortage' => fn (): string => $pdfService->renderBatchShortagePdf(
                $listService->generateShortageCourseLists($waveIds)
            ),
            'secondary' => fn (): string => $pdfService->renderCourseGroupedPdf(
                $listService->generateCourseGroupedListByWaveIds($waveIds)
            ),
            'secondary_v2' => fn (): string => $pdfService->renderCourseGroupedPdf(
                $listService->generateCourseGroupedListV2ByWaveIds($waveIds)
            ),
            'tertiary' => fn (): string => $pdfService->renderBuyerGroupedPdf(
                $listService->generateBuyerGroupedListByWaveIds($waveIds)
            ),
        ];

        $saved = [];
        foreach ($renderers as $listType => $renderer) {
            $pdf = $renderer();
            $filename = "wave-group-{$waveGroup->group_no}-{$listType}-{$shippingDate}.pdf";
            $path = "wms/picking-lists/{$datePath}/wave-group-{$waveGroup->id}/{$filename}";

            if (! Storage::disk('s3')->put($path, $pdf)) {
                throw new \RuntimeException("ピッキングリストのS3保存に失敗しました: {$listType}");
            }

            $saved[$listType] = [
                'disk' => 's3',
                'path' => $path,
                'filename' => $filename,
                'mime_type' => 'application/pdf',
                'size' => strlen($pdf),
                'checksum' => 'sha256:'.hash('sha256', $pdf),
                'generated_at' => now()->toDateTimeString(),
                'source' => [
                    'wave_ids' => array_values($waveIds),
                    'separate_floors' => $separateFloors,
                ],
            ];
        }

        return $saved;
    }

    /**
     * @return array{wave_ids: array<int>, earning_count: int, stock_transfer_count: int}
     */
    protected function createWavesFromCourses(array $data, WaveGroup $waveGroup, int $userId): array
    {
        $warehouseId = (int) $data['warehouse_id'];
        $shippingDates = $this->normalizeShippingDates($data['shipping_dates'] ?? ($data['shipping_date'] ?? null));
        $generationType = $data['generation_type'] ?? 'delivery_course';
        $deliveryCourseIds = $data['delivery_course_ids'] ?? [];
        $buyerIds = $data['buyer_ids'] ?? [];
        $includePast = (bool) ($data['include_past'] ?? false);
        $targetDocumentTypes = $this->normalizeTargetDocumentTypes($data['target_document_types'] ?? null);

        if (empty($shippingDates)) {
            return ['wave_ids' => [], 'earning_count' => 0, 'stock_transfer_count' => 0];
        }

        $warehouseIds = WarehouseResolver::resolveAllWarehouseIds($warehouseId);

        if ($generationType === 'buyer') {
            $earningQuery = Earning::query()
                ->join('delivery_courses', 'earnings.delivery_course_id', '=', 'delivery_courses.id')
                ->whereIn('delivery_courses.warehouse_id', $warehouseIds)
                ->where('earnings.is_active', true)
                ->where('earnings.is_delivered', 0)
                ->where('earnings.picking_status', 'BEFORE')
                ->whereNotNull('earnings.delivery_course_id')
                ->whereIn('earnings.buyer_id', $buyerIds)
                ->whereExists(fn ($query) => $this->activeTradeItemsExistsQuery($query))
                ->select('earnings.*');

            $earnings = $this->applyDateFilter($earningQuery, 'earnings.delivered_date', $shippingDates, $includePast)
                ->get();

            $stockTransfers = collect();
        } else {
            $validCourseIds = DB::connection('sakemaru')
                ->table('delivery_courses')
                ->whereIn('warehouse_id', $warehouseIds)
                ->whereIn('id', $deliveryCourseIds)
                ->pluck('id')
                ->toArray();

            $earnings = collect();
            if ($this->includesShipmentDocuments($targetDocumentTypes)) {
                $earningQuery = Earning::query()
                    ->where('is_delivered', 0)
                    ->where('is_active', true)
                    ->where('picking_status', 'BEFORE')
                    ->whereNotNull('delivery_course_id')
                    ->whereIn('delivery_course_id', $validCourseIds)
                    ->whereExists(fn ($query) => $this->activeTradeItemsExistsQuery($query));

                $earnings = $this->applyDateFilter($earningQuery, 'delivered_date', $shippingDates, $includePast)
                    ->get();
            }

            $stockTransfers = collect();
            if ($this->includesTransferDocuments($targetDocumentTypes)) {
                $stockTransfers = $this->getEligibleStockTransfersQuery($shippingDates, $warehouseId, $includePast)
                    ->whereIn('st.delivery_course_id', $validCourseIds)
                    ->get();
            }
        }

        if ($earnings->isEmpty() && $stockTransfers->isEmpty()) {
            return ['wave_ids' => [], 'earning_count' => 0, 'stock_transfer_count' => 0];
        }

        $earningsByKey = $earnings->groupBy(
            fn ($earning): string => Carbon::parse($earning->delivered_date)->format('Y-m-d').'|'.$earning->delivery_course_id
        );
        $generationShippingDate = Carbon::parse($data['shipping_date'] ?? $this->latestShippingDate($shippingDates))->format('Y-m-d');
        $stockTransfersByKey = $stockTransfers->groupBy(
            fn ($stockTransfer): string => $generationShippingDate.'|'.$stockTransfer->delivery_course_id
        );

        $keys = $earningsByKey->keys()
            ->merge($stockTransfersByKey->keys())
            ->unique()
            ->sort()
            ->values();

        $createdWaveIds = [];
        $totalEarnings = 0;
        $totalStockTransfers = 0;

        DB::connection('sakemaru')->transaction(function () use (
            $warehouseId,
            $keys,
            $earningsByKey,
            $stockTransfersByKey,
            $waveGroup,
            $userId,
            &$createdWaveIds,
            &$totalEarnings,
            &$totalStockTransfers
        ) {
            $warehouse = DB::connection('sakemaru')
                ->table('warehouses')
                ->where('id', $warehouseId)
                ->first();

            foreach ($keys as $key) {
                [$shippingDate, $deliveryCourseId] = explode('|', (string) $key, 2);
                $deliveryCourseId = (int) $deliveryCourseId;
                $courseEarnings = $earningsByKey->get($key, collect());
                $courseStockTransfers = $stockTransfersByKey->get($key, collect());

                $waveSetting = WaveSetting::where('delivery_course_id', $deliveryCourseId)->first();
                if (! $waveSetting) {
                    $waveSetting = WaveSetting::create([
                        'delivery_course_id' => $deliveryCourseId,
                        'picking_start_time' => null,
                        'picking_deadline_time' => null,
                        'creator_id' => $userId,
                        'last_updater_id' => $userId,
                    ]);
                }

                $course = DB::connection('sakemaru')
                    ->table('delivery_courses')
                    ->where('id', $deliveryCourseId)
                    ->first();

                $wave = $this->createWaveSafely($waveSetting, $warehouse, $course, $shippingDate, $waveGroup);

                if ($courseEarnings->isNotEmpty()) {
                    $this->processEarningsForWave($wave, $waveSetting, $courseEarnings, $warehouse, $course, $shippingDate);
                    $totalEarnings += $courseEarnings->count();
                }

                if ($courseStockTransfers->isNotEmpty()) {
                    $this->processStockTransfersForWave($wave, $waveSetting, $courseStockTransfers, $warehouse, $course, $shippingDate);
                    $totalStockTransfers += $courseStockTransfers->count();
                }

                $createdWaveIds[] = $wave->id;
            }
        });

        return [
            'wave_ids' => $createdWaveIds,
            'earning_count' => $totalEarnings,
            'stock_transfer_count' => $totalStockTransfers,
        ];
    }

    protected function createWaveSafely(WaveSetting $waveSetting, $warehouse, $course, string $shippingDate, WaveGroup $waveGroup): Wave
    {
        $wave = Wave::create([
            'wave_group_id' => $waveGroup->id,
            'wms_wave_setting_id' => $waveSetting->id,
            'wave_no' => uniqid('TEMP_'),
            'shipping_date' => $shippingDate,
            'status' => 'PENDING',
        ]);

        $wave->update([
            'wave_no' => Wave::generateWaveNo(
                $warehouse->code ?? 0,
                $course->code ?? 0,
                $shippingDate,
                $wave->id
            ),
        ]);

        return $wave;
    }

    protected function processEarningsForWave(Wave $wave, WaveSetting $waveSetting, $earnings, $warehouse, $course, string $shippingDate): void
    {
        $earningIds = $earnings->pluck('id')->toArray();
        $tradeIds = $earnings->pluck('trade_id')->toArray();

        DB::connection('sakemaru')
            ->table('wms_picking_tasks')
            ->where('wave_id', $wave->id)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('wms_picking_item_results')
                    ->whereColumn('wms_picking_item_results.picking_task_id', 'wms_picking_tasks.id');
            })
            ->delete();

        DB::connection('sakemaru')
            ->table('wms_reservations')
            ->where('wave_id', $wave->id)
            ->whereIn('source_id', $earningIds)
            ->where('source_type', 'EARNING')
            ->delete();

        $tradeItems = DB::connection('sakemaru')
            ->table('trade_items')
            ->join('trades as ti_trade', 'trade_items.trade_id', '=', 'ti_trade.id')
            ->whereIn('trade_items.trade_id', $tradeIds)
            ->where('trade_items.is_active', true)
            ->orderBy('ti_trade.serial_id')
            ->orderBy('trade_items.id')
            ->select('trade_items.*')
            ->get();

        $tradeIdToEarningId = $earnings->pluck('id', 'trade_id')->toArray();
        $tradeIdToBuyerId = $earnings->pluck('buyer_id', 'trade_id')->toArray();
        $allocationService = new StockAllocationService;
        $locationCache = [];
        $itemLocationCache = [];
        $defaultAreaCache = [];

        $itemsByGroup = [];
        $reservationResults = [];

        foreach ($tradeItems as $tradeItem) {
            $earningId = $tradeIdToEarningId[$tradeItem->trade_id] ?? null;
            $buyerId = $tradeIdToBuyerId[$tradeItem->trade_id] ?? null;
            if (! $earningId) {
                continue;
            }

            $result = $allocationService->allocateForItem(
                $wave->id,
                $waveSetting->warehouse_id,
                $tradeItem->item_id,
                $tradeItem->quantity,
                $tradeItem->quantity_type ?? 'PIECE',
                $earningId,
                $tradeItem->id,
                'EARNING',
                $buyerId
            );

            $primaryReservation = DB::connection('sakemaru')
                ->table('wms_reservations')
                ->where('wave_id', $wave->id)
                ->where('item_id', $tradeItem->item_id)
                ->where('source_id', $earningId)
                ->whereNotNull('location_id')
                ->orderBy('qty_each', 'desc')
                ->orderBy('id', 'asc')
                ->first();

            $reservationResult = [
                'allocated_qty' => $result['allocated'],
                'shortage_qty' => $result['shortage'] ?? 0,
                'real_stock_id' => $primaryReservation->real_stock_id ?? null,
                'location_id' => $primaryReservation->location_id ?? null,
                'walking_order' => null,
            ];

            $reservationResults[$tradeItem->id] = $reservationResult;

            $pickingAreaId = null;
            $floorId = null;
            $temperatureType = null;
            $isRestrictedArea = false;

            if ($reservationResult['location_id']) {
                $location = $locationCache[$reservationResult['location_id']] ??= DB::connection('sakemaru')
                    ->table('locations')
                    ->where('id', $reservationResult['location_id'])
                    ->first();
                $floorId = $location->floor_id ?? null;
                $temperatureType = $location->temperature_type ?? null;
                $isRestrictedArea = $location->is_restricted_area ?? false;
                $pickingAreaId = $location->wms_picking_area_id ?? null;
            }

            if ($pickingAreaId === null || $floorId === null) {
                $itemLocation = $itemLocationCache[$tradeItem->item_id] ??= DB::connection('sakemaru')
                    ->table('real_stocks as rs')
                    ->join('real_stock_lots as rsl', 'rs.id', '=', 'rsl.real_stock_id')
                    ->join('locations as l', 'rsl.location_id', '=', 'l.id')
                    ->where('rs.warehouse_id', $waveSetting->warehouse_id)
                    ->where('rs.item_id', $tradeItem->item_id)
                    ->whereNotNull('l.wms_picking_area_id')
                    ->select('l.id as location_id', 'rs.id as real_stock_id', 'l.wms_picking_area_id', 'l.floor_id', 'l.temperature_type', 'l.is_restricted_area')
                    ->first();

                if ($itemLocation) {
                    if ($reservationResult['location_id'] === null) {
                        $reservationResult['location_id'] = $itemLocation->location_id;
                        $reservationResult['real_stock_id'] = $itemLocation->real_stock_id;
                        $reservationResults[$tradeItem->id] = $reservationResult;
                    }
                    $pickingAreaId = $pickingAreaId ?? $itemLocation->wms_picking_area_id;
                    $floorId = $floorId ?? $itemLocation->floor_id;
                    $temperatureType = $temperatureType ?? $itemLocation->temperature_type;
                    $isRestrictedArea = $isRestrictedArea ?? $itemLocation->is_restricted_area;
                } else {
                    $defaultArea = $defaultAreaCache[$waveSetting->warehouse_id] ??= DB::connection('sakemaru')
                        ->table('wms_picking_areas')
                        ->where('warehouse_id', $waveSetting->warehouse_id)
                        ->where('is_active', true)
                        ->orderBy('display_order', 'asc')
                        ->first();
                    $pickingAreaId = $pickingAreaId ?? ($defaultArea->id ?? null);
                }
            }

            $groupKey = ($floorId ?? 'null');
            if (! isset($itemsByGroup[$groupKey])) {
                $itemsByGroup[$groupKey] = [
                    'floor_id' => $floorId,
                    'picking_area_id' => $pickingAreaId,
                    'temperature_type' => $temperatureType,
                    'is_restricted_area' => $isRestrictedArea,
                    'items' => [],
                ];
            }
            $itemsByGroup[$groupKey]['items'][] = $tradeItem;
        }

        foreach ($itemsByGroup as $groupData) {
            $validItems = [];
            $hasRestrictedItem = false;
            foreach ($groupData['items'] as $tradeItem) {
                $reservationResult = $reservationResults[$tradeItem->id] ?? null;
                if (! $reservationResult) {
                    continue;
                }
                $validItems[] = $tradeItem;
                if ($reservationResult['location_id']) {
                    $location = $locationCache[$reservationResult['location_id']] ??= DB::connection('sakemaru')
                        ->table('locations')
                        ->where('id', $reservationResult['location_id'])
                        ->first();
                    $hasRestrictedItem = $hasRestrictedItem || (bool) ($location->is_restricted_area ?? false);
                }
            }

            if (empty($validItems)) {
                continue;
            }

            $pickingTaskId = DB::connection('sakemaru')->table('wms_picking_tasks')->insertGetId([
                'wave_id' => $wave->id,
                'wms_picking_area_id' => $groupData['picking_area_id'],
                'warehouse_id' => $waveSetting->warehouse_id,
                'warehouse_code' => $warehouse->code,
                'floor_id' => $groupData['floor_id'],
                'temperature_type' => $groupData['temperature_type'],
                'is_restricted_area' => $hasRestrictedItem,
                'delivery_course_id' => $waveSetting->delivery_course_id,
                'delivery_course_code' => $course->code,
                'shipment_date' => $shippingDate,
                'status' => 'PENDING',
                'task_type' => 'WAVE',
                'picker_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($validItems as $tradeItem) {
                $reservationResult = $reservationResults[$tradeItem->id];
                $earningId = $tradeIdToEarningId[$tradeItem->trade_id] ?? null;

                if (! $tradeItem->quantity_type) {
                    throw new \RuntimeException("quantity_type must be specified for trade_item ID {$tradeItem->id}");
                }

                DB::connection('sakemaru')->table('wms_picking_item_results')->insert([
                    'picking_task_id' => $pickingTaskId,
                    'earning_id' => $earningId,
                    'source_type' => WmsPickingItemResult::SOURCE_TYPE_EARNING,
                    'stock_transfer_id' => null,
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
                    'shortage_qty' => $reservationResult['shortage_qty'] ?? 0,
                    'status' => 'PENDING',
                    'picker_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        DB::connection('sakemaru')
            ->table('earnings')
            ->whereIn('id', $earningIds)
            ->update([
                'picking_status' => 'BEFORE_PICKING',
                'updated_at' => now(),
            ]);
    }

    protected function processStockTransfersForWave(Wave $wave, WaveSetting $waveSetting, $stockTransfers, $warehouse, $course, string $shippingDate): void
    {
        $stockTransferIds = $stockTransfers->pluck('id')->toArray();
        $tradeIds = $stockTransfers->pluck('trade_id')->toArray();

        DB::connection('sakemaru')
            ->table('wms_picking_tasks')
            ->where('wave_id', $wave->id)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('wms_picking_item_results')
                    ->whereColumn('wms_picking_item_results.picking_task_id', 'wms_picking_tasks.id');
            })
            ->delete();

        DB::connection('sakemaru')
            ->table('wms_reservations')
            ->where('wave_id', $wave->id)
            ->whereIn('source_id', $stockTransferIds)
            ->where('source_type', 'STOCK_TRANSFER')
            ->delete();

        $tradeIdToStockTransferId = $stockTransfers->pluck('id', 'trade_id')->toArray();
        $tradeItems = DB::connection('sakemaru')
            ->table('trade_items')
            ->join('trades as ti_trade', 'trade_items.trade_id', '=', 'ti_trade.id')
            ->whereIn('trade_items.trade_id', $tradeIds)
            ->where('trade_items.is_active', true)
            ->orderBy('ti_trade.serial_id')
            ->orderBy('trade_items.id')
            ->select('trade_items.*')
            ->get();

        $allocationService = new StockTransferLotAllocationService;
        $locationCache = [];
        $itemLocationCache = [];
        $defaultAreaCache = [];

        foreach ($tradeItems as $tradeItem) {
            $stockTransferId = $tradeIdToStockTransferId[$tradeItem->trade_id] ?? null;
            if (! $stockTransferId) {
                continue;
            }

            $result = $allocationService->allocateForTradeItem(
                $wave->id,
                $waveSetting->warehouse_id,
                $stockTransferId,
                $tradeItem
            );

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
                'shortage_qty' => $result['shortage'] ?? 0,
                'real_stock_id' => $primaryReservation->real_stock_id ?? null,
                'location_id' => $primaryReservation->location_id ?? null,
                'walking_order' => null,
            ];

            $pickingAreaId = null;
            $floorId = null;

            if ($reservationResult['location_id']) {
                $location = $locationCache[$reservationResult['location_id']] ??= DB::connection('sakemaru')
                    ->table('locations')
                    ->where('id', $reservationResult['location_id'])
                    ->first();
                $floorId = $location->floor_id ?? null;
                $pickingAreaId = $location->wms_picking_area_id ?? null;
            }

            if ($pickingAreaId === null || $floorId === null) {
                $itemLocation = $itemLocationCache[$tradeItem->item_id] ??= DB::connection('sakemaru')
                    ->table('real_stocks as rs')
                    ->join('real_stock_lots as rsl', 'rs.id', '=', 'rsl.real_stock_id')
                    ->join('locations as l', 'rsl.location_id', '=', 'l.id')
                    ->where('rs.warehouse_id', $waveSetting->warehouse_id)
                    ->where('rs.item_id', $tradeItem->item_id)
                    ->whereNotNull('l.wms_picking_area_id')
                    ->select('l.id as location_id', 'rs.id as real_stock_id', 'l.wms_picking_area_id', 'l.floor_id')
                    ->first();

                if ($itemLocation) {
                    if ($reservationResult['location_id'] === null) {
                        $reservationResult['location_id'] = $itemLocation->location_id;
                        $reservationResult['real_stock_id'] = $itemLocation->real_stock_id;
                    }
                    $pickingAreaId = $pickingAreaId ?? $itemLocation->wms_picking_area_id;
                    $floorId = $floorId ?? $itemLocation->floor_id;
                } else {
                    $defaultArea = $defaultAreaCache[$waveSetting->warehouse_id] ??= DB::connection('sakemaru')
                        ->table('wms_picking_areas')
                        ->where('warehouse_id', $waveSetting->warehouse_id)
                        ->where('is_active', true)
                        ->orderBy('display_order', 'asc')
                        ->first();
                    $pickingAreaId = $pickingAreaId ?? ($defaultArea->id ?? null);
                }
            }

            $existingTask = DB::connection('sakemaru')
                ->table('wms_picking_tasks')
                ->where('wave_id', $wave->id)
                ->where('floor_id', $floorId)
                ->first();

            if ($existingTask) {
                $pickingTaskId = $existingTask->id;
            } else {
                $pickingTaskId = DB::connection('sakemaru')->table('wms_picking_tasks')->insertGetId([
                    'wave_id' => $wave->id,
                    'wms_picking_area_id' => $pickingAreaId,
                    'warehouse_id' => $waveSetting->warehouse_id,
                    'warehouse_code' => $warehouse->code,
                    'floor_id' => $floorId,
                    'delivery_course_id' => $waveSetting->delivery_course_id,
                    'delivery_course_code' => $course->code,
                    'shipment_date' => $shippingDate,
                    'status' => 'PENDING',
                    'task_type' => 'WAVE',
                    'picker_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if (! $tradeItem->quantity_type) {
                throw new \RuntimeException("quantity_type must be specified for trade_item ID {$tradeItem->id}");
            }

            DB::connection('sakemaru')->table('wms_picking_item_results')->insert([
                'picking_task_id' => $pickingTaskId,
                'earning_id' => null,
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
                'shortage_qty' => $reservationResult['shortage_qty'] ?? 0,
                'status' => 'PENDING',
                'picker_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::connection('sakemaru')
            ->table('stock_transfers')
            ->whereIn('id', $stockTransferIds)
            ->update([
                'picking_status' => 'BEFORE_PICKING',
                'updated_at' => now(),
            ]);
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

    protected function getEligibleStockTransfersQuery(string|array $shippingDate, int $warehouseId, bool $includePast = false)
    {
        $shippingDates = $this->normalizeShippingDates($shippingDate);
        $warehouseIds = WarehouseResolver::resolveAllWarehouseIds($warehouseId);

        $query = DB::connection('sakemaru')
            ->table('stock_transfers as st')
            ->join('trades as st_trade', 'st.trade_id', '=', 'st_trade.id')
            ->join('delivery_courses as dc', 'st.delivery_course_id', '=', 'dc.id')
            ->join('warehouses as fw', 'st.from_warehouse_id', '=', 'fw.id')
            ->join('warehouses as tw', 'st.to_warehouse_id', '=', 'tw.id')
            ->where('st.is_active', true)
            ->where('st_trade.is_active', true)
            ->where('st.picking_status', 'BEFORE')
            ->where('st.is_delivered', false)
            ->where('st.is_confirmed', false)
            ->whereIn('st.from_warehouse_id', $warehouseIds)
            ->whereIn('dc.warehouse_id', $warehouseIds)
            ->whereNotNull('st.delivery_course_id')
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->where('fw.is_virtual', false)
                        ->orWhere('tw.is_virtual', false);
                })
                    ->where(function ($q) {
                        $q->whereRaw('COALESCE(fw.stock_warehouse_id, fw.id) != COALESCE(tw.stock_warehouse_id, tw.id)');
                    });
            })
            ->select('st.*');

        return $this->applyRawDateFilter($query, $this->stockTransferPickingDateExpression(), $shippingDates, $includePast);
    }

    private function normalizeTargetDocumentTypes(mixed $targetDocumentTypes): array
    {
        if (! is_array($targetDocumentTypes)) {
            return ['shipment', 'transfer'];
        }

        return array_values(array_intersect($targetDocumentTypes, ['shipment', 'transfer']));
    }

    private function includesShipmentDocuments(array $targetDocumentTypes): bool
    {
        return in_array('shipment', $targetDocumentTypes, true);
    }

    private function includesTransferDocuments(array $targetDocumentTypes): bool
    {
        return in_array('transfer', $targetDocumentTypes, true);
    }

    private function normalizeShippingDates(mixed $shippingDates): array
    {
        if (! is_array($shippingDates)) {
            $shippingDates = $shippingDates ? [$shippingDates] : [];
        }

        return collect($shippingDates)
            ->filter(fn ($date): bool => is_string($date) && $date !== '')
            ->map(function (string $date): ?string {
                try {
                    return Carbon::parse($date)->format('Y-m-d');
                } catch (\Throwable) {
                    return null;
                }
            })
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function latestShippingDate(array $shippingDates): ?string
    {
        return empty($shippingDates) ? null : max($shippingDates);
    }

    private function applyDateFilter($query, string $column, array $shippingDates, bool $includePast)
    {
        if (empty($shippingDates)) {
            return $query->whereRaw('1 = 0');
        }

        if ($includePast) {
            return $query->where($column, '<=', $this->latestShippingDate($shippingDates));
        }

        return count($shippingDates) === 1
            ? $query->where($column, $shippingDates[0])
            : $query->whereIn($column, $shippingDates);
    }

    private function applyRawDateFilter($query, string $expression, array $shippingDates, bool $includePast)
    {
        if (empty($shippingDates)) {
            return $query->whereRaw('1 = 0');
        }

        if ($includePast) {
            return $query->whereRaw("{$expression} <= ?", [$this->latestShippingDate($shippingDates)]);
        }

        return $query->where(function ($query) use ($expression, $shippingDates) {
            foreach ($shippingDates as $shippingDate) {
                $query->orWhereRaw("{$expression} = ?", [$shippingDate]);
            }
        });
    }

    private function stockTransferPickingDateExpression(): string
    {
        return 'COALESCE(st.picking_date, st.delivered_date)';
    }

    private function elapsedMilliseconds(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
