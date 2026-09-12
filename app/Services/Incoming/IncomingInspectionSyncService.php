<?php

namespace App\Services\Incoming;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\AutoOrder\OrderSource;
use App\Enums\QuantityType;
use App\Models\WmsIncomingAppInspectionBatch;
use App\Models\WmsIncomingAppInspectionDetail;
use App\Models\WmsOrderIncomingSchedule;
use App\Services\AutoOrder\IncomingConfirmationService;
use App\Services\WarehouseResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IncomingInspectionSyncService
{
    public function __construct(
        private readonly IncomingConfirmationService $confirmationService
    ) {}

    public function sync(array $payload, int $userId): array
    {
        $inspectionDate = CarbonImmutable::parse($payload['inspection_date'] ?? now()->toDateString())->toDateString();
        $inspectedAt = isset($payload['inspected_at'])
            ? CarbonImmutable::parse($payload['inspected_at'])->toDateTimeString()
            : now()->toDateTimeString();
        $warehouseId = (int) $payload['warehouse_id'];
        $matchingWarehouseIds = $this->matchingWarehouseIds($warehouseId);
        $itemContractorWarehouseId = WarehouseResolver::resolveRealWarehouseId($warehouseId);
        $details = $payload['details'] ?? [];

        $batch = WmsIncomingAppInspectionBatch::query()->firstOrCreate(
            ['client_batch_uuid' => (string) $payload['client_batch_uuid']],
            [
                'warehouse_id' => $warehouseId,
                'inspection_date' => $inspectionDate,
                'inspected_at' => $inspectedAt,
                'inspected_by' => $userId,
                'picker_id' => $payload['picker_id'] ?? null,
                'device_id' => $payload['device_id'] ?? null,
                'app_version' => $payload['app_version'] ?? null,
                'status' => WmsIncomingAppInspectionBatch::STATUS_RECEIVED,
                'payload_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE)),
            ]
        );

        $results = [];

        foreach ($details as $line) {
            try {
                $results[] = $this->processLine(
                    $batch,
                    $line,
                    $userId,
                    $inspectionDate,
                    $inspectedAt,
                    $matchingWarehouseIds,
                    $itemContractorWarehouseId
                );
            } catch (\Throwable $throwable) {
                Log::warning('[IncomingInspectionSyncService] app incoming inspection line failed', [
                    'batch_id' => $batch->id,
                    'line_uuid' => $line['client_line_uuid'] ?? null,
                    'error' => $throwable->getMessage(),
                ]);

                $results[] = $this->recordFailedLine($batch, $line, $warehouseId, $inspectedAt, $throwable->getMessage());
            }
        }

        $batch = $this->refreshBatchCounts($batch);

        return [
            'batch' => [
                'id' => (int) $batch->id,
                'client_batch_uuid' => $batch->client_batch_uuid,
                'status' => $batch->status,
                'total_detail_count' => (int) $batch->total_detail_count,
                'success_count' => (int) $batch->success_count,
                'history_only_count' => (int) $batch->history_only_count,
                'review_count' => (int) $batch->review_count,
                'error_count' => (int) $batch->error_count,
            ],
            'details' => collect($results)
                ->map(fn (WmsIncomingAppInspectionDetail $detail): array => $this->formatDetail($detail))
                ->values()
                ->all(),
        ];
    }

    private function processLine(
        WmsIncomingAppInspectionBatch $batch,
        array $line,
        int $userId,
        string $inspectionDate,
        string $inspectedAt,
        array $matchingWarehouseIds,
        int $itemContractorWarehouseId
    ): WmsIncomingAppInspectionDetail {
        return DB::connection('sakemaru')->transaction(function () use ($batch, $line, $userId, $inspectionDate, $inspectedAt, $matchingWarehouseIds, $itemContractorWarehouseId) {
            $line['picker_id'] ??= $batch->picker_id;

            $existing = WmsIncomingAppInspectionDetail::query()
                ->where('batch_id', $batch->id)
                ->where('client_line_uuid', (string) $line['client_line_uuid'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing->fresh();
            }

            $itemReviewReason = null;
            $item = $this->resolveItem($line, $itemReviewReason);
            $scheduleReviewReason = null;
            $schedule = $this->resolveSchedule($matchingWarehouseIds, $line, $item?->id, $scheduleReviewReason);
            $itemContractor = $schedule === null && $item
                ? $this->resolveItemContractor($itemContractorWarehouseId, (int) $item->id, $line['contractor_id'] ?? null)
                : null;
            $capacityCase = max(1, (int) ($line['capacity_case'] ?? $item?->capacity_case ?? $schedule?->item?->capacity_case ?? 1));
            $totalPieces = $this->resolveTotalPieces($line, $capacityCase);

            $detail = new WmsIncomingAppInspectionDetail([
                'batch_id' => $batch->id,
                'client_line_uuid' => (string) $line['client_line_uuid'],
                'warehouse_id' => $batch->warehouse_id,
                'incoming_schedule_id' => $schedule?->id,
                'item_id' => $item?->id ?? $schedule?->item_id,
                'item_code' => $item?->code ?? $schedule?->item_code ?? ($line['item_code'] ?? null),
                'item_name' => $item?->name ?? $schedule?->item?->name ?? ($line['item_name'] ?? null),
                'scanned_code' => $line['scanned_code'] ?? null,
                'slip_number' => $line['slip_number'] ?? $schedule?->slip_number,
                'contractor_id' => $line['contractor_id'] ?? $schedule?->contractor_id ?? $itemContractor?->contractor_id,
                'supplier_id' => $schedule?->supplier_id ?? $itemContractor?->supplier_id ?? $item?->supplier_id,
                'location_id' => $line['location_id'] ?? $schedule?->location_id,
                'expected_piece_quantity' => $schedule?->expected_piece_quantity,
                'inspected_case_quantity' => (int) ($line['case_quantity'] ?? 0),
                'inspected_piece_quantity' => (int) ($line['piece_quantity'] ?? 0),
                'inspected_total_piece_quantity' => $totalPieces,
                'capacity_case' => $capacityCase,
                'expiration_date' => $line['expiration_date'] ?? null,
                'inspected_at' => $line['inspected_at'] ?? $inspectedAt,
                'raw_payload' => $line,
            ]);

            if ($totalPieces <= 0) {
                return $this->markNeedsReview($detail, '検品数量が0以下です。');
            }

            if ($itemReviewReason !== null) {
                return $this->markNeedsReview($detail, $itemReviewReason);
            }

            if (! $item && ! $schedule?->item) {
                return $this->markNeedsReview($detail, '商品を特定できません。');
            }

            if ($item && $schedule && (int) $item->id !== (int) $schedule->item_id) {
                return $this->markNeedsReview($detail, '指定された入荷予定の商品と検品商品の対応が一致しません。');
            }

            if ($scheduleReviewReason !== null) {
                return $this->markNeedsReview($detail, $scheduleReviewReason);
            }

            if ($schedule) {
                return $this->applyScheduleInspection($detail, $schedule, $totalPieces, $userId, $inspectionDate, $line);
            }

            $recentEosMatches = $this->findRecentEosConfirmedSchedules($matchingWarehouseIds, (int) $detail->item_id, $inspectionDate, $line);
            if ($recentEosMatches->count() > 1) {
                return $this->markNeedsReview($detail, '直近3日以内のEOS入荷確定済み候補が複数あります。');
            }

            if ($recentEosMatches->count() === 1) {
                $recentEos = $recentEosMatches->first();
                $detail->fill([
                    'linked_confirmed_schedule_id' => $recentEos->id,
                    'inspection_policy' => WmsIncomingAppInspectionDetail::POLICY_EOS_ALREADY_CONFIRMED,
                    'result_status' => WmsIncomingAppInspectionDetail::RESULT_EOS_ALREADY_CONFIRMED,
                    'review_reason' => '直近3日以内にEOS入荷確定済みのため、アプリ検品履歴のみ保存しました。',
                ])->save();

                return $detail->fresh();
            }

            $created = $this->createAppUnplannedSchedule($detail, null, $totalPieces, $userId, $inspectionDate, $line, $itemContractorWarehouseId);

            if (! $created) {
                return $this->markNeedsReview($detail, '入荷予定なしデータの作成に必要な発注先を特定できません。');
            }

            $detail->fill([
                'created_schedule_id' => $created->id,
                'inspection_policy' => WmsIncomingAppInspectionDetail::POLICY_APP_CONFIRM_ALLOWED,
                'result_status' => WmsIncomingAppInspectionDetail::RESULT_APP_UNPLANNED_CREATED,
                'applied_piece_quantity' => $totalPieces,
                'review_reason' => '入荷予定なし入荷として入荷完了データを作成しました。',
            ])->save();

            return $detail->fresh();
        });
    }

    private function applyScheduleInspection(
        WmsIncomingAppInspectionDetail $detail,
        WmsOrderIncomingSchedule $schedule,
        int $totalPieces,
        int $userId,
        string $inspectionDate,
        array $line
    ): WmsIncomingAppInspectionDetail {
        $schedule->loadMissing(['item', 'orderCandidate']);
        $isEosSent = $schedule->isEosSent();

        if ($schedule->purchase_queue_id !== null || $schedule->status === IncomingScheduleStatus::TRANSMITTED) {
            $detail->fill([
                'inspection_policy' => WmsIncomingAppInspectionDetail::POLICY_PURCHASE_TRANSMITTED_LOCKED,
                'result_status' => WmsIncomingAppInspectionDetail::RESULT_HISTORY_ONLY,
                'review_reason' => '仕入連携済みのため、アプリ検品履歴のみ保存しました。',
            ])->save();

            return $detail->fresh();
        }

        if ($schedule->order_source === OrderSource::TRANSFER
            || $schedule->transfer_candidate_id !== null
            || $schedule->source_warehouse_id !== null
            || $schedule->stock_transfer_id !== null) {
            return $this->markNeedsReview($detail, '店間移動の入荷予定はアプリ検品から確定できません。');
        }

        if ($schedule->status === IncomingScheduleStatus::CONFIRMED) {
            $detail->fill([
                'inspection_policy' => $isEosSent
                    ? WmsIncomingAppInspectionDetail::POLICY_EOS_ALREADY_CONFIRMED
                    : WmsIncomingAppInspectionDetail::POLICY_APP_CONFIRM_ALLOWED,
                'result_status' => $isEosSent
                    ? WmsIncomingAppInspectionDetail::RESULT_EOS_ALREADY_CONFIRMED
                    : WmsIncomingAppInspectionDetail::RESULT_HISTORY_ONLY,
                'linked_confirmed_schedule_id' => $schedule->id,
                'review_reason' => $isEosSent
                    ? 'EOS入荷確定済みのため、アプリ検品履歴のみ保存しました。'
                    : '入荷確定済みのため、アプリ検品履歴のみ保存しました。',
            ])->save();

            return $detail->fresh();
        }

        if ($isEosSent) {
            $detail->fill([
                'inspection_policy' => WmsIncomingAppInspectionDetail::POLICY_EOS_HISTORY_ONLY,
                'result_status' => WmsIncomingAppInspectionDetail::RESULT_HISTORY_ONLY,
                'review_reason' => 'EOS自動入荷確定対象のため、アプリ検品履歴のみ保存しました。',
            ])->save();

            return $detail->fresh();
        }

        $remainingPieces = max(0, $schedule->expected_piece_quantity - $schedule->received_piece_quantity);

        if ($remainingPieces <= 0) {
            return $this->markNeedsReview($detail, '入荷予定の残数量がありません。');
        }

        if ($totalPieces > $remainingPieces) {
            $this->confirmationService->confirmIncoming(
                $schedule,
                $userId,
                (int) $schedule->expected_quantity,
                $inspectionDate,
                $line['expiration_date'] ?? null,
                $line['location_id'] ?? null,
                $line['picker_id'] ?? null
            );

            $extraPieces = $totalPieces - $remainingPieces;
            $itemContractorWarehouseId = WarehouseResolver::resolveRealWarehouseId((int) $detail->warehouse_id);
            $created = $this->createAppUnplannedSchedule($detail, $schedule, $extraPieces, $userId, $inspectionDate, $line, $itemContractorWarehouseId);

            $detail->fill([
                'linked_confirmed_schedule_id' => $schedule->id,
                'created_schedule_id' => $created?->id,
                'inspection_policy' => WmsIncomingAppInspectionDetail::POLICY_APP_CONFIRM_ALLOWED,
                'result_status' => $created
                    ? WmsIncomingAppInspectionDetail::RESULT_APP_UNPLANNED_CREATED
                    : WmsIncomingAppInspectionDetail::RESULT_NEEDS_REVIEW,
                'applied_piece_quantity' => $remainingPieces,
                'review_reason' => $created
                    ? "入荷予定超過分 {$extraPieces} バラを予定なし入荷として作成しました。"
                    : '入荷予定超過分の予定なし入荷を作成できませんでした。',
            ])->save();

            return $detail->fresh();
        }

        $incomingScheduleUnits = $this->piecesToScheduleUnits($totalPieces, $schedule);
        if ($incomingScheduleUnits === null) {
            return $this->markNeedsReview($detail, '検品総バラ数を入荷予定の数量単位に変換できません。');
        }

        $finalReceivedQuantity = (int) $schedule->received_quantity + $incomingScheduleUnits;
        $confirmed = $this->confirmationService->confirmIncoming(
            $schedule,
            $userId,
            $finalReceivedQuantity,
            $inspectionDate,
            $line['expiration_date'] ?? null,
            $line['location_id'] ?? null,
            $line['picker_id'] ?? null
        );

        $confirmed->loadMissing('item');
        $detail->fill([
            'linked_confirmed_schedule_id' => $confirmed->id,
            'inspection_policy' => WmsIncomingAppInspectionDetail::POLICY_APP_CONFIRM_ALLOWED,
            'result_status' => WmsIncomingAppInspectionDetail::RESULT_CONFIRMED,
            'applied_piece_quantity' => $totalPieces,
            'shortage_piece_quantity' => max(0, $confirmed->expected_piece_quantity - $confirmed->received_piece_quantity),
            'review_reason' => $totalPieces < $remainingPieces
                ? '検品数量で入荷確定しました。不足分は欠品として完了しました。'
                : null,
        ])->save();

        return $detail->fresh();
    }

    private function createAppUnplannedSchedule(
        WmsIncomingAppInspectionDetail $detail,
        ?WmsOrderIncomingSchedule $sourceSchedule,
        int $pieceQuantity,
        int $userId,
        string $inspectionDate,
        array $line,
        int $itemContractorWarehouseId
    ): ?WmsOrderIncomingSchedule {
        $itemContractor = $detail->item_id
            ? $this->resolveItemContractor($itemContractorWarehouseId, (int) $detail->item_id, $line['contractor_id'] ?? $sourceSchedule?->contractor_id ?? $detail->contractor_id)
            : null;
        $contractorId = $line['contractor_id'] ?? $sourceSchedule?->contractor_id ?? $detail->contractor_id ?? $itemContractor?->contractor_id;
        if (! $contractorId) {
            return null;
        }

        $item = $this->resolveItem(['item_id' => $detail->item_id, 'item_code' => $detail->item_code]);
        $slipNumber = filled($line['slip_number'] ?? null)
            ? (string) $line['slip_number']
            : ($sourceSchedule?->slip_number ?: WmsOrderIncomingSchedule::generateSlipNumber($inspectionDate));

        return WmsOrderIncomingSchedule::query()->create([
            'warehouse_id' => $detail->warehouse_id,
            'item_id' => $detail->item_id,
            'item_code' => $detail->item_code,
            'search_code' => $detail->scanned_code,
            'contractor_id' => $contractorId,
            'supplier_id' => $sourceSchedule?->supplier_id ?? $itemContractor?->supplier_id ?? $item?->supplier_id,
            'location_id' => $line['location_id'] ?? $sourceSchedule?->location_id,
            'order_candidate_id' => null,
            'manual_order_number' => null,
            'order_source' => OrderSource::APP_UNPLANNED,
            'slip_number' => $slipNumber,
            'expected_quantity' => $pieceQuantity,
            'received_quantity' => $pieceQuantity,
            'quantity_type' => QuantityType::PIECE,
            'order_date' => $sourceSchedule?->order_date?->toDateString() ?? $inspectionDate,
            'expected_arrival_date' => $inspectionDate,
            'actual_arrival_date' => $inspectionDate,
            'expiration_date' => $line['expiration_date'] ?? null,
            'status' => IncomingScheduleStatus::CONFIRMED,
            'confirmed_at' => now(),
            'confirmed_by' => ($line['picker_id'] ?? null) ? null : $userId,
            'confirmed_picker_id' => $line['picker_id'] ?? null,
            'is_receive_matched' => false,
            'shipped_quantity' => 0,
            'unit_price' => $sourceSchedule?->unit_price,
            'case_price' => $sourceSchedule?->case_price,
            'partner_unit_price' => $sourceSchedule?->partner_unit_price,
            'partner_case_price' => $sourceSchedule?->partner_case_price,
            'price_type' => $sourceSchedule?->price_type,
            'shortage_quantity' => 0,
            'source_incoming_schedule_id' => null,
            'note' => $this->appUnplannedNote($detail, $sourceSchedule),
        ]);
    }

    private function appUnplannedNote(WmsIncomingAppInspectionDetail $detail, ?WmsOrderIncomingSchedule $sourceSchedule): string
    {
        $note = "アプリ予定なし入荷 / batch={$detail->batch?->client_batch_uuid} / line={$detail->client_line_uuid}";

        if ($sourceSchedule) {
            $note .= " / 元入荷予定ID={$sourceSchedule->id}";
        }

        return $note;
    }

    /**
     * @param  array<int>  $warehouseIds
     */
    private function resolveSchedule(array $warehouseIds, array $line, ?int $itemId, ?string &$reviewReason = null): ?WmsOrderIncomingSchedule
    {
        if (! empty($line['incoming_schedule_id'])) {
            $schedule = WmsOrderIncomingSchedule::query()
                ->whereIn('warehouse_id', $warehouseIds)
                ->whereKey((int) $line['incoming_schedule_id'])
                ->with('item')
                ->lockForUpdate()
                ->first();

            if (! $schedule) {
                $reviewReason = '指定された入荷予定が見つかりません。';
            }

            return $schedule;
        }

        if (! $itemId) {
            return null;
        }

        $query = WmsOrderIncomingSchedule::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('item_id', $itemId)
            ->whereIn('status', [
                IncomingScheduleStatus::PENDING->value,
                IncomingScheduleStatus::PARTIAL->value,
            ])
            ->with('item')
            ->lockForUpdate();

        if (filled($line['slip_number'] ?? null)) {
            $query->where('slip_number', trim((string) $line['slip_number']));
        }

        if (! empty($line['contractor_id'])) {
            $query->where('contractor_id', (int) $line['contractor_id']);
        }

        $matches = $query
            ->orderBy('expected_arrival_date')
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($matches->count() > 1) {
            $reviewReason = '条件に一致する未確定入荷予定が複数あります。';

            return null;
        }

        return $matches->first();
    }

    private function resolveItem(array $line, ?string &$reviewReason = null): ?object
    {
        if (! empty($line['item_id'])) {
            return DB::connection('sakemaru')
                ->table('items')
                ->where('id', (int) $line['item_id'])
                ->where('is_active', true)
                ->first();
        }

        $itemCode = trim((string) ($line['item_code'] ?? ''));
        if ($itemCode !== '') {
            $item = DB::connection('sakemaru')
                ->table('items')
                ->where('is_active', true)
                ->where('code', $itemCode)
                ->first();

            if ($item) {
                return $item;
            }
        }

        $scannedCode = trim((string) ($line['scanned_code'] ?? ''));
        if ($scannedCode === '') {
            return null;
        }

        $normalized = function_exists('mb_convert_kana')
            ? mb_convert_kana($scannedCode, 'as')
            : $scannedCode;

        $matches = DB::connection('sakemaru')
            ->table('items as i')
            ->leftJoin('item_search_information as isi', 'isi.item_id', '=', 'i.id')
            ->leftJoin('item_quantity_information as iqi', 'iqi.item_id', '=', 'i.id')
            ->where('i.is_active', true)
            ->where(function ($query) use ($normalized) {
                $query->where('i.code', $normalized)
                    ->orWhere('isi.search_string', $normalized)
                    ->orWhere('iqi.product_code', $normalized)
                    ->orWhere('iqi.own_code', $normalized)
                    ->orWhereRaw('LPAD(isi.search_string, 13, "0") = ?', [$normalized])
                    ->orWhereRaw('LPAD(iqi.product_code, 13, "0") = ?', [$normalized])
                    ->orWhereRaw('LPAD(iqi.own_code, 13, "0") = ?', [$normalized]);
            })
            ->select([
                'i.id',
                'i.code',
                'i.name',
                'i.capacity_case',
                'i.capacity_carton',
                'i.supplier_id',
            ])
            ->selectRaw(
                'MIN(CASE
                    WHEN i.code = ? THEN 0
                    WHEN isi.search_string = ? THEN 0
                    WHEN iqi.product_code = ? THEN 0
                    WHEN iqi.own_code = ? THEN 0
                    ELSE 1
                END) as match_rank',
                [$normalized, $normalized, $normalized, $normalized]
            )
            ->groupBy('i.id', 'i.code', 'i.name', 'i.capacity_case', 'i.capacity_carton', 'i.supplier_id')
            ->orderBy('match_rank')
            ->orderBy('i.code')
            ->limit(2)
            ->get();

        if ($matches->count() > 1) {
            $reviewReason = '商品候補が複数あります。';

            return null;
        }

        return $matches->first();
    }

    private function resolveItemContractor(int $warehouseId, int $itemId, int|string|null $contractorId = null): ?object
    {
        $query = DB::connection('sakemaru')
            ->table('item_contractors')
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId);

        if ($contractorId !== null && $contractorId !== '') {
            $query->where('contractor_id', (int) $contractorId);
        }

        return $query
            ->orderByDesc('is_auto_order')
            ->orderBy('id')
            ->first(['contractor_id', 'supplier_id']);
    }

    /**
     * @param  array<int>  $warehouseIds
     * @return Collection<int, WmsOrderIncomingSchedule>
     */
    private function findRecentEosConfirmedSchedules(array $warehouseIds, int $itemId, string $inspectionDate, array $line): Collection
    {
        $date = CarbonImmutable::parse($inspectionDate);
        $query = WmsOrderIncomingSchedule::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('item_id', $itemId)
            ->where('status', IncomingScheduleStatus::CONFIRMED->value)
            ->whereBetween('actual_arrival_date', [$date->subDays(2)->toDateString(), $date->toDateString()])
            ->eosSent()
            ->with('item')
            ->orderByDesc('actual_arrival_date')
            ->orderByDesc('id');

        if (filled($line['slip_number'] ?? null)) {
            $query->where('slip_number', trim((string) $line['slip_number']));
        }

        if (! empty($line['contractor_id'])) {
            $query->where('contractor_id', (int) $line['contractor_id']);
        }

        return $query->limit(2)->get();
    }

    private function resolveTotalPieces(array $line, int $capacityCase): int
    {
        if (isset($line['total_piece_quantity'])) {
            return max(0, (int) $line['total_piece_quantity']);
        }

        return ((int) ($line['case_quantity'] ?? 0) * $capacityCase)
            + (int) ($line['piece_quantity'] ?? 0);
    }

    private function piecesToScheduleUnits(int $pieceQuantity, WmsOrderIncomingSchedule $schedule): ?int
    {
        $quantityType = $schedule->quantity_type instanceof QuantityType
            ? $schedule->quantity_type
            : QuantityType::tryFrom((string) $schedule->quantity_type);

        if ($quantityType === QuantityType::PIECE || $quantityType === null || $quantityType === QuantityType::UNKNOWN) {
            return $pieceQuantity;
        }

        $capacity = $quantityType === QuantityType::CARTON
            ? max(1, (int) ($schedule->item?->capacity_carton ?? 1))
            : max(1, (int) ($schedule->item?->capacity_case ?? 1));

        if ($pieceQuantity % $capacity !== 0) {
            return null;
        }

        return (int) ($pieceQuantity / $capacity);
    }

    private function markNeedsReview(WmsIncomingAppInspectionDetail $detail, string $reason): WmsIncomingAppInspectionDetail
    {
        $detail->fill([
            'inspection_policy' => WmsIncomingAppInspectionDetail::POLICY_NEEDS_REVIEW,
            'result_status' => WmsIncomingAppInspectionDetail::RESULT_NEEDS_REVIEW,
            'review_reason' => $reason,
        ])->save();

        return $detail->fresh();
    }

    private function recordFailedLine(
        WmsIncomingAppInspectionBatch $batch,
        array $line,
        int $warehouseId,
        string $inspectedAt,
        string $errorMessage
    ): WmsIncomingAppInspectionDetail {
        return WmsIncomingAppInspectionDetail::query()->updateOrCreate(
            [
                'batch_id' => $batch->id,
                'client_line_uuid' => (string) ($line['client_line_uuid'] ?? uniqid('missing-line-', true)),
            ],
            [
                'warehouse_id' => $warehouseId,
                'incoming_schedule_id' => $line['incoming_schedule_id'] ?? null,
                'item_id' => $line['item_id'] ?? null,
                'item_code' => $line['item_code'] ?? null,
                'item_name' => $line['item_name'] ?? null,
                'scanned_code' => $line['scanned_code'] ?? null,
                'slip_number' => $line['slip_number'] ?? null,
                'contractor_id' => $line['contractor_id'] ?? null,
                'location_id' => $line['location_id'] ?? null,
                'inspection_policy' => WmsIncomingAppInspectionDetail::POLICY_NEEDS_REVIEW,
                'result_status' => WmsIncomingAppInspectionDetail::RESULT_ERROR,
                'review_reason' => $errorMessage,
                'inspected_case_quantity' => (int) ($line['case_quantity'] ?? 0),
                'inspected_piece_quantity' => (int) ($line['piece_quantity'] ?? 0),
                'inspected_total_piece_quantity' => (int) ($line['total_piece_quantity'] ?? 0),
                'inspected_at' => $line['inspected_at'] ?? $inspectedAt,
                'raw_payload' => $line,
            ]
        );
    }

    private function refreshBatchCounts(WmsIncomingAppInspectionBatch $batch): WmsIncomingAppInspectionBatch
    {
        $details = $batch->details()->get(['result_status']);
        $successStatuses = [
            WmsIncomingAppInspectionDetail::RESULT_CONFIRMED,
            WmsIncomingAppInspectionDetail::RESULT_APP_UNPLANNED_CREATED,
        ];
        $historyStatuses = [
            WmsIncomingAppInspectionDetail::RESULT_HISTORY_ONLY,
            WmsIncomingAppInspectionDetail::RESULT_EOS_ALREADY_CONFIRMED,
        ];

        $errorCount = $details->where('result_status', WmsIncomingAppInspectionDetail::RESULT_ERROR)->count();

        $batch->update([
            'status' => $errorCount > 0
                ? WmsIncomingAppInspectionBatch::STATUS_PARTIAL_FAILED
                : WmsIncomingAppInspectionBatch::STATUS_COMPLETED,
            'total_detail_count' => $details->count(),
            'success_count' => $details->whereIn('result_status', $successStatuses)->count(),
            'history_only_count' => $details->whereIn('result_status', $historyStatuses)->count(),
            'review_count' => $details->where('result_status', WmsIncomingAppInspectionDetail::RESULT_NEEDS_REVIEW)->count(),
            'error_count' => $errorCount,
        ]);

        return $batch->fresh();
    }

    private function formatDetail(WmsIncomingAppInspectionDetail $detail): array
    {
        return [
            'id' => (int) $detail->id,
            'client_line_uuid' => $detail->client_line_uuid,
            'incoming_schedule_id' => $detail->incoming_schedule_id ? (int) $detail->incoming_schedule_id : null,
            'linked_confirmed_schedule_id' => $detail->linked_confirmed_schedule_id ? (int) $detail->linked_confirmed_schedule_id : null,
            'created_schedule_id' => $detail->created_schedule_id ? (int) $detail->created_schedule_id : null,
            'item_id' => $detail->item_id ? (int) $detail->item_id : null,
            'item_code' => $detail->item_code,
            'item_name' => $detail->item_name,
            'inspection_policy' => $detail->inspection_policy,
            'result_status' => $detail->result_status,
            'review_reason' => $detail->review_reason,
            'inspected_total_piece_quantity' => (int) $detail->inspected_total_piece_quantity,
            'applied_piece_quantity' => (int) $detail->applied_piece_quantity,
            'shortage_piece_quantity' => (int) $detail->shortage_piece_quantity,
        ];
    }

    /**
     * @return array<int>
     */
    private function matchingWarehouseIds(int $warehouseId): array
    {
        $warehouseIds = WarehouseResolver::resolveAllWarehouseIds($warehouseId);

        return $warehouseIds !== [] ? array_map('intval', $warehouseIds) : [$warehouseId];
    }
}
