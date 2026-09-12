<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\AutoOrder\OrderSource;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Item;
use App\Models\WmsOrderIncomingSchedule;
use App\Models\WmsStockTransferCandidate;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 移動候補確定サービス
 *
 * 承認済みの移動候補を確定し、stock_transfer_queueを作成する
 * 基幹システム（sakemaru-ai-core）が stock_transfer_queue を処理して
 * 実際の移動伝票（stock_transfers）を生成する
 *
 * 新フロー:
 * - stock_transfer_queue (action_type=CREATE) を作成
 * - WmsOrderIncomingSchedule を作成（Satellite入荷予定）
 * - Hub在庫の予約は行わない（stock_transfer作成時に減算）
 */
class TransferCandidateExecutionService
{
    /**
     * @var array<string, int>|null
     */
    private ?array $transferDeliveryCourseMap = null;

    /**
     * @var array<int, int|null>
     */
    private array $resolvedDeliveryCourseIds = [];

    /**
     * 移動候補を確定
     * - stock_transfer_queue を作成 (action_type=CREATE)
     * - WmsOrderIncomingSchedule を作成（Satellite入荷予定）
     * ※ Hub在庫の予約は行わない（stock_transfer作成時に減算）
     *
     * @param  WmsStockTransferCandidate  $candidate  移動候補
     * @param  int  $executedBy  確定者ID
     * @return array{queue_id: int, incoming_schedule_id: int}
     */
    public function executeCandidate(WmsStockTransferCandidate $candidate, int $executedBy): array
    {
        if ($candidate->status !== CandidateStatus::APPROVED) {
            throw new \RuntimeException(
                "Candidate {$candidate->id} must be APPROVED before execution. Current status: {$candidate->status->value}"
            );
        }

        return DB::connection('sakemaru')->transaction(function () use ($candidate, $executedBy) {
            // 1. stock_transfer_queue を作成 (action_type=CREATE)
            $queueId = $this->createStockTransferQueue($candidate);

            // 2. WmsOrderIncomingSchedule を作成（Satellite入荷予定）
            $schedule = $this->createIncomingSchedule($candidate);

            // 3. 候補ステータスを更新
            $candidate->update([
                'delivery_course_id' => $this->resolvedDeliveryCourseId($candidate),
                'status' => CandidateStatus::EXECUTED,
                'modified_by' => $executedBy,
                'modified_at' => now(),
            ]);

            Log::info('Transfer candidate executed', [
                'candidate_id' => $candidate->id,
                'queue_id' => $queueId,
                'incoming_schedule_id' => $schedule->id,
                'hub_warehouse_id' => $candidate->hub_warehouse_id,
                'satellite_warehouse_id' => $candidate->satellite_warehouse_id,
                'item_id' => $candidate->item_id,
                'transfer_quantity' => $candidate->transfer_quantity,
            ]);

            return [
                'queue_id' => $queueId,
                'incoming_schedule_id' => $schedule->id,
            ];
        });
    }

    /**
     * バッチ単位で移動候補を確定
     *
     * @param  string  $batchCode  バッチコード
     * @param  int  $executedBy  確定者ID
     * @return Collection 実行結果のコレクション
     */
    public function executeBatch(string $batchCode, int $executedBy): Collection
    {
        $candidates = WmsStockTransferCandidate::where('batch_code', $batchCode)
            ->where('status', CandidateStatus::APPROVED)
            ->get();

        if ($candidates->isEmpty()) {
            Log::info('No approved transfer candidates to execute', ['batch_code' => $batchCode]);

            return collect();
        }

        $results = collect();
        $executedCount = 0;

        foreach ($candidates as $candidate) {
            try {
                $result = $this->executeCandidate($candidate, $executedBy);
                $results->push($result);
                $executedCount++;
            } catch (\Exception $e) {
                Log::error('Failed to execute transfer candidate', [
                    'candidate_id' => $candidate->id,
                    'error' => $e->getMessage(),
                ]);
                // 個別のエラーはスキップして続行
            }
        }

        Log::info('Batch transfer execution completed', [
            'batch_code' => $batchCode,
            'executed_count' => $executedCount,
            'total_approved' => $candidates->count(),
        ]);

        return $results;
    }

