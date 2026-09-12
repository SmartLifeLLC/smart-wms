<?php

namespace App\Services\WarehouseTransfer;

use App\Enums\WarehouseTransferCandidateStatus;
use App\Models\WmsWarehouseTransferCandidate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * 倉庫移動候補の確定 → 基幹 stock_transfer_queue (action_type=CREATE) 投入
 *
 * - request_id = wms-warehouse-transfer-{candidate_id} で冪等
 * - 在庫不足は確定ブロックせず警告のみ（最終判定は基幹側）
 * - quantity_type は PIECE 固定（総バラ数を渡す）
 */
class WarehouseTransferQueueService
{
    public function __construct(
        private readonly WarehouseTransferStockListService $stockListService,
    ) {}

    /**
     * 確定前チェック（モーダル表示用）
     *
     * @return array{
     *   ok: bool,
     *   errors: array<int, string>,
     *   warnings: array<int, string>,
     *   delivery_course_id: int|null,
     *   item_count: int,
     *   total_quantity: float,
     *   shortages: array<int, array{item_code:string, item_name:string, transfer_quantity:float, available_quantity:float}>
     * }
     */
    public function validateForConfirm(WmsWarehouseTransferCandidate $candidate): array
    {
        $errors = [];
        $warnings = [];
        $shortages = [];

        if (! $candidate->isPending()) {
            $errors[] = 'この候補は未確定ではありません（'.$candidate->status_label.'）';
        }

        $items = $candidate->items()->get();

        if ($items->isEmpty()) {
            $errors[] = '明細が1件もありません';
        }

        foreach ($items as $item) {
            if ((float) $item->transfer_quantity <= 0) {
                $errors[] = "商品CD {$item->item_code} の移動数が0以下です";
            }
        }

        $fromWarehouse = $this->warehouse((int) $candidate->from_warehouse_id);
        $toWarehouse = $this->warehouse((int) $candidate->to_warehouse_id);

        if (! $fromWarehouse) {
            $errors[] = '移動元倉庫が存在しません';
        }
        if (! $toWarehouse) {
            $errors[] = '移動先倉庫が存在しません';
        }
        if ($fromWarehouse && $toWarehouse && (int) $fromWarehouse->id === (int) $toWarehouse->id) {
            $errors[] = '移動元と移動先が同一です';
        }

        $deliveryCourseId = $this->resolveDeliveryCourseId($candidate);
        if ($deliveryCourseId === null) {
            $errors[] = '配送コースが解決できません。詳細画面で配送コースを選択してください';
        }

        // 商品 / 在庫区分の検証
        $allocationCodes = $items->pluck('stock_allocation_code')->unique()->values()->all();
        $validAllocationCodes = $allocationCodes === []
            ? []
            : DB::connection('sakemaru')
                ->table('stock_allocations')
                ->whereIn('code', $allocationCodes)
                ->pluck('code')
                ->map(fn ($code) => (string) $code)
                ->all();

        foreach ($allocationCodes as $code) {
            if (! in_array((string) $code, $validAllocationCodes, true)) {
                $errors[] = "在庫区分CD {$code} が基幹マスタに存在しません";
            }
        }

        $itemIds = $items->pluck('item_id')->unique()->values()->all();
        $activeItemIds = $itemIds === []
            ? []
            : DB::connection('sakemaru')
                ->table('items')
                ->whereIn('id', $itemIds)
                ->where('is_active', 1)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

        foreach ($items as $item) {
            if (! in_array((int) $item->item_id, $activeItemIds, true)) {
                $errors[] = "商品CD {$item->item_code} が基幹マスタで無効です";
            }
        }

        // 在庫不足チェック（警告のみ）
        if ($fromWarehouse && $itemIds !== []) {
            foreach ($items->groupBy('stock_allocation_code') as $allocationCode => $groupItems) {
                $available = $this->stockListService->availableQuantityByItem(
                    (int) $candidate->from_warehouse_id,
                    $groupItems->pluck('item_id')->all(),
                    (string) $allocationCode,
                );

                foreach ($groupItems as $item) {
                    $availableQty = (float) ($available[(int) $item->item_id] ?? 0);
                    if ((float) $item->transfer_quantity > $availableQty) {
                        $shortages[] = [
                            'item_id' => (int) $item->item_id,
                            'item_code' => $item->item_code,
                            'item_name' => $item->item_name,
                            'transfer_quantity' => (float) $item->transfer_quantity,
                            'available_quantity' => $availableQty,
                        ];
                    }
                }
            }
        }

        if ($shortages !== []) {
            $warnings[] = count($shortages).'件の明細で移動元の利用可能在庫が不足しています（確定は可能ですが、基幹側で在庫がマイナスになる可能性があります）';
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'delivery_course_id' => $deliveryCourseId,
            'item_count' => $items->count(),
            'total_quantity' => (float) $items->sum('transfer_quantity'),
            'shortages' => $shortages,
        ];
    }

