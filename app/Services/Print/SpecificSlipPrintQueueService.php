<?php

namespace App\Services\Print;

use App\Models\SpecificSlipPrintRequestQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SpecificSlipPrintQueueService
{
    private const RECENT_COMPLETED_DUPLICATE_WINDOW_SECONDS = 5;

    public function createForContinuouslyPrintableGroups(Collection $groups): array
    {
        return $this->createForGroups(
            $groups
                ->filter(fn (array $group) => ($group['can_print'] ?? false) && ($group['can_print_continuously'] ?? true))
                ->values(),
            []
        );
    }

    public function createForGroups(Collection $groups, array|bool $paperConfirmations = []): array
    {
        $result = [
            'created_count' => 0,
            'already_queued_count' => 0,
            'skipped_count' => 0,
            'queued_ids' => [],
            'messages' => [],
        ];

        if ($groups->isEmpty()) {
            return $result;
        }

        return DB::connection('sakemaru')->transaction(function () use ($groups, $paperConfirmations, $result) {
            foreach ($groups as $group) {
                if (! ($group['can_print'] ?? false)) {
                    $result['skipped_count']++;
                    $result['messages'][] = $group['slip_type_name'].': '.($group['disabled_reason'] ?? '印刷不可');

                    continue;
                }

                $earningIds = $this->normalizedEarningIds($group);
                if (empty($earningIds)) {
                    $result['skipped_count']++;
                    $result['messages'][] = $group['slip_type_name'].': 印刷対象なし';

                    continue;
                }

                DB::connection('sakemaru')
                    ->table('earnings')
                    ->whereIn('id', $earningIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->pluck('id');

                $existing = $this->findDuplicateQueue(
                    (int) $group['warehouse_id'],
                    (int) $group['slip_type_id'],
                    $earningIds,
                );

                if ($existing) {
                    $result['already_queued_count']++;
                    $result['queued_ids'][] = $existing->id;

                    continue;
                }

                if (($group['requires_paper_confirmation'] ?? false) && ! $this->isPaperConfirmed($group, $paperConfirmations)) {
                    $result['skipped_count']++;
                    $result['messages'][] = $group['slip_type_name'].': 用紙セット確認が未完了';

                    continue;
                }

                $queue = SpecificSlipPrintRequestQueue::create([
                    'client_id' => (int) $group['client_id'],
                    'warehouse_id' => (int) $group['warehouse_id'],
                    'slip_type_id' => (int) $group['slip_type_id'],
                    'earning_ids' => $earningIds,
                    'status' => SpecificSlipPrintRequestQueue::STATUS_PENDING,
                    'requested_by' => Auth::id(),
                    'idempotency_key' => $this->buildIdempotencyKey($group, $earningIds),
                ]);

                $result['created_count']++;
                $result['queued_ids'][] = $queue->id;

                Log::info('Specific slip print queue created from WMS', [
                    'queue_id' => $queue->id,
                    'warehouse_id' => $group['warehouse_id'],
                    'slip_type_id' => $group['slip_type_id'],
                    'printer_key' => $group['printer_key'] ?? null,
                    'earning_ids' => $earningIds,
                ]);
            }

            return $result;
        });
    }

    public function annotateGroupsWithQueueStatus(Collection $groups): Collection
    {
        if ($groups->isEmpty()) {
            return $groups;
        }

        $keys = $groups
            ->map(fn (array $group) => $this->buildGroupIdempotencyKey($group))
            ->filter()
            ->unique()
            ->values();

        if ($keys->isEmpty()) {
            return $groups;
        }

        $queues = SpecificSlipPrintRequestQueue::query()
            ->whereIn('idempotency_key', $keys->all())
            ->orderByDesc('id')
            ->get()
            ->unique('idempotency_key')
            ->keyBy('idempotency_key');

        return $groups
            ->map(function (array $group) use ($queues) {
                $idempotencyKey = $this->buildGroupIdempotencyKey($group);
                $queue = $idempotencyKey ? $queues->get($idempotencyKey) : null;

                $group['queue_id'] = $queue?->id;
                $group['queue_status'] = $queue?->status;
                $group['queue_processed_at'] = $queue?->processed_at;

                return $group;
            })
            ->values();
    }

    public function countIncompleteTargets(Collection $groups): int
    {
        return $this->annotateGroupsWithQueueStatus($groups)
            ->reject(fn (array $group) => ($group['queue_status'] ?? null) === SpecificSlipPrintRequestQueue::STATUS_COMPLETED)
            ->sum(fn (array $group) => max(1, (int) ($group['earning_count'] ?? count($group['earning_ids'] ?? []))));
    }

    public function buildGroupIdempotencyKey(array $group): ?string
    {
        $earningIds = $this->normalizedEarningIds($group);
        if (empty($earningIds)) {
            return null;
        }

        return $this->buildIdempotencyKey($group, $earningIds);
    }

    private function findDuplicateQueue(int $warehouseId, int $slipTypeId, array $earningIds): ?SpecificSlipPrintRequestQueue
    {
        $duplicateWindowStart = now()->subSeconds(self::RECENT_COMPLETED_DUPLICATE_WINDOW_SECONDS);

        $queues = SpecificSlipPrintRequestQueue::query()
            ->where('warehouse_id', $warehouseId)
            ->where('slip_type_id', $slipTypeId)
            ->where(function ($query) use ($duplicateWindowStart) {
                $query->whereIn('status', [
                    SpecificSlipPrintRequestQueue::STATUS_PENDING,
                    SpecificSlipPrintRequestQueue::STATUS_PROCESSING,
                ])
                    ->orWhere(function ($query) use ($duplicateWindowStart) {
                        $query->where('status', SpecificSlipPrintRequestQueue::STATUS_COMPLETED)
                            ->where(function ($query) use ($duplicateWindowStart) {
                                $query->where('processed_at', '>=', $duplicateWindowStart)
                                    ->orWhere(function ($query) use ($duplicateWindowStart) {
                                        $query->whereNull('processed_at')
                                            ->where('created_at', '>=', $duplicateWindowStart);
                                    });
                            });
                    });
            })
            ->lockForUpdate()
            ->get();

        foreach ($queues as $queue) {
            if ($this->sameIdSet($queue->earning_ids ?? [], $earningIds)) {
                return $queue;
            }
        }

        return null;
    }

    private function sameIdSet(array $left, array $right): bool
    {
        $left = array_values(array_unique(array_map('intval', $left)));
        $right = array_values(array_unique(array_map('intval', $right)));
        sort($left);
        sort($right);

        return $left === $right;
    }

    private function normalizedEarningIds(array $group): array
    {
        return array_values(array_unique(array_map('intval', $group['earning_ids'] ?? [])));
    }

    private function buildIdempotencyKey(array $group, array $earningIds): string
    {
        $hash = substr(sha1(implode(',', $earningIds)), 0, 16);

        return implode(':', [
            'wms-specific-slip',
            $group['warehouse_id'],
            $group['slip_type_id'],
            $hash,
        ]);
    }

    private function isPaperConfirmed(array $group, array|bool $paperConfirmations): bool
    {
        if (is_bool($paperConfirmations)) {
            return $paperConfirmations;
        }

        $confirmationKey = $group['confirmation_key'] ?? null;
        if (! $confirmationKey) {
            return false;
        }

        return (bool) data_get($paperConfirmations, $confirmationKey, false);
    }
}