    /**
     * stock_transfer_queue を作成 (action_type=CREATE)
     *
     * @param  WmsStockTransferCandidate  $candidate  移動候補
     * @return int 作成されたqueue ID
     */
    private function createStockTransferQueue(WmsStockTransferCandidate $candidate): int
    {
        // 倉庫情報を取得
        $hubWarehouse = $candidate->hubWarehouse;
        $satelliteWarehouse = $candidate->satelliteWarehouse;

        if (! $hubWarehouse || ! $satelliteWarehouse) {
            throw new \RuntimeException(
                "Warehouse not found: hub={$candidate->hub_warehouse_id}, satellite={$candidate->satellite_warehouse_id}"
            );
        }

        // 商品情報を取得
        $item = $candidate->item;
        if (! $item) {
            throw new \RuntimeException("Item not found: {$candidate->item_id}");
        }

        // items配列を作成
        $items = [[
            'item_code' => $item->code,
            'quantity' => $candidate->transfer_quantity,
            'quantity_type' => $candidate->quantity_type->value,
            'stock_allocation_code' => '1', // デフォルトの在庫区分コード（通常在庫）
            'note' => "移動候補ID: {$candidate->id}",
        ]];

        // request_idは候補IDを使用（ユニーク制約対応）
        $requestId = "transfer-create-{$candidate->id}";

        // 処理日はshipment_dateを優先、なければexpected_arrival_dateを使用
        $processDate = $candidate->shipment_date?->format('Y-m-d')
            ?? $candidate->expected_arrival_date?->format('Y-m-d')
            ?? now()->format('Y-m-d');

        $deliveryCourseId = $this->resolveTransferDeliveryCourseId(
            (int) $candidate->hub_warehouse_id,
            (int) $candidate->satellite_warehouse_id,
            $candidate->delivery_course_id ? (int) $candidate->delivery_course_id : null,
        );
        $this->setResolvedDeliveryCourseId($candidate, $deliveryCourseId);

        $queueData = [
            'client_id' => config('app.client_id'),
            'request_id' => $requestId,
            'slip_number' => null, // 自動採番
            'process_date' => $processDate,
            'delivered_date' => $processDate,
            'note' => "自動発注移動 バッチ:{$candidate->batch_code}",
            'items' => json_encode($items, JSON_UNESCAPED_UNICODE),
            'from_warehouse_code' => $hubWarehouse->code,    // 移動元（Hub）
            'to_warehouse_code' => $satelliteWarehouse->code, // 移動先（Satellite）
            'delivery_course_id' => $deliveryCourseId, // 配送コースID
            'status' => 'BEFORE',
            'action_type' => 'CREATE',  // 新規追加
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $existingQueue = DB::connection('sakemaru')
            ->table('stock_transfer_queue')
            ->where('request_id', $requestId)
            ->where('action_type', 'CREATE')
            ->first();

        if ($existingQueue) {
            if ($existingQueue->status === 'BEFORE') {
                DB::connection('sakemaru')
                    ->table('stock_transfer_queue')
                    ->where('id', $existingQueue->id)
                    ->update(array_merge($queueData, [
                        'created_at' => $existingQueue->created_at,
                    ]));
            }

            $queueId = (int) $existingQueue->id;
        } else {
            $queueId = DB::connection('sakemaru')->table('stock_transfer_queue')->insertGetId($queueData);
        }

        Log::info('Stock transfer queue created (action_type=CREATE)', [
            'queue_id' => $queueId,
            'candidate_id' => $candidate->id,
            'request_id' => $requestId,
            'from_warehouse' => $hubWarehouse->code,
            'to_warehouse' => $satelliteWarehouse->code,
            'delivery_course_id' => $deliveryCourseId,
            'transfer_quantity' => $candidate->transfer_quantity,
        ]);

        return $queueId;
    }

    /**
     * WmsOrderIncomingSchedule を作成（Satellite入荷予定）
     *
     * @param  WmsStockTransferCandidate  $candidate  移動候補
     * @return WmsOrderIncomingSchedule 作成された入荷予定
     */
    private function createIncomingSchedule(WmsStockTransferCandidate $candidate): WmsOrderIncomingSchedule
    {
        $existingSchedule = WmsOrderIncomingSchedule::query()
            ->where('transfer_candidate_id', $candidate->id)
            ->where('order_source', OrderSource::TRANSFER)
            ->where('status', '!=', IncomingScheduleStatus::CANCELLED->value)
            ->first();

        $expectedArrivalDate = $this->resolveIncomingExpectedArrivalDate($candidate);
        $expirationDate = $this->calculateExpirationDate($candidate->item_id, $expectedArrivalDate);

        $orderDate = ClientSetting::freshSystemDateYMD('order_incoming_schedule:transfer');
        $scheduleData = [
            'warehouse_id' => $candidate->satellite_warehouse_id,
            'item_id' => $candidate->item_id,
            'item_code' => $candidate->item_code,
            'search_code' => $this->getSearchCodeForItem($candidate->item_id),
            'contractor_id' => $candidate->contractor_id,
            'supplier_id' => null,
            'order_candidate_id' => null,
            'transfer_candidate_id' => $candidate->id,
            'source_warehouse_id' => $candidate->hub_warehouse_id,
            'stock_transfer_id' => null,  // Core処理完了後に同期
            'order_source' => OrderSource::TRANSFER,
            'slip_number' => WmsOrderIncomingSchedule::generateSlipNumber($orderDate),
            'expected_quantity' => $candidate->transfer_quantity,
            'received_quantity' => 0,
            'quantity_type' => $candidate->quantity_type,
            'order_date' => $orderDate,
            'expected_arrival_date' => $expectedArrivalDate->format('Y-m-d'),
            'expiration_date' => $expirationDate,
            'status' => IncomingScheduleStatus::PENDING,
        ];

        if ($existingSchedule) {
            if ($existingSchedule->status === IncomingScheduleStatus::PENDING) {
                $scheduleUpdateData = $scheduleData;
                unset($scheduleUpdateData['slip_number']);

                $existingSchedule->update($scheduleUpdateData);
            }

            $schedule = $existingSchedule->refresh();
        } else {
            $schedule = WmsOrderIncomingSchedule::create($scheduleData);
        }

        Log::info('Incoming schedule created for transfer', [
            'schedule_id' => $schedule->id,
            'candidate_id' => $candidate->id,
            'warehouse_id' => $candidate->satellite_warehouse_id,
            'source_warehouse_id' => $candidate->hub_warehouse_id,
            'item_id' => $candidate->item_id,
            'expected_quantity' => $candidate->transfer_quantity,
            'expected_arrival_date' => $expectedArrivalDate->format('Y-m-d'),
            'expiration_date' => $expirationDate,
        ]);

        return $schedule;
    }

    private function resolveIncomingExpectedArrivalDate(WmsStockTransferCandidate $candidate): Carbon
    {
        return $candidate->expected_arrival_date
            ?? $candidate->shipment_date
            ?? Carbon::today();
    }

    /**
     * 商品の検索コードを取得
     *
     * @param  int  $itemId  商品ID
     * @return string|null 検索コード
     */
    private function getSearchCodeForItem(int $itemId): ?string
    {
        return DB::connection('sakemaru')
            ->table('item_search_information')
            ->where('item_id', $itemId)
            ->where('is_used_for_ordering', true)
            ->where('is_active', true)
            ->value('search_string');
    }

    /**
     * 商品の賞味期限を計算
     *
     * 商品マスタの default_expiration_days から計算
     * 設定がない場合は null を返す
     *
     * @param  int  $itemId  商品ID
     * @param  string|Carbon  $baseDate  基準日（入荷予定日）
     * @return string|null 賞味期限（Y-m-d形式）
     */
    private function calculateExpirationDate(int $itemId, string|Carbon $baseDate): ?string
    {
        $item = Item::find($itemId);

        if (! $item || ! $item->default_expiration_days || $item->default_expiration_days <= 0) {
            return null;
        }

        return $this->calculateExpirationDateFromDays($baseDate, (int) $item->default_expiration_days);
    }

    private function calculateExpirationDateFromDays(string|Carbon $baseDate, int $days): string
    {
        $base = $baseDate instanceof Carbon ? $baseDate->copy() : Carbon::parse($baseDate);

        return $base->addDays($days)->format('Y-m-d');
    }

    /**
     * 複数の移動候補を一括確定
     *
     * @param  array  $candidateIds  候補IDの配列
     * @param  int  $executedBy  確定者ID
     * @return Collection 実行結果のコレクション
     */
    public function executeMultiple(array $candidateIds, int $executedBy): Collection
    {
        $candidates = WmsStockTransferCandidate::whereIn('id', $candidateIds)
            ->where('status', CandidateStatus::APPROVED)
            ->with(['hubWarehouse', 'satelliteWarehouse', 'item'])
            ->get();

        $result = $this->executeGroupedCandidates($candidates, $executedBy);

        return collect($result['executed_candidate_ids'])
            ->map(fn (int $candidateId): array => ['candidate_id' => $candidateId]);
    }

    /**
     * 全ての承認済み移動候補を一括確定（グループ化してqueue作成）
     *
     * hub_warehouse + satellite_warehouse + delivery_course_id でグループ化し、
     * 1グループ1queueで複数商品をまとめて作成する
     *
     * @param  int  $executedBy  確定者ID
     * @return array{queue_count: int, candidate_count: int, errors: array}
     */
    public function executeAllApprovedGrouped(int $executedBy, ?int $warehouseId = null, ?int $createdBy = null): array
    {
        $query = WmsStockTransferCandidate::where('status', CandidateStatus::APPROVED)
            ->forCreatedBy($createdBy)
            ->with(['hubWarehouse', 'satelliteWarehouse', 'item']);

        if ($warehouseId !== null) {
            $query->where('satellite_warehouse_id', $warehouseId);
        }

        $candidates = $query->get();

        if ($candidates->isEmpty()) {
            Log::info('No approved transfer candidates to execute');

            return [
                'queue_count' => 0,
                'candidate_count' => 0,
                'executed_candidate_ids' => [],
                'errors' => [],
            ];
        }

        return $this->executeGroupedCandidates($candidates, $executedBy);
    }

    /**
     * @param  Collection<int, WmsStockTransferCandidate>  $candidates
     * @return array{queue_count: int, candidate_count: int, executed_candidate_ids: array<int>, errors: array}
     */
    private function executeGroupedCandidates(Collection $candidates, int $executedBy): array
    {
        $queueCount = 0;
        $candidateCount = 0;
        $executedCandidateIds = [];
        $errors = [];

        $candidates = $candidates->filter(function (WmsStockTransferCandidate $candidate) use (&$errors) {
            try {
                $this->setResolvedDeliveryCourseId($candidate, $this->resolveTransferDeliveryCourseId(
                    (int) $candidate->hub_warehouse_id,
                    (int) $candidate->satellite_warehouse_id,
                    $candidate->delivery_course_id ? (int) $candidate->delivery_course_id : null,
                ));

                return true;
            } catch (\Exception $e) {
                $groupKey = "{$candidate->hub_warehouse_id}_{$candidate->satellite_warehouse_id}";
                $errors[] = [
                    'group_key' => $groupKey,
                    'candidate_id' => $candidate->id,
                    'error' => $e->getMessage(),
                ];
                Log::error('Failed to resolve transfer delivery course', [
                    'group_key' => $groupKey,
                    'candidate_id' => $candidate->id,
                    'error' => $e->getMessage(),
                ]);

                return false;
            }
        });

        // 移動伝票は移動元・移動先・配送コース・入荷日が同じ候補だけを1queueにまとめる
        $grouped = $candidates->groupBy(function (WmsStockTransferCandidate $candidate) {
            $arrivalDate = $this->resolveTransferGroupArrivalDate($candidate)->format('Y-m-d');

            return "{$candidate->hub_warehouse_id}_{$candidate->satellite_warehouse_id}_{$this->resolvedDeliveryCourseId($candidate)}_{$arrivalDate}";
        });

        // 各グループを個別のトランザクション（セーブポイント）で処理
        // これにより、1グループ失敗しても他グループには影響しない
        foreach ($grouped as $groupKey => $groupCandidates) {
            try {
                DB::connection('sakemaru')->transaction(function () use ($groupKey, $groupCandidates, $executedBy, &$queueCount, &$candidateCount, &$executedCandidateIds) {
                    // 1. 入荷予定を先に作成（失敗時はキューが作られないようにするため）
                    foreach ($groupCandidates as $candidate) {
                        $this->createIncomingSchedule($candidate);
                    }

                    // 2. キューを作成（入荷予定が全て成功した後）
                    $queueId = $this->createGroupedStockTransferQueue($groupCandidates, $executedBy);

                    // 3. ステータスを更新
                    foreach ($groupCandidates as $candidate) {
                        $candidate->update([
                            'delivery_course_id' => $this->resolvedDeliveryCourseId($candidate),
                            'status' => CandidateStatus::EXECUTED,
                            'modified_by' => $executedBy,
                            'modified_at' => now(),
                        ]);
                        $candidateCount++;
                        $executedCandidateIds[] = (int) $candidate->id;
                    }

                    $queueCount++;

                    Log::info('Grouped transfer candidates executed', [
                        'group_key' => $groupKey,
                        'queue_id' => $queueId,
                        'candidate_count' => $groupCandidates->count(),
                    ]);
                });
            } catch (\Exception $e) {
                // このグループの処理は全てロールバックされる
                $errors[] = [
                    'group_key' => $groupKey,
                    'error' => $e->getMessage(),
                ];
                Log::error('Failed to execute grouped transfer candidates', [
                    'group_key' => $groupKey,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'queue_count' => $queueCount,
            'candidate_count' => $candidateCount,
            'executed_candidate_ids' => $executedCandidateIds,
            'errors' => $errors,
        ];
    }

    /**
     * グループ化された移動候補からstock_transfer_queueを作成
     *
     * @param  Collection  $candidates  同一グループの移動候補
     * @param  int  $executedBy  確定者ID
     * @return int 作成されたqueue ID
     */
    private function createGroupedStockTransferQueue(Collection $candidates, int $executedBy): int
    {
        $firstCandidate = $candidates->first();
        $hubWarehouse = $firstCandidate->hubWarehouse;
        $satelliteWarehouse = $firstCandidate->satelliteWarehouse;

        if (! $hubWarehouse || ! $satelliteWarehouse) {
            throw new \RuntimeException(
                "Warehouse not found: hub={$firstCandidate->hub_warehouse_id}, satellite={$firstCandidate->satellite_warehouse_id}"
            );
        }

        // 全候補の商品をitems配列に統合
        $items = [];
        $candidateIds = [];
        foreach ($candidates as $candidate) {
            $item = $candidate->item;
            if (! $item) {
                throw new \RuntimeException("Item not found: {$candidate->item_id}");
            }

            $items[] = [
                'item_code' => $item->code,
                'quantity' => $candidate->transfer_quantity,
                'quantity_type' => $candidate->quantity_type->value,
                'stock_allocation_code' => '1',
                'note' => "移動候補ID: {$candidate->id}",
            ];
            $candidateIds[] = $candidate->id;
        }

        sort($candidateIds);

        // request_idは同時確定された同一グループの候補IDを連結（ユニーク制約対応）
        $requestId = 'transfer-create-group-'.implode('-', $candidateIds);
        if (strlen($requestId) > 100) {
            // 長すぎる場合はハッシュ化
            $requestId = 'transfer-create-group-'.md5(implode('-', $candidateIds));
        }

        $processDate = $this->resolveTransferGroupArrivalDate($firstCandidate)->format('Y-m-d');

        // バッチコードを収集
        $batchCodes = $candidates->pluck('batch_code')->unique()->implode(',');

        $deliveryCourseId = $this->resolvedDeliveryCourseId($firstCandidate);

        if ($deliveryCourseId === null) {
            $deliveryCourseId = $this->resolveTransferDeliveryCourseId(
                (int) $firstCandidate->hub_warehouse_id,
                (int) $firstCandidate->satellite_warehouse_id,
                $firstCandidate->delivery_course_id ? (int) $firstCandidate->delivery_course_id : null,
            );
            $this->setResolvedDeliveryCourseId($firstCandidate, $deliveryCourseId);
        }

        $this->assertGroupedCandidatesUseSameDeliveryCourse(
            $candidates,
            (int) $firstCandidate->hub_warehouse_id,
            (int) $firstCandidate->satellite_warehouse_id,
            $deliveryCourseId,
        );
        $this->assertGroupedCandidatesUseSameArrivalDate($candidates, $processDate);

        $queueData = [
            'client_id' => config('app.client_id'),
            'request_id' => $requestId,
            'slip_number' => null,
            'process_date' => $processDate,
            'delivered_date' => $processDate,
            'note' => "自動発注移動 バッチ:{$batchCodes} ({$candidates->count()}件)",
            'items' => json_encode($items, JSON_UNESCAPED_UNICODE),
            'from_warehouse_code' => $hubWarehouse->code,
            'to_warehouse_code' => $satelliteWarehouse->code,
            'delivery_course_id' => $deliveryCourseId,
            'status' => 'BEFORE',
            'action_type' => 'CREATE',  // 新規追加
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $existingQueue = DB::connection('sakemaru')
            ->table('stock_transfer_queue')
            ->where('request_id', $requestId)
            ->where('action_type', 'CREATE')
            ->first();

        if ($existingQueue) {
            if ($existingQueue->status === 'BEFORE') {
                DB::connection('sakemaru')
                    ->table('stock_transfer_queue')
                    ->where('id', $existingQueue->id)
                    ->update(array_merge($queueData, [
                        'created_at' => $existingQueue->created_at,
                    ]));
            }

            $queueId = (int) $existingQueue->id;
        } else {
            $queueId = DB::connection('sakemaru')->table('stock_transfer_queue')->insertGetId($queueData);
        }

        Log::info('Grouped stock transfer queue created (action_type=CREATE)', [
            'queue_id' => $queueId,
            'candidate_ids' => $candidateIds,
            'request_id' => $requestId,
            'from_warehouse' => $hubWarehouse->code,
            'to_warehouse' => $satelliteWarehouse->code,
            'delivery_course_id' => $deliveryCourseId,
            'arrival_date' => $processDate,
            'item_count' => count($items),
        ]);

        return $queueId;
    }

    private function resolveTransferDeliveryCourseId(int $fromWarehouseId, int $toWarehouseId, ?int $currentDeliveryCourseId): ?int
    {
        $deliveryCourseId = $this->transferDeliveryCourseMap()["{$fromWarehouseId}:{$toWarehouseId}"] ?? null;

        return $deliveryCourseId !== null ? (int) $deliveryCourseId : $currentDeliveryCourseId;
    }

    /**
     * @return array<string, int>
     */
    private function transferDeliveryCourseMap(): array
    {
        if ($this->transferDeliveryCourseMap !== null) {
            return $this->transferDeliveryCourseMap;
        }

        $this->transferDeliveryCourseMap = DB::connection('sakemaru')
            ->table('warehouse_stock_transfer_delivery_courses')
            ->whereNotNull('delivery_course_id')
            ->get(['from_warehouse_id', 'to_warehouse_id', 'delivery_course_id'])
            ->mapWithKeys(fn ($row) => [
                "{$row->from_warehouse_id}:{$row->to_warehouse_id}" => (int) $row->delivery_course_id,
            ])
            ->all();

        return $this->transferDeliveryCourseMap;
    }

    private function resolvedDeliveryCourseId(WmsStockTransferCandidate $candidate): ?int
    {
        $deliveryCourseId = $this->resolvedDeliveryCourseIds[(int) $candidate->id]
            ?? $candidate->delivery_course_id;

        return $deliveryCourseId !== null ? (int) $deliveryCourseId : null;
    }

    private function setResolvedDeliveryCourseId(WmsStockTransferCandidate $candidate, ?int $deliveryCourseId): void
    {
        $this->resolvedDeliveryCourseIds[(int) $candidate->id] = $deliveryCourseId;
    }

    private function resolveTransferGroupArrivalDate(WmsStockTransferCandidate $candidate): Carbon
    {
        return $candidate->expected_arrival_date
            ?? $candidate->shipment_date
            ?? Carbon::today();
    }

    /**
     * @param  Collection<int, WmsStockTransferCandidate>  $candidates
     */
    private function assertGroupedCandidatesUseSameDeliveryCourse(
        Collection $candidates,
        int $fromWarehouseId,
        int $toWarehouseId,
        ?int $deliveryCourseId
    ): void {
        foreach ($candidates as $candidate) {
            $candidateDeliveryCourseId = $this->resolvedDeliveryCourseId($candidate);
            if ($candidateDeliveryCourseId === $deliveryCourseId) {
                continue;
            }

            throw new \RuntimeException(
                "Delivery course mismatch in stock transfer group: from_warehouse_id={$fromWarehouseId}, to_warehouse_id={$toWarehouseId}, candidate_id={$candidate->id}"
            );
        }
    }

    /**
     * @param  Collection<int, WmsStockTransferCandidate>  $candidates
     */
    private function assertGroupedCandidatesUseSameArrivalDate(Collection $candidates, string $arrivalDate): void
    {
        foreach ($candidates as $candidate) {
            $candidateArrivalDate = $this->resolveTransferGroupArrivalDate($candidate)->format('Y-m-d');
            if ($candidateArrivalDate === $arrivalDate) {
                continue;
            }

            throw new \RuntimeException(
                "Arrival date mismatch in stock transfer group: expected_arrival_date={$arrivalDate}, candidate_id={$candidate->id}, candidate_expected_arrival_date={$candidateArrivalDate}"
            );
        }
    }
}