    /**
     * 確定して queue 投入
     *
     * @return int stock_transfer_queue.id
     */
    public function enqueue(WmsWarehouseTransferCandidate $candidate, ?int $confirmedBy = null): int
    {
        return DB::connection('sakemaru')->transaction(function () use ($candidate, $confirmedBy) {
            /** @var WmsWarehouseTransferCandidate $locked */
            $locked = WmsWarehouseTransferCandidate::query()
                ->whereKey($candidate->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isPending()) {
                if ($locked->stock_transfer_queue_id) {
                    return (int) $locked->stock_transfer_queue_id;
                }

                throw new RuntimeException('この候補は未確定ではありません（'.$locked->status_label.'）');
            }

            $validation = $this->validateForConfirm($locked);
            if (! $validation['ok']) {
                throw new RuntimeException(implode("\n", $validation['errors']));
            }

            $items = $locked->items()->lockForUpdate()->get();
            $deliveryCourseId = $validation['delivery_course_id'];

            // 確定時点の利用可能在庫を保存しつつ queue items を組み立てる
            $queueItems = [];
            foreach ($items as $item) {
                $available = $this->stockListService->availableQuantityByItem(
                    (int) $locked->from_warehouse_id,
                    [(int) $item->item_id],
                    (string) $item->stock_allocation_code,
                );
                $item->update(['available_quantity_at_confirm' => (float) ($available[(int) $item->item_id] ?? 0)]);

                $queueItems[] = [
                    'item_code' => (string) $item->item_code,
                    'quantity' => (float) $item->transfer_quantity,
                    'quantity_type' => 'PIECE',
                    'stock_allocation_code' => (string) $item->stock_allocation_code,
                    'note' => "WMS倉庫移動候補ID: {$locked->id} / 明細ID: {$item->id}",
                ];
            }

            $requestId = $locked->getQueueRequestIdForCandidate();

            $queueData = [
                'client_id' => (int) config('app.client_id'),
                'slip_number' => null,
                'process_date' => $locked->process_date->format('Y-m-d'),
                'delivered_date' => $locked->delivered_date->format('Y-m-d'),
                'note' => "WMS倉庫移動候補: {$locked->candidate_no}",
                'items' => json_encode($queueItems, JSON_UNESCAPED_UNICODE),
                'from_warehouse_code' => $locked->from_warehouse_code,
                'to_warehouse_code' => $locked->to_warehouse_code,
                'delivery_course_id' => $deliveryCourseId,
                'request_id' => $requestId,
                'status' => 'BEFORE',
                'action_type' => 'CREATE',
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $existingQueue = DB::connection('sakemaru')
                ->table('stock_transfer_queue')
                ->where('request_id', $requestId)
                ->where('action_type', 'CREATE')
                ->lockForUpdate()
                ->first();

            if ($existingQueue) {
                if ($existingQueue->status === 'BEFORE') {
                    DB::connection('sakemaru')
                        ->table('stock_transfer_queue')
                        ->where('id', $existingQueue->id)
                        ->update(array_merge($queueData, ['created_at' => $existingQueue->created_at]));
                }
                $queueId = (int) $existingQueue->id;
            } else {
                $queueId = (int) DB::connection('sakemaru')->table('stock_transfer_queue')->insertGetId($queueData);
            }

            $locked->update([
                'status' => WarehouseTransferCandidateStatus::CONFIRMED,
                'delivery_course_id' => $deliveryCourseId,
                'confirmed_by' => $confirmedBy,
                'confirmed_at' => now(),
                'queue_request_id' => $requestId,
                'stock_transfer_queue_id' => $queueId,
                'queue_error_message' => null,
            ]);

            Log::info('Warehouse transfer candidate confirmed and enqueued', [
                'candidate_id' => $locked->id,
                'candidate_no' => $locked->candidate_no,
                'queue_id' => $queueId,
                'request_id' => $requestId,
                'from_warehouse' => $locked->from_warehouse_code,
                'to_warehouse' => $locked->to_warehouse_code,
                'delivery_course_id' => $deliveryCourseId,
                'item_count' => count($queueItems),
                'confirmed_by' => $confirmedBy,
                'shortage_count' => count($validation['shortages']),
            ]);

            return $queueId;
        });
    }

    /**
     * 失敗した queue を再投入可能な状態に戻す
     */
    public function retry(WmsWarehouseTransferCandidate $candidate, ?int $userId = null): void
    {
        DB::connection('sakemaru')->transaction(function () use ($candidate, $userId) {
            $locked = WmsWarehouseTransferCandidate::query()
                ->whereKey($candidate->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== WarehouseTransferCandidateStatus::FAILED) {
                throw new RuntimeException('伝票作成失敗の候補のみ再投入できます');
            }

            $queue = $locked->stock_transfer_queue_id
                ? DB::connection('sakemaru')->table('stock_transfer_queue')->where('id', $locked->stock_transfer_queue_id)->lockForUpdate()->first()
                : null;

            if ($queue && $queue->status === 'FINISHED' && ! (int) $queue->is_success && ! $queue->stock_transfer_id) {
                DB::connection('sakemaru')
                    ->table('stock_transfer_queue')
                    ->where('id', $queue->id)
                    ->update([
                        'status' => 'BEFORE',
                        'is_success' => null,
                        'error_message' => null,
                        'retry_count' => 0,
                        'next_retry_at' => null,
                        'updated_at' => now(),
                    ]);
            } elseif (! $queue) {
                throw new RuntimeException('再投入対象の queue が見つかりません');
            } else {
                throw new RuntimeException('この queue は再投入できる状態ではありません');
            }

            $locked->update([
                'status' => WarehouseTransferCandidateStatus::CONFIRMED,
                'queue_error_message' => null,
            ]);

            Log::info('Warehouse transfer candidate queue retried', [
                'candidate_id' => $locked->id,
                'queue_id' => $queue->id,
                'user_id' => $userId,
            ]);
        });
    }

    /**
     * 取消（PENDING のみ）
     */
    public function cancel(WmsWarehouseTransferCandidate $candidate, ?int $userId = null): void
    {
        DB::connection('sakemaru')->transaction(function () use ($candidate, $userId) {
            $locked = WmsWarehouseTransferCandidate::query()
                ->whereKey($candidate->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isPending()) {
                throw new RuntimeException('未確定の候補のみ取消できます');
            }

            $locked->update([
                'status' => WarehouseTransferCandidateStatus::CANCELLED,
                'cancelled_by' => $userId,
                'cancelled_at' => now(),
            ]);
        });
    }

    /**
     * 配送コース解決: マスタ設定 → 候補の手動選択
     */
    public function resolveDeliveryCourseId(WmsWarehouseTransferCandidate $candidate): ?int
    {
        $fromMaster = DB::connection('sakemaru')
            ->table('warehouse_stock_transfer_delivery_courses')
            ->where('from_warehouse_id', $candidate->from_warehouse_id)
            ->where('to_warehouse_id', $candidate->to_warehouse_id)
            ->whereNotNull('delivery_course_id')
            ->value('delivery_course_id');

        if ($fromMaster) {
            return (int) $fromMaster;
        }

        return $candidate->delivery_course_id ? (int) $candidate->delivery_course_id : null;
    }

    private function warehouse(int $warehouseId): ?object
    {
        return DB::connection('sakemaru')
            ->table('warehouses')
            ->where('id', $warehouseId)
            ->first(['id', 'code', 'name']);
    }
}
