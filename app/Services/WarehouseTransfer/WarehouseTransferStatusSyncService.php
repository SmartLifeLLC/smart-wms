<?php

namespace App\Services\WarehouseTransfer;

use App\Enums\WarehouseTransferCandidateStatus;
use App\Models\WmsWarehouseTransferCandidate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * stock_transfer_queue の処理結果を候補へ投影する
 *
 * | queue状態                | candidate       |
 * |--------------------------|-----------------|
 * | BEFORE / PROCESSING      | CONFIRMED のまま |
 * | FINISHED + is_success=1  | EXECUTED        |
 * | FINISHED + is_success=0  | FAILED          |
 */
class WarehouseTransferStatusSyncService
{
    /**
     * 確定済み（CONFIRMED / FAILED）候補の queue 状態を同期
     *
     * @return int 更新件数
     */
    public function syncAll(): int
    {
        $updated = 0;

        WmsWarehouseTransferCandidate::query()
            ->whereIn('status', [
                WarehouseTransferCandidateStatus::CONFIRMED->value,
                WarehouseTransferCandidateStatus::FAILED->value,
            ])
            ->whereNotNull('queue_request_id')
            ->chunkById(200, function (Collection $candidates) use (&$updated) {
                $queues = $this->queuesByRequestId($candidates->pluck('queue_request_id')->all());

                foreach ($candidates as $candidate) {
                    if ($this->apply($candidate, $queues[$candidate->queue_request_id] ?? null)) {
                        $updated++;
                    }
                }
            });

        return $updated;
    }

    /**
     * 単一候補の同期（詳細画面表示時など）
     */
    public function sync(WmsWarehouseTransferCandidate $candidate): bool
    {
        if (! $candidate->queue_request_id) {
            return false;
        }

        $queues = $this->queuesByRequestId([$candidate->queue_request_id]);

        return $this->apply($candidate, $queues[$candidate->queue_request_id] ?? null);
    }

    /**
     * @return array<string, object> request_id => queue row
     */
    public function queuesByRequestId(array $requestIds): array
    {
        $requestIds = array_values(array_filter(array_unique($requestIds)));

        if ($requestIds === []) {
            return [];
        }

        return DB::connection('sakemaru')
            ->table('stock_transfer_queue')
            ->whereIn('request_id', $requestIds)
            ->where('action_type', 'CREATE')
            ->get(['id', 'request_id', 'status', 'is_success', 'error_message', 'stock_transfer_id', 'updated_at'])
            ->keyBy('request_id')
            ->all();
    }

    private function apply(WmsWarehouseTransferCandidate $candidate, ?object $queue): bool
    {
        if (! $queue || $queue->status !== 'FINISHED') {
            return false;
        }

        $success = (int) ($queue->is_success ?? 0) === 1;
        $newStatus = $success ? WarehouseTransferCandidateStatus::EXECUTED : WarehouseTransferCandidateStatus::FAILED;

        $changes = [];
        if ($candidate->status !== $newStatus) {
            $changes['status'] = $newStatus;
        }
        if ($success && (int) $candidate->stock_transfer_id !== (int) $queue->stock_transfer_id) {
            $changes['stock_transfer_id'] = $queue->stock_transfer_id;
        }
        if (! $success && $candidate->queue_error_message !== $queue->error_message) {
            $changes['queue_error_message'] = $queue->error_message;
        }
        if ((int) $candidate->stock_transfer_queue_id !== (int) $queue->id) {
            $changes['stock_transfer_queue_id'] = $queue->id;
        }

        if ($changes === []) {
            return false;
        }

        $candidate->update($changes);

        return true;
    }
}
