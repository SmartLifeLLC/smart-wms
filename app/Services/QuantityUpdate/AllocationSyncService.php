<?php

namespace App\Services\QuantityUpdate;

use App\Models\QuantityUpdateQueue;
use App\Models\Sakemaru\ClientSetting;
use App\Models\WmsShortageAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 横持ち出荷欠品のai-core同期サービス
 * 欠品単位でquantity_update_queueに最終数量を作成
 */
class AllocationSyncService
{
    /**
     * 指定倉庫の未同期欠品allocationを欠品単位で同期
     *
     * @return array{syncedCount: int, queueCreated: int, skipped: int, errors: array, synced_count: int, queue_created: int}
     */
    public function syncByWarehouse(int $warehouseId, ?string $shipmentDate = null): array
    {
        $allocations = WmsShortageAllocation::needingSync()
            ->where('target_warehouse_id', $warehouseId)
            ->when($shipmentDate, fn ($query) => $query->where('shipment_date', $shipmentDate))
            ->with(['shortage.trade', 'shortage.wave', 'shortage.allocations'])
            ->get();

        if ($allocations->isEmpty()) {
            return $this->formatResult(0, 0, 0, []);
        }

        $grouped = $allocations->groupBy('shortage_id');

        $syncedCount = 0;
        $queueCreated = 0;
        $skipped = 0;
        $errors = [];

        DB::connection('sakemaru')->transaction(function () use ($grouped, $shipmentDate, &$syncedCount, &$queueCreated, &$skipped, &$errors) {
            $queueService = app(QuantityUpdateQueueService::class);

            foreach ($grouped as $shortageId => $groupAllocations) {
                try {
                    $result = $this->syncShortageIdIfReady((int) $shortageId, $queueService, $shipmentDate);
                    if ($result) {
                        $queueCreated++;
                        $syncedCount += $groupAllocations->count();
                    } else {
                        $skipped += $groupAllocations->count();
                    }
                } catch (\Exception $e) {
                    $errors[] = "欠品ID {$shortageId}: {$e->getMessage()}";
                    Log::error('AllocationSyncService: group sync failed', [
                        'shortage_id' => $shortageId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        Log::info('AllocationSyncService: sync completed', [
            'warehouse_id' => $warehouseId,
            'shipment_date' => $shipmentDate,
            'synced_count' => $syncedCount,
            'queue_created' => $queueCreated,
            'skipped' => $skipped,
        ]);

        return $this->formatResult($syncedCount, $queueCreated, $skipped, $errors);
    }

    /**
     * 完了した代理側欠品を、同じ欠品IDの横持ち割当がすべて完了していれば同期する。
     */
    public function syncAllocationIfReady(WmsShortageAllocation $allocation): ?QuantityUpdateQueue
    {
        if (! $allocation->shortage_id) {
            return null;
        }

        $systemShipmentDate = $this->systemShipmentDate();

        return DB::connection('sakemaru')->transaction(function () use ($allocation, $systemShipmentDate): ?QuantityUpdateQueue {
            WmsShortageAllocation::query()
                ->where('shortage_id', $allocation->shortage_id)
                ->lockForUpdate()
                ->get(['id']);

            $lockedAllocation = WmsShortageAllocation::query()
                ->whereKey($allocation->getKey())
                ->first();

            if (! $lockedAllocation?->needsSync()) {
                return null;
            }

            if (! $systemShipmentDate || ! $this->matchesShipmentDate($lockedAllocation, $systemShipmentDate)) {
                Log::info('AllocationSyncService: auto sync skipped because shipment date is not system date', [
                    'allocation_id' => $lockedAllocation->id,
                    'shortage_id' => $lockedAllocation->shortage_id,
                    'shipment_date' => $lockedAllocation->shipment_date?->toDateString(),
                    'system_shipment_date' => $systemShipmentDate,
                ]);

                return null;
            }

            return $this->syncShortageIdIfReady(
                (int) $lockedAllocation->shortage_id,
                app(QuantityUpdateQueueService::class),
                $systemShipmentDate,
            );
        });
    }

    protected function syncShortageIdIfReady(int $shortageId, QuantityUpdateQueueService $queueService, ?string $shipmentDate = null): ?QuantityUpdateQueue
    {
        WmsShortageAllocation::query()
            ->where('shortage_id', $shortageId)
            ->lockForUpdate()
            ->get(['id']);

        $allocations = WmsShortageAllocation::needingSync()
            ->where('shortage_id', $shortageId)
            ->when($shipmentDate, fn ($query) => $query->where('shipment_date', $shipmentDate))
            ->with(['shortage.trade', 'shortage.wave', 'shortage.allocations'])
            ->get();

        if ($allocations->isEmpty()) {
            return null;
        }

        if ($this->hasUnfinishedAllocation($shortageId, $shipmentDate)) {
            Log::info('AllocationSyncService: shortage group sync skipped because allocations remain unfinished', [
                'shortage_id' => $shortageId,
                'shipment_date' => $shipmentDate,
                'allocation_ids' => $allocations->pluck('id')->sort()->values()->all(),
            ]);

            return null;
        }

        return $this->syncShortageGroup($allocations, $queueService);
    }

    /**
     * 欠品単位でqueue作成 & allocation同期済み更新
     */
    protected function syncShortageGroup($allocations, QuantityUpdateQueueService $queueService): ?QuantityUpdateQueue
    {
        $first = $allocations->first();
        $shortage = $first->shortage;

        if (! $shortage) {
            Log::warning('AllocationSyncService: shortage not found', ['allocation_id' => $first->id]);

            return null;
        }

        $allocationIds = $allocations->pluck('id')->sort()->values()->all();
        $requestHash = substr(sha1(implode(',', $allocationIds)), 0, 12);
        $requestId = "proxy-shortage-final-{$shortage->id}-{$requestHash}";

        $queue = $queueService->createQueueForAllocationSync($shortage, $requestId);
        if (! $queue) {
            return null;
        }

        // allocation を同期済みに更新
        $this->markAllocationsAsSynced($allocations);

        Log::info('AllocationSyncService: queue created for shortage group', [
            'queue_id' => $queue->id,
            'request_id' => $requestId,
            'shortage_id' => $shortage->id,
            'update_qty' => $queue->update_qty,
            'allocation_count' => $allocations->count(),
            'allocation_ids' => $allocationIds,
        ]);

        return $queue;
    }

    protected function hasUnfinishedAllocation(int $shortageId, ?string $shipmentDate = null): bool
    {
        return WmsShortageAllocation::query()
            ->where('shortage_id', $shortageId)
            ->when($shipmentDate, fn ($query) => $query->where('shipment_date', $shipmentDate))
            ->where('is_finished', false)
            ->where(function ($query): void {
                $query->whereNull('status')
                    ->orWhere('status', '!=', WmsShortageAllocation::STATUS_CANCELLED);
            })
            ->exists();
    }

    protected function markAllocationsAsSynced($allocations): void
    {
        $ids = $allocations->pluck('id')->toArray();
        WmsShortageAllocation::whereIn('id', $ids)->update([
            'is_synced' => true,
            'is_synced_at' => now(),
        ]);
    }

    /**
     * 指定倉庫の未同期件数を取得
     */
    public function getUnsyncedCount(int $warehouseId, ?string $shipmentDate = null): int
    {
        return WmsShortageAllocation::needingSync()
            ->where('target_warehouse_id', $warehouseId)
            ->when($shipmentDate, fn ($query) => $query->where('shipment_date', $shipmentDate))
            ->count();
    }

    protected function systemShipmentDate(): ?string
    {
        return ClientSetting::systemDate(true)?->toDateString();
    }

    protected function matchesShipmentDate(WmsShortageAllocation $allocation, string $shipmentDate): bool
    {
        $allocation->loadMissing('shortage');

        if ($allocation->shipment_date?->toDateString() !== $shipmentDate) {
            return false;
        }

        $shortageShipmentDate = $allocation->shortage?->shipment_date?->toDateString();

        return $shortageShipmentDate === null || $shortageShipmentDate === $shipmentDate;
    }

    /**
     * @return array{syncedCount: int, queueCreated: int, skipped: int, errors: array, synced_count: int, queue_created: int}
     */
    protected function formatResult(int $syncedCount, int $queueCreated, int $skipped, array $errors): array
    {
        return [
            'syncedCount' => $syncedCount,
            'queueCreated' => $queueCreated,
            'skipped' => $skipped,
            'errors' => $errors,
            'synced_count' => $syncedCount,
            'queue_created' => $queueCreated,
        ];
    }
}
