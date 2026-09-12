<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\AutoOrder\OrderSource;
use App\Enums\QuantityType;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsContractorSetting;
use App\Models\WmsIncomingImportError;
use App\Models\WmsIncomingReceivedDetail;
use App\Models\WmsIncomingReceivedFile;
use App\Models\WmsIncomingReceivedSlip;
use App\Models\WmsOrderIncomingSchedule;
use App\Models\WmsOrderSlipNumberAssignment;
use App\Services\AutoOrder\IncomingParsers\JxIncomingParser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * 入荷受信データサービス
 *
 * JX/CSVの受信データをパースし、入荷予定と照合する
 */
class IncomingReceiveService
{
    /** 送料JANコード（単価比較対象外） */
    private const SHIPPING_JAN_CODE = '9999999999996';

    private ?IncomingPriceCheckSourceRecorder $priceCheckSourceRecorder = null;

    /**
     * JXデータをパースして保存
     */
    public function parseJxData(
        string $content,
        string $filename,
        ?int $contractorId = null,
        array $metadata = []
    ): WmsIncomingReceivedFile {
        $parser = new JxIncomingParser;

        return $parser->parseWithMetadata($content, $filename, $contractorId, $metadata);
    }

    /**
     * JX受信原本をS3へ保存
     */
    public function saveRawJxData(string $content, string $filename): array
    {
        $date = now()->format('Y-m-d');
        $timestamp = now()->format('YmdHisv');
        $safeFilename = preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename);
        $path = "jx-client/received-data/{$date}/{$timestamp}_{$safeFilename}";

        Storage::disk('s3')->put($path, $content);

        return [
            'raw_file_path' => "s3:{$path}",
            'raw_file_size' => strlen($content),
            'raw_sha256' => hash('sha256', $content),
        ];
    }

    /**
     * 保存済み原本から新しい取込レコードを作成する
     */
    public function reparseFromRaw(WmsIncomingReceivedFile $file): WmsIncomingReceivedFile
    {
        if (empty($file->raw_file_path)) {
            throw new \RuntimeException('保存済みのJX受信原本がありません。');
        }

        $path = str_starts_with($file->raw_file_path, 's3:')
            ? substr($file->raw_file_path, 3)
            : $file->raw_file_path;

        if (! Storage::disk('s3')->exists($path)) {
            throw new \RuntimeException("JX受信原本がS3に存在しません: {$file->raw_file_path}");
        }

        $content = Storage::disk('s3')->get($path);
        $filename = 'reparse_'.now()->format('YmdHis').'_'.($file->filename ?? basename($path));

        return $this->parseJxData($content, $filename, $file->contractor_id, [
            'raw_file_path' => $file->raw_file_path,
            'raw_file_size' => $file->raw_file_size ?? strlen($content),
            'raw_sha256' => $file->raw_sha256 ?? hash('sha256', $content),
            'received_message_id' => $file->received_message_id,
            'get_request_path' => $file->get_request_path,
            'get_response_path' => $file->get_response_path,
            'confirm_status' => $file->confirm_status,
            'confirmed_at' => $file->confirmed_at,
            'confirm_request_path' => $file->confirm_request_path,
            'confirm_response_path' => $file->confirm_response_path,
            'confirm_error_message' => $file->confirm_error_message,
        ]);
    }

    /**
     * 受信ファイルの伝票を入荷予定と照合
     */
    public function matchWithSchedules(WmsIncomingReceivedFile $file): array
    {
        $result = DB::connection('sakemaru')->transaction(function () use ($file): array {
            $lockedFile = WmsIncomingReceivedFile::query()
                ->whereKey($file->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedFile->isWorkflowTerminal() || $lockedFile->status === WmsIncomingReceivedFile::STATUS_MATCHED) {
                Log::info('[IncomingReceiveService] 照合済みまたは終端状態の受信ファイルのため照合をスキップしました', [
                    'file_id' => $lockedFile->id,
                    'status' => $lockedFile->status,
                ]);

                return $this->summarizeFileMatchStatus($lockedFile);
            }

            $matchedCount = 0;
            $unmatchedCount = 0;
            $shortageCount = 0;

            $slips = $lockedFile->slips()->with('details')->get();

            foreach ($slips as $slip) {
                $result = $this->matchSlip($slip, $lockedFile);

                match ($result) {
                    'MATCHED' => $matchedCount++,
                    'SHORTAGE', 'PARTIAL' => $shortageCount++,
                    default => $unmatchedCount++,
                };
            }

            // ファイルステータス更新
            $lockedFile->update([
                'status' => $unmatchedCount === 0
                    ? WmsIncomingReceivedFile::STATUS_MATCHED
                    : WmsIncomingReceivedFile::STATUS_PENDING,
            ]);

            Log::info('[IncomingReceiveService] 照合完了', [
                'file_id' => $lockedFile->id,
                'matched' => $matchedCount,
                'unmatched' => $unmatchedCount,
                'shortage' => $shortageCount,
            ]);

            return [
                'matched' => $matchedCount,
                'unmatched' => $unmatchedCount,
                'shortage' => $shortageCount,
                'total' => $slips->count(),
            ];
        });

        $file->refresh();

        return $result;
    }

    private function summarizeFileMatchStatus(WmsIncomingReceivedFile $file): array
    {
        $statuses = $file->slips()->pluck('match_status');

        return [
            'matched' => $statuses->filter(fn (?string $status): bool => $status === 'MATCHED')->count(),
            'unmatched' => $statuses
                ->filter(fn (?string $status): bool => ! in_array($status, ['MATCHED', 'PARTIAL', 'SHORTAGE'], true))
                ->count(),
            'shortage' => $statuses->filter(fn (?string $status): bool => in_array($status, ['PARTIAL', 'SHORTAGE'], true))->count(),
            'total' => $statuses->count(),
        ];
    }

    /**
     * 伝票単位の照合
     */
    private function matchSlip(WmsIncomingReceivedSlip $slip, WmsIncomingReceivedFile $file): string
    {
        // 伝票番号だけでは仕入先間で重複し得るため、仕入先×伝票番号で照合する。
        $schedules = $this->findSchedulesForSlip($slip);

        if ($schedules->isEmpty()) {
            if ($this->isJxSlip($slip)) {
                if (! $this->findJxAssignmentForSlip($slip)) {
                    $confirmedScheduleIds = $this->receivedDetailScheduleIdsForSlip($slip);
                    if ($confirmedScheduleIds !== []) {
                        $slip->update([
                            'matched_schedule_id' => $confirmedScheduleIds[0],
                            'match_status' => 'MATCHED',
                        ]);
                        $this->resolveUnassignedJxReviewErrors($slip);

                        return 'MATCHED';
                    }

                    $this->markUnassignedJxSlipForReview($slip, $file);

                    return 'NO_ASSIGNMENT';
                }

                $this->recordSlipNotFoundWarning($file, $slip);

                $slip->update(['match_status' => 'NOT_FOUND']);

                return 'NOT_FOUND';
            }

            $this->recordSlipNotFoundWarning($file, $slip);

            // 未照合 → 受信データから入荷予定を新規作成
            $creationResult = $this->createSchedulesFromSlip($slip);

            if ($creationResult['created'] > 0) {
                if ($slip->details()->where('match_status', 'NOT_FOUND')->exists()) {
                    $slip->update(['match_status' => 'NOT_FOUND']);

                    return 'NOT_FOUND';
                }

                $slip->update(['match_status' => 'CREATED']);
                Log::info('[IncomingReceiveService] 未照合伝票から入荷予定を新規作成', [
                    'slip_id' => $slip->id,
                    'slip_number' => $slip->slip_number,
                    'created_count' => $creationResult['created'],
                ]);

                return 'MATCHED';
            }

            $slip->update(['match_status' => 'NOT_FOUND']);

            return 'NOT_FOUND';
        }

        $matchableSchedules = $schedules
            ->filter(fn (WmsOrderIncomingSchedule $schedule) => $this->canApplyReceivedData($schedule))
            ->values();

        if ($matchableSchedules->isEmpty()) {
            $creationResult = $this->createSchedulesFromSlip($slip, $schedules);

            if ($creationResult['schedule_ids'] !== []) {
                $schedules = WmsOrderIncomingSchedule::query()
                    ->with('item')
                    ->whereIn('id', $creationResult['schedule_ids'])
                    ->orderBy('id')
                    ->get();
                $matchableSchedules = $schedules;

                Log::info('[IncomingReceiveService] 適用済み伝票の再入荷を別入荷予定として作成', [
                    'slip_id' => $slip->id,
                    'slip_number' => $slip->slip_number,
                    'created_count' => $creationResult['created'],
                ]);
            } else {
                $slip->update([
                    'matched_schedule_id' => null,
                    'match_status' => 'NOT_FOUND',
                ]);

                return 'NOT_FOUND';
            }
        } elseif ($matchableSchedules->count() < $schedules->count()) {
            $creationResult = $this->createSchedulesFromSlip($slip, $schedules);

            if ($creationResult['schedule_ids'] !== []) {
                $createdOrExistingSchedules = WmsOrderIncomingSchedule::query()
                    ->with('item')
                    ->whereIn('id', $creationResult['schedule_ids'])
                    ->orderBy('id')
                    ->get();

                $schedules = $schedules
                    ->merge($createdOrExistingSchedules)
                    ->unique('id')
                    ->values();
                $matchableSchedules = $schedules
                    ->filter(fn (WmsOrderIncomingSchedule $schedule) => $this->canApplyReceivedData($schedule))
                    ->values();

                Log::info('[IncomingReceiveService] 一部適用済み伝票の再入荷を別入荷予定として作成', [
                    'slip_id' => $slip->id,
                    'slip_number' => $slip->slip_number,
                    'created_count' => $creationResult['created'],
                    'schedule_ids' => $creationResult['schedule_ids'],
                ]);
            }
        }

        // 入荷予定のordering_codeマップを構築（Step 1用）
        $schedulesByOrderingCode = [];
        foreach ($matchableSchedules as $schedule) {
            if ($schedule->search_code) {
                foreach (explode(',', $schedule->search_code) as $code) {
                    $code = trim($code);
                    if ($code !== '') {
                        $schedulesByOrderingCode[$code] = $schedule;
                    }
                }
            }
        }

        // 明細レベルの照合
        $hasShortage = false;
        $hasPartial = false;
        $hasUnmatched = false;
        $details = $slip->details;
        $matchedSchedule = $matchableSchedules->first();
        $matchedDetailsByScheduleId = [];

        foreach ($details as $detail) {
            $matchResult = $this->matchDetailWithSchedules(
                $detail, $matchableSchedules, $schedulesByOrderingCode, $file
            );

            if (
                $matchResult['matched_schedule']
                && $detail->matched_item_id
                && (int) $matchResult['matched_schedule']->item_id === (int) $detail->matched_item_id
            ) {
                $matchedSchedule = $matchResult['matched_schedule'];
                $matchedDetailsByScheduleId[$matchedSchedule->id]['schedule'] = $matchedSchedule;
                $matchedDetailsByScheduleId[$matchedSchedule->id]['details'][] = $detail;
            }

            if ($detail->match_status === 'SHORTAGE') {
                $hasShortage = true;
            } elseif ($detail->match_status === 'PARTIAL') {
                $hasPartial = true;
            } elseif ($detail->match_status === 'NOT_FOUND') {
                $hasUnmatched = true;
            }
        }

        if ($matchedDetailsByScheduleId === [] || $hasUnmatched) {
            $slip->update([
                'matched_schedule_id' => null,
                'match_status' => 'NOT_FOUND',
            ]);

            return 'NOT_FOUND';
        }

        // 互換性維持のため、代表scheduleをslip-levelにも保持する
        $slip->update(['matched_schedule_id' => $matchedSchedule->id]);

        // shipped_quantity と仕入先単価を書き戻し
        foreach ($matchedDetailsByScheduleId as $matched) {
            $scheduleDetails = collect($matched['details']);
            $scheduleShippedPieces = $scheduleDetails
                ->reject(fn ($detail) => $detail->is_shortage)
                ->sum(fn ($detail) => (int) $detail->total_quantity);

            $this->writebackShippedData($matched['schedule'], $scheduleDetails, $scheduleShippedPieces, $file);
        }

        // 伝票ステータス決定
        $status = 'MATCHED';
        if ($hasShortage) {
            $status = 'SHORTAGE';
        } elseif ($hasPartial) {
            $status = 'PARTIAL';
        }

        $slip->update([
            'match_status' => $status,
            'shortage_count' => $details
                ->filter(fn ($detail): bool => (bool) $detail->is_shortage || $detail->match_status === 'SHORTAGE')
                ->count(),
        ]);

        return $status;
    }

    private function findSchedulesForSlip(WmsIncomingReceivedSlip $slip)
    {
        if ($this->isJxSlip($slip)) {
            return $this->findSchedulesForJxAssignment($slip);
        }

        $contractorIds = $this->resolveSlipContractorIds($slip);

        if ($contractorIds === []) {
            return collect();
        }

        return WmsOrderIncomingSchedule::query()
            ->with('item')
            ->where('slip_number', $slip->slip_number)
            ->whereIn('contractor_id', $contractorIds)
            ->orderBy('id')
            ->get();
    }

    private function isJxSlip(WmsIncomingReceivedSlip $slip): bool
    {
        return strtoupper((string) ($slip->file?->format_type ?? '')) === 'JX';
    }

    private function findSchedulesForJxAssignment(WmsIncomingReceivedSlip $slip)
    {
        $assignment = $this->findJxAssignmentForSlip($slip);
        if (! $assignment) {
            return collect();
        }

        $candidateIds = $this->assignmentCandidateIds($assignment);
        if ($candidateIds === []) {
            WmsIncomingImportError::firstOrCreate([
                'received_file_id' => $slip->received_file_id,
                'received_slip_id' => $slip->id,
                'error_code' => 'EOS_ASSIGNMENT_EMPTY',
            ], [
                'error_type' => 'ERROR',
                'error_message' => "送信済みEOS伝票番号割当に発注候補IDがありません: slip_number={$slip->slip_number}",
                'raw_data' => ['assignment_id' => $assignment->id],
            ]);

            return collect();
        }

        $schedules = WmsOrderIncomingSchedule::query()
            ->with('item')
            ->whereIn('order_candidate_id', $candidateIds)
            ->orderBy('id')
            ->get();

        if ($schedules->isEmpty()) {
            WmsIncomingImportError::firstOrCreate([
                'received_file_id' => $slip->received_file_id,
                'received_slip_id' => $slip->id,
                'error_code' => 'EOS_ASSIGNMENT_SCHEDULE_NOT_FOUND',
            ], [
                'error_type' => 'ERROR',
                'error_message' => "EOS伝票番号割当に対応する入荷予定が見つかりません: slip_number={$slip->slip_number}",
                'raw_data' => [
                    'assignment_id' => $assignment->id,
                    'order_candidate_ids' => $candidateIds,
                ],
            ]);

            return collect();
        }

        if (! $this->assignmentMatchesReceivedContractor($assignment, $slip, $schedules)) {
            WmsIncomingImportError::firstOrCreate([
                'received_file_id' => $slip->received_file_id,
                'received_slip_id' => $slip->id,
                'error_code' => 'EOS_CONTRACTOR_MISMATCH',
            ], [
                'error_type' => 'ERROR',
                'error_message' => "EOS受信伝票の仕入先が送信済み割当と一致しません: slip_number={$slip->slip_number}",
                'raw_data' => [
                    'assignment_id' => $assignment->id,
                    'b_contractor_code' => $slip->b_contractor_code,
                    'file_contractor_id' => $slip->file?->contractor_id,
                ],
            ]);

            return collect();
        }

        return $schedules;
    }

    private function findJxAssignmentForSlip(WmsIncomingReceivedSlip $slip): ?WmsOrderSlipNumberAssignment
    {
        return WmsOrderSlipNumberAssignment::query()
            ->with('document')
            ->where('slip_number', $slip->slip_number)
            ->whereIn('status', [
                WmsOrderSlipNumberAssignment::STATUS_ACTIVE,
                WmsOrderSlipNumberAssignment::STATUS_TRANSMITTED,
            ])
            ->orderByDesc('id')
            ->first();
    }

    private function markUnassignedJxSlipForReview(WmsIncomingReceivedSlip $slip, WmsIncomingReceivedFile $file): void
    {
        $this->recordJxAssignmentNotFoundError($slip);
        $this->recordSlipNotFoundWarning($file, $slip);
        $this->recordUnassignedJxResolutionErrors($slip, $file);

        $slip->loadMissing('details');
        $shortageCount = 0;

        foreach ($slip->details as $detail) {
            $itemId = $this->resolveItemId($detail);

            if (! $itemId) {
                $detail->update(['match_status' => 'NOT_FOUND']);

                WmsIncomingImportError::firstOrCreate([
                    'received_file_id' => $file->id,
                    'received_slip_id' => $slip->id,
                    'received_detail_id' => $detail->id,
                    'error_code' => 'ITEM_NOT_FOUND',
                ], [
                    'error_type' => 'ERROR',
                    'error_message' => "商品を特定できません: JAN={$detail->d_jan_code}, 商品CD={$detail->d_item_code}",
                    'item_code' => $detail->d_jan_code ?: $detail->d_item_code,
                    'raw_data' => [
                        'd_jan_code' => $detail->d_jan_code,
                        'd_item_code' => $detail->d_item_code,
                        'd_product_name' => $detail->d_product_name,
                    ],
                ]);

                continue;
            }

            if ($detail->is_shortage || (int) $detail->total_quantity === 0) {
                $shortageCount++;
            }

            $detail->update([
                'matched_item_id' => $itemId,
                'expected_quantity' => null,
                'match_status' => 'NO_ASSIGNMENT',
            ]);
        }

        $slip->update([
            'matched_schedule_id' => null,
            'match_status' => 'NO_ASSIGNMENT',
            'shortage_count' => $shortageCount,
        ]);

        Log::info('[IncomingReceiveService] 未割当JX受信伝票をレビュー対象として記録', [
            'file_id' => $file->id,
            'slip_id' => $slip->id,
            'slip_number' => $slip->slip_number,
            'b_shop_code' => $slip->b_shop_code,
            'b_contractor_code' => $slip->b_contractor_code,
        ]);
    }

    private function recordUnassignedJxResolutionErrors(
        WmsIncomingReceivedSlip $slip,
        WmsIncomingReceivedFile $file
    ): void {
        if ($this->resolveCreateContractorId($slip) === null) {
            WmsIncomingImportError::firstOrCreate([
                'received_file_id' => $file->id,
                'received_slip_id' => $slip->id,
                'error_code' => 'EOS_UNASSIGNED_CONTRACTOR_NOT_RESOLVED',
            ], [
                'error_type' => 'ERROR',
                'error_message' => "未割当EOS受信伝票の仕入先を解決できません: slip_number={$slip->slip_number}",
                'raw_data' => [
                    'slip_number' => $slip->slip_number,
                    'b_contractor_code' => $slip->b_contractor_code,
                    'file_contractor_id' => $file->contractor_id,
                ],
            ]);
        }

        if (! $this->resolveWarehouseForSlip($slip)) {
            WmsIncomingImportError::firstOrCreate([
                'received_file_id' => $file->id,
                'received_slip_id' => $slip->id,
                'error_code' => 'EOS_UNASSIGNED_WAREHOUSE_NOT_RESOLVED',
            ], [
                'error_type' => 'ERROR',
                'error_message' => "未割当EOS受信伝票の倉庫を解決できません: slip_number={$slip->slip_number}, shop_code={$slip->b_shop_code}",
                'raw_data' => [
                    'slip_number' => $slip->slip_number,
                    'b_shop_code' => $slip->b_shop_code,
                ],
            ]);
        }

        $slip->loadMissing('details');
        if ($slip->details->isEmpty() || ! $slip->details->contains(fn ($detail): bool => (int) $detail->total_quantity > 0)) {
            WmsIncomingImportError::firstOrCreate([
                'received_file_id' => $file->id,
                'received_slip_id' => $slip->id,
                'error_code' => 'EOS_UNASSIGNED_NO_RECEIVED_QUANTITY',
            ], [
                'error_type' => 'ERROR',
                'error_message' => "未割当EOS受信伝票に入荷数量のある明細がありません: slip_number={$slip->slip_number}",
                'raw_data' => [
                    'slip_number' => $slip->slip_number,
                    'detail_count' => $slip->details->count(),
                ],
            ]);
        }
    }

    private function recordJxAssignmentNotFoundError(WmsIncomingReceivedSlip $slip): void
    {
        WmsIncomingImportError::firstOrCreate([
            'received_file_id' => $slip->received_file_id,
            'received_slip_id' => $slip->id,
            'error_code' => 'EOS_ASSIGNMENT_NOT_FOUND',
        ], [
            'error_type' => 'ERROR',
            'error_message' => "送信済みEOS伝票番号割当が見つかりません: slip_number={$slip->slip_number}",
            'raw_data' => [
                'slip_number' => $slip->slip_number,
                'b_contractor_code' => $slip->b_contractor_code,
            ],
        ]);
    }

    private function recordSlipNotFoundWarning(WmsIncomingReceivedFile $file, WmsIncomingReceivedSlip $slip): void
    {
        WmsIncomingImportError::firstOrCreate([
            'received_file_id' => $file->id,
            'received_slip_id' => $slip->id,
            'error_code' => 'SLIP_NOT_FOUND',
        ], [
            'error_type' => 'WARNING',
            'error_message' => "伝票番号 {$slip->slip_number} に対応する入荷予定が見つかりません",
        ]);
    }

    private function assignmentCandidateIds(WmsOrderSlipNumberAssignment $assignment): array
    {
        return collect($assignment->order_candidate_ids ?? [])
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function assignmentMatchesReceivedContractor(WmsOrderSlipNumberAssignment $assignment, WmsIncomingReceivedSlip $slip, $schedules): bool
    {
        $receivedContractorIds = $this->resolveReceivedSlipContractorIds($slip);
        if ($receivedContractorIds === []) {
            return true;
        }

        $assignmentContractorIds = collect([$assignment->document?->contractor_id])
            ->merge($schedules->pluck('contractor_id'))
            ->filter()
            ->flatMap(fn ($id) => $this->expandContractorIds((int) $id))
            ->unique()
            ->values()
            ->all();

        if ($assignmentContractorIds === []) {
            return true;
        }

        return array_intersect($receivedContractorIds, $assignmentContractorIds) !== [];
    }

    private function resolveReceivedSlipContractorIds(WmsIncomingReceivedSlip $slip): array
    {
        $ids = $this->resolveContractorIdsFromReceivedCode($slip->b_contractor_code);

        $fileContractorId = $slip->file?->contractor_id;
        if ($fileContractorId) {
            $ids = array_merge($ids, $this->expandContractorIds((int) $fileContractorId));
        }

        return array_values(array_unique($ids));
    }

    private function resolveSlipContractorIds(WmsIncomingReceivedSlip $slip): array
    {
        $ids = $this->resolveContractorIdsFromReceivedCode($slip->b_contractor_code);
        if ($ids !== []) {
            return $ids;
        }

        $fileContractorId = $slip->file?->contractor_id;
        if ($fileContractorId) {
            return $this->expandContractorIds((int) $fileContractorId);
        }

        return $this->resolveUniqueContractorIdsFromExistingSlip($slip);
    }

    private function resolveContractorIdsFromReceivedCode(?string $contractorCode): array
    {
        $contractorIds = $this->resolveDirectContractorIdsFromReceivedCode($contractorCode);

        $expandedIds = [];
        foreach ($contractorIds as $contractorId) {
            $expandedIds = array_merge($expandedIds, $this->expandContractorIds($contractorId));
        }

        return array_values(array_unique($expandedIds));
    }

    private function resolveDirectContractorIdsFromReceivedCode(?string $contractorCode): array
    {
        $contractorCode = trim((string) $contractorCode);
        if ($contractorCode === '') {
            return [];
        }

        $withoutLeadingZeros = ltrim($contractorCode, '0');
        $codes = array_values(array_unique(array_filter([
            $contractorCode,
            $withoutLeadingZeros,
            $withoutLeadingZeros !== '' ? str_pad($withoutLeadingZeros, 4, '0', STR_PAD_LEFT) : null,
        ])));

        return Contractor::query()
            ->whereIn('code', $codes)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function resolveUniqueContractorIdsFromExistingSlip(WmsIncomingReceivedSlip $slip): array
    {
        $contractorIds = WmsOrderIncomingSchedule::query()
            ->where('slip_number', $slip->slip_number)
            ->whereNotNull('contractor_id')
            ->distinct()
            ->pluck('contractor_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (count($contractorIds) !== 1) {
            return [];
        }

        return $this->expandContractorIds($contractorIds[0]);
    }

    private function expandContractorIds(int $contractorId): array
    {
        return array_values(array_unique(array_map(
            'intval',
            WmsContractorSetting::getContractorIdsWithChildren($contractorId)
        )));
    }

    /**
     * 明細と入荷予定の3段階マッチング
     *
     * Step 1: ordering_code(search_code) と d_jan_code の比較
     * Step 2: item_search_information から検索
     * Step 3: ITEM_NOT_FOUND エラー記録
     */
    private function matchDetailWithSchedules(
        $detail,
        $schedules,
        array $schedulesByOrderingCode,
        WmsIncomingReceivedFile $file
    ): array {
        $janCode = $detail->d_jan_code;
        $itemCode = $detail->d_item_code;
        $matchedSchedule = null;
        $matchedItemId = null;

        // Step 1: ordering_code（search_code）と d_jan_code で照合
        if ($janCode && isset($schedulesByOrderingCode[$janCode])) {
            $matchedSchedule = $schedulesByOrderingCode[$janCode];
            $matchedItemId = $matchedSchedule->item_id;
        }

        // Step 1b: d_item_code でも試行
        if (! $matchedItemId && $itemCode) {
            foreach ($schedules as $schedule) {
                $scheduleItem = $schedule->item;
                if ($scheduleItem && $scheduleItem->code === $itemCode) {
                    $matchedSchedule = $schedule;
                    $matchedItemId = $schedule->item_id;
                    break;
                }
            }
        }

        // Step 2: item_search_information から検索
        if (! $matchedItemId) {
            $searchCodes = $this->normalizedIncomingSearchCodes($detail);
            if (! empty($searchCodes)) {
                $itemId = DB::connection('sakemaru')
                    ->table('item_search_information')
                    ->whereIn('search_string', $searchCodes)
                    ->value('item_id');

                if ($itemId) {
                    $matchedItemId = $itemId;
                    // scheduleからも特定
                    $matchedSchedule = $schedules->firstWhere('item_id', $itemId);
                }
            }
        }

        // Step 3: 商品不明
        if (! $matchedItemId) {
            WmsIncomingImportError::updateOrCreate([
                'received_file_id' => $file->id,
                'received_slip_id' => $detail->received_slip_id,
                'received_detail_id' => $detail->id,
                'error_code' => 'ITEM_NOT_FOUND',
            ], [
                'error_type' => 'ERROR',
                'error_message' => "商品を特定できません: JAN={$janCode}, 商品CD={$itemCode}",
                'item_code' => $janCode ?: $itemCode,
                'raw_data' => [
                    'd_jan_code' => $janCode,
                    'd_item_code' => $itemCode,
                    'd_product_name' => $detail->d_product_name,
                ],
            ]);

            $detail->update(['match_status' => 'NOT_FOUND']);

            return ['matched_schedule' => null];
        }

        if (! $matchedSchedule || (int) $matchedSchedule->item_id !== (int) $matchedItemId) {
            WmsIncomingImportError::updateOrCreate([
                'received_file_id' => $file->id,
                'received_slip_id' => $detail->received_slip_id,
                'received_detail_id' => $detail->id,
                'error_code' => 'SCHEDULE_ITEM_NOT_FOUND',
            ], [
                'error_type' => 'ERROR',
                'error_message' => "伝票内の入荷予定に商品がありません: slip_id={$detail->received_slip_id}, item_id={$matchedItemId}",
                'item_code' => $janCode ?: $itemCode,
                'raw_data' => [
                    'd_jan_code' => $janCode,
                    'd_item_code' => $itemCode,
                    'd_product_name' => $detail->d_product_name,
                    'matched_item_id' => $matchedItemId,
                ],
            ]);

            $detail->update([
                'matched_item_id' => $matchedItemId,
                'matched_schedule_id' => null,
                'match_status' => 'NOT_FOUND',
            ]);

            return ['matched_schedule' => null];
        }

        if (! $this->canApplyReceivedData($matchedSchedule)) {
            WmsIncomingImportError::updateOrCreate([
                'received_file_id' => $file->id,
                'received_slip_id' => $detail->received_slip_id,
                'received_detail_id' => $detail->id,
                'error_code' => 'SCHEDULE_STATUS_NOT_APPLICABLE',
            ], [
                'error_type' => 'ERROR',
                'error_message' => "入荷予定が受信適用できない状態です: schedule_id={$matchedSchedule->id}, status={$this->scheduleStatusValue($matchedSchedule)}",
                'item_code' => $janCode ?: $itemCode,
                'raw_data' => [
                    'd_jan_code' => $janCode,
                    'd_item_code' => $itemCode,
                    'd_product_name' => $detail->d_product_name,
                    'matched_item_id' => $matchedItemId,
                    'matched_schedule_id' => $matchedSchedule->id,
                ],
            ]);

            $detail->update([
                'matched_item_id' => $matchedItemId,
                'matched_schedule_id' => null,
                'match_status' => 'NOT_FOUND',
            ]);

            return ['matched_schedule' => null];
        }

        // 商品一致 → 数量照合
        $detail->update([
            'matched_item_id' => $matchedItemId,
            'matched_schedule_id' => $matchedSchedule?->id,
        ]);

        $expectedQty = $matchedSchedule
            ? $this->scheduleQuantityAsPieces($matchedSchedule)
            : 0;
        $detail->update(['expected_quantity' => $expectedQty]);

        if ($detail->is_shortage || $detail->total_quantity === 0) {
            $detail->update(['match_status' => 'SHORTAGE']);
        } elseif ($detail->total_quantity < $expectedQty) {
            $detail->update(['match_status' => 'PARTIAL']);
        } else {
            $detail->update(['match_status' => 'MATCHED']);
        }

        return ['matched_schedule' => $matchedSchedule ?? $schedules->first()];
    }

    /**
     * shipped_quantity と仕入先単価を入荷予定に書き戻し
     */
    private function writebackShippedData(
        WmsOrderIncomingSchedule $schedule,
        $details,
        int $totalShippedPieces,
        WmsIncomingReceivedFile $file
    ): void {
        // 対象商品の明細を取得
        $matchedDetail = $details->firstWhere('matched_item_id', $schedule->item_id);

        $priceData = $matchedDetail
            ? $this->receivedPriceWritebackData($schedule, $matchedDetail)
            : [
                'partner_unit_price' => null,
                'partner_case_price' => null,
                'price_type' => 'CASE',
            ];

        $quantityData = $this->scheduleQuantityWritebackData($schedule, $details, $totalShippedPieces);

        $schedule->update(array_merge($quantityData, $priceData, [
            'is_receive_matched' => true,
        ]));

        // 単価不一致チェック（送料は除外）
        $janCode = $matchedDetail?->d_jan_code;
        if ($janCode !== self::SHIPPING_JAN_CODE && $matchedDetail) {
            $this->checkPriceMismatch($schedule, $file, $matchedDetail);
        }

        foreach ($details as $detail) {
            $this->recordPriceCheckSource($file, $detail, $schedule);
        }
    }

    private function canApplyReceivedData(WmsOrderIncomingSchedule $schedule): bool
    {
        return in_array($this->scheduleStatusValue($schedule), [
            IncomingScheduleStatus::PENDING->value,
            IncomingScheduleStatus::PARTIAL->value,
        ], true);
    }

    private function scheduleStatusValue(WmsOrderIncomingSchedule $schedule): string
    {
        return $schedule->status instanceof IncomingScheduleStatus
            ? $schedule->status->value
            : (string) $schedule->status;
    }

    /**
     * 単価不一致チェック
     */
    private function checkPriceMismatch(
        WmsOrderIncomingSchedule $schedule,
        WmsIncomingReceivedFile $file,
        $detail
    ): void {
        $priceType = $schedule->price_type;
        $hasMismatch = false;
        $expectedPrice = null;
        $actualPrice = null;

        if ($priceType === 'CASE' && $schedule->case_price !== null && $schedule->partner_case_price !== null) {
            if ((float) $schedule->case_price !== (float) $schedule->partner_case_price) {
                $hasMismatch = true;
                $expectedPrice = $schedule->case_price;
                $actualPrice = $schedule->partner_case_price;
            }
        } elseif ($priceType === 'PIECE' && $schedule->unit_price !== null && $schedule->partner_unit_price !== null) {
            if ((float) $schedule->unit_price !== (float) $schedule->partner_unit_price) {
                $hasMismatch = true;
                $expectedPrice = $schedule->unit_price;
                $actualPrice = $schedule->partner_unit_price;
            }
        }

        if ($hasMismatch) {
            WmsIncomingImportError::updateOrCreate([
                'received_file_id' => $file->id,
                'received_slip_id' => $detail->received_slip_id,
                'received_detail_id' => $detail->id,
                'error_code' => 'PRICE_MISMATCH',
            ], [
                'error_type' => 'WARNING',
                'error_message' => "単価不一致（{$priceType}）: 自社={$expectedPrice} vs 仕入先={$actualPrice}",
                'item_code' => $detail->d_jan_code ?: $detail->d_item_code,
                'expected_price' => $expectedPrice,
                'actual_price' => $actualPrice,
            ]);
        }
    }

    /**
     * 未照合伝票から入荷予定を新規作成
     *
     * 各明細（detail）ごとに wms_order_incoming_schedules を1件作成
     *
     * @return array{created: int, schedule_ids: array<int>}
     */
    private function createSchedulesFromSlip(
        WmsIncomingReceivedSlip $slip,
        $templateSchedules = null
    ): array {
        // 倉庫特定: b_shop_code（4桁ゼロ埋め）→ warehouses.code（ltrim('0')で照合）
        $warehouse = $this->resolveWarehouseForSlip($slip);

        if (! $warehouse) {
            Log::warning('[IncomingReceiveService] 倉庫コード解決失敗', [
                'slip_id' => $slip->id,
                'b_shop_code' => $slip->b_shop_code,
            ]);

            return [
                'created' => 0,
                'schedule_ids' => [],
            ];
        }

        // 発注先特定: Bレコードコード → 既存入荷予定 → 受信ファイルの順で一意に解決
        $contractorId = $this->resolveCreateContractorId($slip, $templateSchedules);
        $contractor = $contractorId ? Contractor::find($contractorId) : null;
        $receivedFile = $slip->file;

        // 日付パース
        $orderDate = $this->parseJxDate($slip->b_order_date);
        $deliveryDate = $this->parseJxDate($slip->b_delivery_date);

        $details = $slip->details;
        $createdCount = 0;
        $createdScheduleIds = [];

        foreach ($details as $detail) {
            // 3段階で商品特定
            $itemId = $this->resolveItemId($detail)
                ?? $this->resolveTemplateItemId($templateSchedules, $detail);

            if (! $itemId) {
                $detail->update(['match_status' => 'NOT_FOUND']);

                WmsIncomingImportError::firstOrCreate([
                    'received_file_id' => $receivedFile?->id,
                    'received_slip_id' => $slip->id,
                    'received_detail_id' => $detail->id,
                    'error_code' => 'ITEM_NOT_FOUND',
                ], [
                    'error_type' => 'ERROR',
                    'error_message' => "商品を特定できません: JAN={$detail->d_jan_code}, 商品CD={$detail->d_item_code}",
                    'item_code' => $detail->d_jan_code ?: $detail->d_item_code,
                    'raw_data' => [
                        'd_jan_code' => $detail->d_jan_code,
                        'd_item_code' => $detail->d_item_code,
                        'd_product_name' => $detail->d_product_name,
                    ],
                ]);

                continue;
            }

            $isShortage = $detail->is_shortage || $detail->total_quantity === 0;
            $shippedPieces = $isShortage ? 0 : (int) $detail->total_quantity;
            $templateSchedule = $this->findTemplateSchedule($templateSchedules, $itemId);
            $scheduleContractorId = $contractor?->id ?? $templateSchedule?->contractor_id;
            $quantityType = $templateSchedule?->quantity_type ?? QuantityType::PIECE;
            $shippedQty = $this->receivedQuantityInScheduleUnit($templateSchedule, collect([$detail]), $shippedPieces);
            $expectedQty = $this->resolveCreatedScheduleExpectedQuantity($templateSchedule, $shippedQty, $isShortage);
            $shortageQty = max(0, $expectedQty - $shippedQty);

            // 同一仕入先×伝票番号×商品の未確定スケジュールがあればスキップ（再実行時の重複防止）
            $existingSchedule = WmsOrderIncomingSchedule::where('slip_number', $slip->slip_number)
                ->when(
                    $scheduleContractorId,
                    fn ($query, $contractorId) => $query->where('contractor_id', $contractorId),
                    fn ($query) => $query->whereNull('contractor_id')
                )
                ->where('item_id', $itemId)
                ->whereIn('status', [
                    IncomingScheduleStatus::PENDING->value,
                    IncomingScheduleStatus::PARTIAL->value,
                ])
                ->first();

            if ($existingSchedule) {
                $detail->update([
                    'matched_item_id' => $itemId,
                    'matched_schedule_id' => $existingSchedule->id,
                    'expected_quantity' => $this->scheduleQuantityAsPieces($existingSchedule),
                    'match_status' => $isShortage ? 'SHORTAGE' : 'MATCHED',
                ]);
                $createdScheduleIds[] = (int) $existingSchedule->id;

                continue;
            }

            $priceData = $this->receivedPriceWritebackData($templateSchedule, $detail);

            // 賞味期限: 商品マスタの default_expiration_days から算出
            $expirationDate = $this->calculateExpirationDate($itemId, $deliveryDate);
            $supplierId = $templateSchedule?->supplier_id
                ?? $this->resolveSupplierId($warehouse->id, $itemId, $contractor?->id);

            // 商品コード・検索コードを取得
            $itemCode = $templateSchedule?->item_code ?? Item::where('id', $itemId)->value('code');
            $searchCode = $templateSchedule?->search_code ?? DB::connection('sakemaru')
                ->table('item_search_information')
                ->where('item_id', $itemId)
                ->where('is_used_for_ordering', true)
                ->where('is_active', true)
                ->value('search_string');

            $schedule = WmsOrderIncomingSchedule::create([
                'warehouse_id' => $templateSchedule?->warehouse_id ?? $warehouse->id,
                'item_id' => $itemId,
                'item_code' => $itemCode,
                'search_code' => $searchCode,
                'contractor_id' => $scheduleContractorId,
                'supplier_id' => $supplierId,
                'order_source' => OrderSource::RECEIVED,
                'slip_number' => $slip->slip_number,
                'expected_quantity' => $expectedQty,
                'shipped_quantity' => $shippedQty,
                'received_quantity' => $shippedQty, // 発注先出荷実績をプリセット（検品時に不一致なら変更）
                'shortage_quantity' => $shortageQty,
                'is_receive_matched' => true,
                'partner_unit_price' => $priceData['partner_unit_price'],
                'partner_case_price' => $priceData['partner_case_price'],
                'price_type' => $priceData['price_type'],
                'unit_price' => $templateSchedule?->unit_price,
                'case_price' => $templateSchedule?->case_price,
                'quantity_type' => $quantityType,
                'order_date' => $orderDate,
                'expected_arrival_date' => $deliveryDate,
                'expiration_date' => $expirationDate,
                'actual_arrival_date' => null,
                'status' => IncomingScheduleStatus::PENDING,
                'confirmed_at' => null,
            ]);

            // 明細にもマッチ情報をセット
            $detail->update([
                'matched_item_id' => $itemId,
                'matched_schedule_id' => $schedule->id,
                'expected_quantity' => $this->scheduleQuantityAsPieces($schedule),
                'match_status' => $isShortage ? 'SHORTAGE' : 'MATCHED',
            ]);

            $createdCount++;
            $createdScheduleIds[] = (int) $schedule->id;
        }

        return [
            'created' => $createdCount,
            'schedule_ids' => array_values(array_unique($createdScheduleIds)),
        ];
    }

    private function resolveWarehouseForSlip(WmsIncomingReceivedSlip $slip): ?Warehouse
    {
        $warehouseCode = ltrim($slip->b_shop_code ?? '', '0');

        return Warehouse::where(DB::raw('LTRIM(code)'), $warehouseCode)
            ->orWhere('code', $slip->b_shop_code)
            ->first();
    }

    private function resolveCreateContractorId(WmsIncomingReceivedSlip $slip, $templateSchedules = null): ?int
    {
        $templateContractorIds = $templateSchedules
            ? $templateSchedules
                ->pluck('contractor_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all()
            : [];

        if (count($templateContractorIds) === 1) {
            return $templateContractorIds[0];
        }

        $directContractorIds = $this->resolveDirectContractorIdsFromReceivedCode($slip->b_contractor_code);
        if (count($directContractorIds) === 1) {
            return $directContractorIds[0];
        }

        $itemContractorId = $this->resolveUniqueItemContractorIdForSlip($slip);
        if ($itemContractorId) {
            return $itemContractorId;
        }

        $fileContractorId = $slip->file?->contractor_id;
        if ($fileContractorId) {
            return (int) $fileContractorId;
        }

        return null;
    }

    public function resolveUnassignedJxSlipContractorId(WmsIncomingReceivedSlip $slip): ?int
    {
        return $this->resolveCreateContractorId($slip);
    }

    private function resolveUniqueItemContractorIdForSlip(WmsIncomingReceivedSlip $slip): ?int
    {
        $warehouse = $this->resolveWarehouseForSlip($slip);
        if (! $warehouse) {
            return null;
        }

        $slip->loadMissing('details');

        $itemIds = $slip->details
            ->map(fn (WmsIncomingReceivedDetail $detail): ?int => $detail->matched_item_id
                ? (int) $detail->matched_item_id
                : $this->resolveItemId($detail))
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($itemIds === []) {
            return null;
        }

        $contractorIds = DB::connection('sakemaru')
            ->table('item_contractors')
            ->where('warehouse_id', $warehouse->id)
            ->whereIn('item_id', $itemIds)
            ->whereNotNull('contractor_id')
            ->pluck('contractor_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return count($contractorIds) === 1 ? $contractorIds[0] : null;
    }

    private function findTemplateSchedule($templateSchedules, int $itemId): ?WmsOrderIncomingSchedule
    {
        if (! $templateSchedules) {
            return null;
        }

        return $templateSchedules->firstWhere('item_id', $itemId);
    }

    private function resolveTemplateItemId($templateSchedules, $detail): ?int
    {
        if (! $templateSchedules) {
            return null;
        }

        $incomingCodes = $this->normalizedIncomingSearchCodes($detail);
        if ($incomingCodes === []) {
            return null;
        }

        foreach ($templateSchedules as $schedule) {
            $scheduleCodes = array_filter(array_map('trim', explode(',', (string) $schedule->search_code)));
            if ($schedule->item_code) {
                $scheduleCodes[] = (string) $schedule->item_code;
            }

            if (array_intersect($incomingCodes, array_unique($scheduleCodes)) !== []) {
                return (int) $schedule->item_id;
            }
        }

        return null;
    }

    private function resolveCreatedScheduleExpectedQuantity(
        ?WmsOrderIncomingSchedule $templateSchedule,
        int $shippedQty,
        bool $isShortage
    ): int {
        if (! $templateSchedule) {
            return $shippedQty;
        }

        $remainingFromPreviousShortage = (int) ($templateSchedule->shortage_quantity ?? 0);
        if ($remainingFromPreviousShortage > 0) {
            return max($shippedQty, $remainingFromPreviousShortage);
        }

        if ($isShortage) {
            return max(0, (int) $templateSchedule->expected_quantity);
        }

        return $shippedQty;
    }

    private function resolveSupplierId(int $warehouseId, int $itemId, ?int $contractorId): ?int
    {
        if (! $contractorId) {
            return null;
        }

        $itemContractorSupplierId = DB::connection('sakemaru')
            ->table('item_contractors')
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->where('contractor_id', $contractorId)
            ->whereNotNull('supplier_id')
            ->orderBy('id')
            ->value('supplier_id');

        if ($itemContractorSupplierId) {
            return (int) $itemContractorSupplierId;
        }

        $contractorSupplierId = DB::connection('sakemaru')
            ->table('contractors')
            ->where('id', $contractorId)
            ->value('supplier_id');

        return $contractorSupplierId ? (int) $contractorSupplierId : null;
    }

    /**
     * 明細から商品IDを解決
     *
     * Step 1: d_item_code → items.code
     * Step 2: d_jan_code / d_item_code → item_search_information.search_string
     */
    private function resolveItemId($detail): ?int
    {
        // Step 1: items.code で直接検索
        if ($detail->d_item_code) {
            $item = Item::where('code', $detail->d_item_code)->first();
            if ($item) {
                return $item->id;
            }
        }

        // Step 2: item_search_information
        $searchCodes = $this->normalizedIncomingSearchCodes($detail);
        if (! empty($searchCodes)) {
            $itemId = DB::connection('sakemaru')
                ->table('item_search_information')
                ->whereIn('search_string', $searchCodes)
                ->value('item_id');
            if ($itemId) {
                return $itemId;
            }
        }

        return null;
    }

    private function resolveItemIdForUnassignedJxConfirmation(WmsIncomingReceivedDetail $detail): ?int
    {
        return $detail->matched_item_id
            ? (int) $detail->matched_item_id
            : $this->resolveItemId($detail);
    }

    /**
     * JX受信コードの表記揺れを検索用に正規化する。
     */
    private function normalizedIncomingSearchCodes($detail): array
    {
        $codes = [];

        foreach ([$detail->d_jan_code, $detail->d_item_code] as $code) {
            $code = trim((string) $code);
            if ($code === '') {
                continue;
            }

            $codes[] = $code;

            $withoutLeadingZeros = ltrim($code, '0');
            if ($withoutLeadingZeros !== '') {
                $codes[] = $withoutLeadingZeros;

                if (strlen($withoutLeadingZeros) < 13) {
                    $codes[] = str_pad($withoutLeadingZeros, 13, '0', STR_PAD_LEFT);
                }
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * JX日付文字列をパース（YYYYMMDD / YYMMDD / YYYY/MM/DD形式）
     */
    private function parseJxDate(?string $dateStr): ?string
    {
        if (! $dateStr || trim($dateStr) === '') {
            return null;
        }

        try {
            $str = trim($dateStr);

            // YYYYMMDD形式
            if (preg_match('/^\d{8}$/', $str)) {
                return Carbon::createFromFormat('Ymd', $str)->format('Y-m-d');
            }

            // YYMMDD形式（和暦ではなく西暦下2桁として扱う: 26→2026）
            if (preg_match('/^\d{6}$/', $str)) {
                return Carbon::createFromFormat('ymd', $str)->format('Y-m-d');
            }

            // YYYY/MM/DD or YYYY-MM-DD形式
            return Carbon::parse($str)->format('Y-m-d');
        } catch (\Exception $e) {
            Log::warning('[IncomingReceiveService] 日付パース失敗', [
                'dateStr' => $dateStr,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 伝票番号割当がないJX受信伝票を、受信明細由来の入荷完了データとして作成する。
     *
     * @return array{created: int, updated: int, skipped: int, schedule_ids: array<int>}
     */
    public function confirmUnassignedJxSlip(WmsIncomingReceivedSlip $slip, ?int $confirmedBy = null): array
    {
        $result = DB::connection('sakemaru')->transaction(function () use ($slip, $confirmedBy): array {
            $lockedSlip = WmsIncomingReceivedSlip::query()
                ->whereKey($slip->id)
                ->with(['file', 'details'])
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->isJxSlip($lockedSlip)) {
                throw new \RuntimeException("JX受信伝票ではありません: slip_id={$lockedSlip->id}");
            }

            $file = $lockedSlip->file;
            if (! $file) {
                throw new \RuntimeException("受信ファイルが見つかりません: slip_id={$lockedSlip->id}");
            }

            $warehouse = $this->resolveWarehouseForSlip($lockedSlip);
            if (! $warehouse) {
                throw new \RuntimeException("倉庫を解決できません: slip_number={$lockedSlip->slip_number}, shop_code={$lockedSlip->b_shop_code}");
            }

            $contractorId = $this->resolveCreateContractorId($lockedSlip);
            if (! $contractorId) {
                throw new \RuntimeException("仕入先を解決できません: slip_number={$lockedSlip->slip_number}, contractor_code={$lockedSlip->b_contractor_code}");
            }

            $contractor = Contractor::find($contractorId);
            if (! $contractor) {
                throw new \RuntimeException("仕入先が見つかりません: contractor_id={$contractorId}");
            }

            $details = $lockedSlip->details
                ->sortBy('d_line_number')
                ->values();
            $receivableDetails = $details
                ->reject(fn (WmsIncomingReceivedDetail $detail): bool => $detail->match_status === 'IGNORED'
                    || $detail->is_shortage
                    || (int) $detail->total_quantity <= 0)
                ->values();

            if ($receivableDetails->isEmpty()) {
                throw new \RuntimeException("入荷数量のある明細がありません: slip_number={$lockedSlip->slip_number}");
            }

            $validatedDetails = [];
            foreach ($receivableDetails as $detail) {
                $itemId = $this->resolveItemIdForUnassignedJxConfirmation($detail);
                if (! $itemId) {
                    throw new \RuntimeException("商品を解決できません: slip_number={$lockedSlip->slip_number}, line={$detail->d_line_number}, JAN={$detail->d_jan_code}, item_code={$detail->d_item_code}");
                }

                $validatedDetails[] = [
                    'detail' => $detail,
                    'item_id' => (int) $itemId,
                ];
            }

            $orderDate = $this->parseJxDate($lockedSlip->b_order_date) ?? now()->toDateString();
            $deliveryDate = $this->parseJxDate($lockedSlip->b_delivery_date) ?? now()->toDateString();
            $createdCount = 0;
            $updatedCount = 0;
            $skippedCount = 0;
            $scheduleIds = [];
            $purchaseSplitKey = $this->unassignedJxSlipPurchaseSplitKey($lockedSlip);

            foreach ($validatedDetails as $validatedDetail) {
                /** @var WmsIncomingReceivedDetail $detail */
                $detail = $validatedDetail['detail'];
                $itemId = $validatedDetail['item_id'];

                $existingSchedule = WmsOrderIncomingSchedule::query()
                    ->where('source_received_detail_id', $detail->id)
                    ->lockForUpdate()
                    ->first();

                if ($existingSchedule) {
                    $this->markDetailAsConfirmedFromReceivedSchedule($detail, $existingSchedule);
                    $scheduleIds[] = (int) $existingSchedule->id;

                    if ($this->canApplyReceivedData($existingSchedule)) {
                        $this->applyDetailToSchedule($existingSchedule, $detail, $lockedSlip);
                        $updatedCount++;
                    } else {
                        $skippedCount++;
                    }

                    $this->recordPriceCheckSource($file, $detail, $existingSchedule);

                    continue;
                }

                $shippedPieces = (int) $detail->total_quantity;
                $priceData = $this->receivedPriceWritebackData(null, $detail);
                $supplierId = $this->resolveSupplierId($warehouse->id, $itemId, $contractor->id);
                $itemCode = Item::where('id', $itemId)->value('code');
                $searchCode = DB::connection('sakemaru')
                    ->table('item_search_information')
                    ->where('item_id', $itemId)
                    ->where('is_used_for_ordering', true)
                    ->where('is_active', true)
                    ->value('search_string');
                $expirationDate = $this->calculateExpirationDate($itemId, $deliveryDate);

                $schedule = WmsOrderIncomingSchedule::create([
                    'warehouse_id' => $warehouse->id,
                    'item_id' => $itemId,
                    'item_code' => $itemCode,
                    'search_code' => $searchCode,
                    'contractor_id' => $contractor->id,
                    'supplier_id' => $supplierId,
                    'order_source' => OrderSource::RECEIVED,
                    'slip_number' => $lockedSlip->slip_number,
                    'expected_quantity' => $shippedPieces,
                    'shipped_quantity' => $shippedPieces,
                    'received_quantity' => $shippedPieces,
                    'shortage_quantity' => 0,
                    'is_receive_matched' => true,
                    'partner_unit_price' => $priceData['partner_unit_price'],
                    'partner_case_price' => $priceData['partner_case_price'],
                    'price_type' => $priceData['price_type'],
                    'quantity_type' => QuantityType::PIECE,
                    'order_date' => $orderDate,
                    'expected_arrival_date' => $deliveryDate,
                    'actual_arrival_date' => $deliveryDate,
                    'expiration_date' => $expirationDate,
                    'status' => IncomingScheduleStatus::CONFIRMED,
                    'confirmed_at' => now(),
                    'confirmed_by' => $confirmedBy ?? 0,
                    'confirmed_picker_id' => null,
                    'source_received_detail_id' => $detail->id,
                    'purchase_split_key' => $purchaseSplitKey,
                    'note' => "伝票番号不明EOS受信から入荷確定データ作成: 受信伝票ID={$lockedSlip->id}, 受信明細ID={$detail->id}",
                ]);

                $this->markDetailAsConfirmedFromReceivedSchedule($detail, $schedule);
                $this->recordPriceCheckSource($file, $detail, $schedule);

                $createdCount++;
                $scheduleIds[] = (int) $schedule->id;
            }

            foreach ($details as $detail) {
                if ($detail->match_status === 'IGNORED') {
                    continue;
                }

                if ($detail->is_shortage || (int) $detail->total_quantity <= 0) {
                    $itemId = $this->resolveItemIdForUnassignedJxConfirmation($detail);
                    $detail->update([
                        'matched_item_id' => $itemId,
                        'matched_schedule_id' => null,
                        'expected_quantity' => 0,
                        'match_status' => 'SHORTAGE',
                    ]);
                }
            }

            $scheduleIds = array_values(array_unique($scheduleIds));
            $lockedSlip->update([
                'matched_schedule_id' => $scheduleIds[0] ?? null,
                'match_status' => 'MATCHED',
                'shortage_count' => $details
                    ->filter(fn (WmsIncomingReceivedDetail $detail): bool => $detail->is_shortage || (int) $detail->total_quantity <= 0)
                    ->count(),
            ]);
            $this->resolveUnassignedJxReviewErrors($lockedSlip, $confirmedBy);
            $this->refreshReceivedFileStatusAfterManualConfirmation($file);

            Log::info('[IncomingReceiveService] 伝票番号不明JX受信伝票を入荷完了データとして作成しました', [
                'file_id' => $file->id,
                'slip_id' => $lockedSlip->id,
                'slip_number' => $lockedSlip->slip_number,
                'created' => $createdCount,
                'updated' => $updatedCount,
                'skipped' => $skippedCount,
                'schedule_ids' => $scheduleIds,
            ]);

            return [
                'created' => $createdCount,
                'updated' => $updatedCount,
                'skipped' => $skippedCount,
                'schedule_ids' => $scheduleIds,
            ];
        });

        $slip->refresh();

        return $result;
    }

    /**
     * 商品不明のJX受信明細に商品を手動確定する。
     *
     * @return array{detail_id: int, item_id: int}
     */
    public function resolveUnassignedJxDetailItem(WmsIncomingReceivedDetail $detail, int $itemId, ?int $resolvedBy = null): array
    {
        return DB::connection('sakemaru')->transaction(function () use ($detail, $itemId, $resolvedBy): array {
            $lockedDetail = WmsIncomingReceivedDetail::query()
                ->whereKey($detail->id)
                ->with(['slip.file'])
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertUnassignedJxDetailCanBeAdjusted($lockedDetail);

            if ($lockedDetail->match_status === 'IGNORED') {
                throw new \RuntimeException("対象外にした受信明細は商品確定できません: detail_id={$lockedDetail->id}");
            }

            $item = Item::query()->whereKey($itemId)->first();
            if (! $item) {
                throw new \RuntimeException("商品が見つかりません: item_id={$itemId}");
            }

            $lockedDetail->update([
                'd_product_name' => mb_substr((string) ($item->name ?: $item->name_main ?: ''), 0, 64),
                'matched_item_id' => $item->id,
                'matched_schedule_id' => null,
                'expected_quantity' => null,
                'match_status' => ($lockedDetail->is_shortage || (int) $lockedDetail->total_quantity <= 0)
                    ? 'SHORTAGE'
                    : 'NO_ASSIGNMENT',
            ]);

            $this->resolveItemErrorsForDetail($lockedDetail, $resolvedBy);

            $slip = $lockedDetail->slip;
            if ($slip) {
                $this->refreshUnassignedSlipStatusAfterManualDetailAdjustment($slip, $resolvedBy, keepReviewVisible: true);
                $this->refreshReceivedFileStatusAfterManualConfirmation($slip->file);
            }

            Log::info('[IncomingReceiveService] 商品不明のJX受信明細を手動確定しました', [
                'slip_id' => $lockedDetail->received_slip_id,
                'detail_id' => $lockedDetail->id,
                'item_id' => $item->id,
                'resolved_by' => $resolvedBy,
            ]);

            return [
                'detail_id' => (int) $lockedDetail->id,
                'item_id' => (int) $item->id,
            ];
        });
    }

    /**
     * 商品不明のJX受信明細を入荷確定対象から外す。
     *
     * 原本は保持し、match_status=IGNORED として以後の入荷確定対象から除外する。
     *
     * @return array{detail_id: int, slip_status: string|null}
     */
    public function ignoreUnassignedJxDetail(WmsIncomingReceivedDetail $detail, ?int $resolvedBy = null): array
    {
        return DB::connection('sakemaru')->transaction(function () use ($detail, $resolvedBy): array {
            $lockedDetail = WmsIncomingReceivedDetail::query()
                ->whereKey($detail->id)
                ->with(['slip.file'])
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertUnassignedJxDetailCanBeAdjusted($lockedDetail);

            $lockedDetail->update([
                'matched_item_id' => null,
                'matched_schedule_id' => null,
                'expected_quantity' => 0,
                'match_status' => 'IGNORED',
            ]);

            $this->resolveItemErrorsForDetail($lockedDetail, $resolvedBy);

            $slip = $lockedDetail->slip;
            if ($slip) {
                $this->refreshUnassignedSlipStatusAfterManualDetailAdjustment($slip, $resolvedBy);
                $this->refreshReceivedFileStatusAfterManualConfirmation($slip->file);
            }

            Log::info('[IncomingReceiveService] 商品不明のJX受信明細を対象外にしました', [
                'slip_id' => $lockedDetail->received_slip_id,
                'detail_id' => $lockedDetail->id,
                'resolved_by' => $resolvedBy,
            ]);

            return [
                'detail_id' => (int) $lockedDetail->id,
                'slip_status' => $slip?->fresh()?->match_status,
            ];
        });
    }

    /**
     * 照合済みデータを入荷予定に適用
     */
    public function applyMatched(WmsIncomingReceivedFile $file): array
    {
        $result = DB::connection('sakemaru')->transaction(function () use ($file): array {
            $lockedFile = WmsIncomingReceivedFile::query()
                ->whereKey($file->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedFile->isWorkflowTerminal()) {
                Log::info('[IncomingReceiveService] 終端状態の受信ファイルのため適用をスキップしました', [
                    'file_id' => $lockedFile->id,
                    'status' => $lockedFile->status,
                ]);

                return [
                    'applied' => 0,
                    'schedule_ids' => [],
                    'errors' => [],
                ];
            }

            $appliedCount = 0;
            $appliedScheduleIds = [];
            $errors = [];

            $slips = $lockedFile->slips()
                ->whereIn('match_status', ['MATCHED', 'PARTIAL', 'SHORTAGE'])
                ->whereNotNull('matched_schedule_id')
                ->with(['file', 'details.file'])
                ->get();

            foreach ($slips as $slip) {
                try {
                    $scheduleIds = $this->applySlip($slip);
                    $appliedScheduleIds = array_merge($appliedScheduleIds, $scheduleIds);
                    $appliedCount += count($scheduleIds);
                } catch (\Exception $e) {
                    $errors[] = [
                        'slip_id' => $slip->id,
                        'slip_number' => $slip->slip_number,
                        'error' => $e->getMessage(),
                    ];
                    Log::error('[IncomingReceiveService] 適用エラー', [
                        'slip_id' => $slip->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // ファイルステータス更新
            if ($appliedCount > 0) {
                $lockedFile->update(['status' => WmsIncomingReceivedFile::STATUS_APPLIED]);
            }

            return [
                'applied' => $appliedCount,
                'schedule_ids' => array_values(array_unique($appliedScheduleIds)),
                'errors' => $errors,
            ];
        });

        $file->refresh();

        return $result;
    }

    /**
     * 伝票単位の適用
     */
    private function applySlip(WmsIncomingReceivedSlip $slip): array
    {
        $matchedDetails = $slip->details()
            ->whereNotNull('matched_item_id')
            ->whereIn('match_status', ['MATCHED', 'PARTIAL', 'SHORTAGE'])
            ->get();
        $file = $slip->file ?: WmsIncomingReceivedFile::query()->find($slip->received_file_id);

        $appliedScheduleIds = [];
        $contractorIds = null;
        $usedMatchedScheduleIds = [];

        foreach ($matchedDetails as $detail) {
            $originalMatchedScheduleId = $detail->matched_schedule_id
                ? (int) $detail->matched_schedule_id
                : null;
            $schedule = null;

            if ($originalMatchedScheduleId && isset($usedMatchedScheduleIds[$originalMatchedScheduleId])) {
                $templateSchedule = WmsOrderIncomingSchedule::query()
                    ->whereKey($originalMatchedScheduleId)
                    ->first();

                if (! $templateSchedule) {
                    throw new \RuntimeException("入荷予定が見つかりません: {$originalMatchedScheduleId}");
                }

                $schedule = $this->createDuplicateDetailSchedule($templateSchedule, $detail, $slip);
            } else {
                $schedule = $detail->matched_schedule_id
                    ? WmsOrderIncomingSchedule::query()
                        ->whereKey($detail->matched_schedule_id)
                        ->whereIn('status', [
                            IncomingScheduleStatus::PENDING->value,
                            IncomingScheduleStatus::PARTIAL->value,
                        ])
                        ->first()
                    : null;
            }

            if (! $schedule) {
                $contractorIds ??= $this->resolveSlipContractorIds($slip);
                if ($contractorIds === []) {
                    throw new \RuntimeException("受信伝票の仕入先を解決できません: slip_number={$slip->slip_number}");
                }

                $schedule = WmsOrderIncomingSchedule::query()
                    ->where('slip_number', $slip->slip_number)
                    ->whereIn('contractor_id', $contractorIds)
                    ->where('item_id', $detail->matched_item_id)
                    ->whereIn('status', [
                        IncomingScheduleStatus::PENDING->value,
                        IncomingScheduleStatus::PARTIAL->value,
                    ])
                    ->orderBy('id')
                    ->first();
            }

            if (! $schedule) {
                throw new \RuntimeException("入荷予定が見つかりません: slip_number={$slip->slip_number}, item_id={$detail->matched_item_id}");
            }

            $this->applyDetailToSchedule($schedule, $detail, $slip);
            if ($file) {
                $this->recordPriceCheckSource($file, $detail, $schedule);
            }
            $appliedScheduleIds[] = (int) $schedule->id;

            if ($originalMatchedScheduleId) {
                $usedMatchedScheduleIds[$originalMatchedScheduleId] = true;
            }
        }

        if ($appliedScheduleIds === [] && $slip->matched_schedule_id) {
            $schedule = WmsOrderIncomingSchedule::find($slip->matched_schedule_id);
            if (! $schedule) {
                throw new \RuntimeException("入荷予定が見つかりません: {$slip->matched_schedule_id}");
            }

            $contractorIds ??= $this->resolveSlipContractorIds($slip);
            if ($contractorIds === []) {
                throw new \RuntimeException("受信伝票の仕入先を解決できません: slip_number={$slip->slip_number}");
            }

            if (! in_array((int) $schedule->contractor_id, $contractorIds, true)) {
                throw new \RuntimeException("入荷予定の仕入先が受信伝票と一致しません: schedule_id={$schedule->id}, slip_number={$slip->slip_number}");
            }

            if (! $this->canApplyReceivedData($schedule)) {
                throw new \RuntimeException("入荷予定が受信適用できない状態です: schedule_id={$schedule->id}, status={$this->scheduleStatusValue($schedule)}");
            }

            $schedule->update([
                'shortage_quantity' => $schedule->expected_quantity,
                'is_receive_matched' => true,
            ]);

            foreach ($slip->details as $detail) {
                if ($file) {
                    $this->recordPriceCheckSource($file, $detail, $schedule);
                }
            }

            $appliedScheduleIds[] = (int) $schedule->id;
        }

        return $appliedScheduleIds;
    }

    private function createDuplicateDetailSchedule(
        WmsOrderIncomingSchedule $templateSchedule,
        WmsIncomingReceivedDetail $detail,
        WmsIncomingReceivedSlip $slip
    ): WmsOrderIncomingSchedule {
        $existing = WmsOrderIncomingSchedule::query()
            ->where('source_received_detail_id', $detail->id)
            ->first();

        if ($existing) {
            $detail->update([
                'matched_schedule_id' => $existing->id,
                'expected_quantity' => $this->scheduleQuantityAsPieces($existing),
            ]);

            return $existing;
        }

        $isShortage = $detail->is_shortage
            || $detail->match_status === 'SHORTAGE'
            || (int) $detail->total_quantity === 0;
        $shippedPieces = $isShortage ? 0 : (int) $detail->total_quantity;
        $shippedQty = $this->receivedQuantityInScheduleUnit($templateSchedule, collect([$detail]), $shippedPieces);
        $expectedQty = $this->resolveCreatedScheduleExpectedQuantity($templateSchedule, $shippedQty, $isShortage);
        $shortageQty = max(0, $expectedQty - $shippedQty);
        $orderDate = $this->parseJxDate($slip->b_order_date)
            ?? $templateSchedule->order_date?->format('Y-m-d')
            ?? now()->toDateString();
        $deliveryDate = $this->parseJxDate($slip->b_delivery_date)
            ?? $templateSchedule->expected_arrival_date?->format('Y-m-d')
            ?? now()->toDateString();
        $expirationDate = $templateSchedule->expiration_date?->format('Y-m-d')
            ?? $this->calculateExpirationDate($templateSchedule->item_id, $deliveryDate);
        $noteParts = array_filter([
            trim((string) $templateSchedule->note),
            "EOS重複明細から自動作成: 元入荷予定ID={$templateSchedule->id}, 受信明細ID={$detail->id}, 受信伝票番号={$slip->slip_number}",
        ]);

        $schedule = WmsOrderIncomingSchedule::create([
            'warehouse_id' => $templateSchedule->warehouse_id,
            'item_id' => $templateSchedule->item_id,
            'item_code' => $templateSchedule->item_code,
            'search_code' => $templateSchedule->search_code,
            'contractor_id' => $templateSchedule->contractor_id,
            'supplier_id' => $templateSchedule->supplier_id,
            'location_id' => $templateSchedule->location_id,
            'order_source' => OrderSource::RECEIVED,
            'slip_number' => $templateSchedule->slip_number ?: $slip->slip_number,
            'expected_quantity' => $expectedQty,
            'shipped_quantity' => $shippedQty,
            'received_quantity' => 0,
            'shortage_quantity' => $shortageQty,
            'is_receive_matched' => true,
            'unit_price' => $templateSchedule->unit_price,
            'case_price' => $templateSchedule->case_price,
            'quantity_type' => $templateSchedule->quantity_type,
            'order_date' => $orderDate,
            'expected_arrival_date' => $deliveryDate,
            'expiration_date' => $expirationDate,
            'actual_arrival_date' => null,
            'status' => IncomingScheduleStatus::PENDING,
            'confirmed_at' => null,
            'source_incoming_schedule_id' => $templateSchedule->id,
            'source_received_detail_id' => $detail->id,
            'purchase_split_key' => $this->duplicateDetailPurchaseSplitKey($detail),
            'note' => implode(' / ', $noteParts),
        ]);

        $detail->update([
            'matched_schedule_id' => $schedule->id,
            'expected_quantity' => $this->scheduleQuantityAsPieces($schedule),
            'match_status' => $isShortage ? 'SHORTAGE' : 'MATCHED',
        ]);

        Log::info('[IncomingReceiveService] EOS重複明細を別入荷予定として作成しました', [
            'source_schedule_id' => $templateSchedule->id,
            'created_schedule_id' => $schedule->id,
            'received_detail_id' => $detail->id,
            'slip_id' => $slip->id,
            'slip_number' => $slip->slip_number,
        ]);

        return $schedule;
    }

    private function duplicateDetailPurchaseSplitKey(WmsIncomingReceivedDetail $detail): string
    {
        return "EOS_DETAIL_{$detail->id}";
    }

    private function unassignedJxSlipPurchaseSplitKey(WmsIncomingReceivedSlip $slip): string
    {
        return "UNASSIGNED_JX_SLIP_{$slip->id}";
    }

    private function markDetailAsConfirmedFromReceivedSchedule(
        WmsIncomingReceivedDetail $detail,
        WmsOrderIncomingSchedule $schedule
    ): void {
        $detail->update([
            'matched_item_id' => $schedule->item_id,
            'matched_schedule_id' => $schedule->id,
            'expected_quantity' => $this->scheduleQuantityAsPieces($schedule),
            'match_status' => 'MATCHED',
        ]);
    }

    /**
     * @return array<int>
     */
    private function receivedDetailScheduleIdsForSlip(WmsIncomingReceivedSlip $slip): array
    {
        $detailIds = $slip->details()->pluck('id')->all();
        if ($detailIds === []) {
            return [];
        }

        return WmsOrderIncomingSchedule::query()
            ->whereIn('source_received_detail_id', $detailIds)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function resolveUnassignedJxReviewErrors(WmsIncomingReceivedSlip $slip, ?int $resolvedBy = null): void
    {
        WmsIncomingImportError::query()
            ->where('received_slip_id', $slip->id)
            ->whereIn('error_code', [
                'EOS_ASSIGNMENT_NOT_FOUND',
                'EOS_UNASSIGNED_CONTRACTOR_NOT_RESOLVED',
                'EOS_UNASSIGNED_WAREHOUSE_NOT_RESOLVED',
                'EOS_UNASSIGNED_NO_RECEIVED_QUANTITY',
                'EOS_UNASSIGNED_RECEIVED_SCHEDULE_CREATED',
                'SLIP_NOT_FOUND',
                'ITEM_NOT_FOUND',
                'SCHEDULE_ITEM_NOT_FOUND',
            ])
            ->where(function ($query): void {
                $query
                    ->whereNull('is_resolved')
                    ->orWhere('is_resolved', false);
            })
            ->update([
                'is_resolved' => true,
                'resolved_by' => $resolvedBy,
                'resolved_at' => now(),
            ]);
    }

    private function assertUnassignedJxDetailCanBeAdjusted(WmsIncomingReceivedDetail $detail): void
    {
        $slip = $detail->slip;
        if (! $slip || ! $this->isJxSlip($slip)) {
            throw new \RuntimeException("JX受信明細ではありません: detail_id={$detail->id}");
        }

        if (! $slip->file) {
            throw new \RuntimeException("受信ファイルが見つかりません: detail_id={$detail->id}");
        }

        $hasSchedule = WmsOrderIncomingSchedule::query()
            ->where('source_received_detail_id', $detail->id)
            ->exists();

        if ($hasSchedule) {
            throw new \RuntimeException("入荷完了データ作成済みのため修正できません: detail_id={$detail->id}");
        }
    }

    private function resolveItemErrorsForDetail(WmsIncomingReceivedDetail $detail, ?int $resolvedBy = null): void
    {
        WmsIncomingImportError::query()
            ->where('received_detail_id', $detail->id)
            ->whereIn('error_code', ['ITEM_NOT_FOUND', 'SCHEDULE_ITEM_NOT_FOUND'])
            ->where(function ($query): void {
                $query
                    ->whereNull('is_resolved')
                    ->orWhere('is_resolved', false);
            })
            ->update([
                'is_resolved' => true,
                'resolved_by' => $resolvedBy,
                'resolved_at' => now(),
            ]);
    }

    private function refreshUnassignedSlipStatusAfterManualDetailAdjustment(
        WmsIncomingReceivedSlip $slip,
        ?int $resolvedBy = null,
        bool $keepReviewVisible = false,
    ): void {
        $details = $slip->details()
            ->where(function ($query): void {
                $query
                    ->whereNull('match_status')
                    ->orWhere('match_status', '!=', 'IGNORED');
            })
            ->get();

        $shortageCount = $details
            ->filter(fn (WmsIncomingReceivedDetail $detail): bool => $detail->is_shortage || (int) $detail->total_quantity <= 0)
            ->count();

        $receivableDetails = $details
            ->reject(fn (WmsIncomingReceivedDetail $detail): bool => $detail->is_shortage || (int) $detail->total_quantity <= 0)
            ->values();

        if ($receivableDetails->isEmpty()) {
            $slip->update([
                'matched_schedule_id' => null,
                'match_status' => $keepReviewVisible ? 'NO_ASSIGNMENT' : 'IGNORED',
                'shortage_count' => $shortageCount,
            ]);

            if (! $keepReviewVisible) {
                $this->resolveUnassignedJxReviewErrors($slip, $resolvedBy);
            }

            return;
        }

        $hasMissingItem = $receivableDetails
            ->contains(fn (WmsIncomingReceivedDetail $detail): bool => ! $this->resolveItemIdForUnassignedJxConfirmation($detail));

        $slip->update([
            'matched_schedule_id' => null,
            'match_status' => $hasMissingItem ? 'NOT_FOUND' : 'NO_ASSIGNMENT',
            'shortage_count' => $shortageCount,
        ]);
    }

    private function refreshReceivedFileStatusAfterManualConfirmation(WmsIncomingReceivedFile $file): void
    {
        $hasUnresolvedSlips = $file->slips()
            ->whereIn('match_status', ['UNMATCHED', 'NO_ASSIGNMENT', 'NOT_FOUND'])
            ->exists();

        if (! $hasUnresolvedSlips) {
            $file->update(['status' => WmsIncomingReceivedFile::STATUS_APPLIED]);
        }
    }

    private function applyDetailToSchedule(
        WmsOrderIncomingSchedule $schedule,
        WmsIncomingReceivedDetail $detail,
        WmsIncomingReceivedSlip $slip
    ): void {
        $shippedPieces = ($detail->is_shortage || $detail->match_status === 'SHORTAGE')
            ? 0
            : (int) $detail->total_quantity;
        $quantityData = $this->scheduleQuantityWritebackData($schedule, collect([$detail]), $shippedPieces);

        $priceData = $this->receivedPriceWritebackData($schedule, $detail);

        // 賞味期限: 未設定の場合、商品マスタの default_expiration_days から算出
        $expirationDate = $schedule->expiration_date
            ?? $this->calculateExpirationDate($schedule->item_id, $schedule->expected_arrival_date);

        $actualArrivalDate = $this->parseJxDate($slip->b_delivery_date)
            ?? $schedule->expected_arrival_date?->format('Y-m-d')
            ?? now()->format('Y-m-d');

        $schedule->update(array_merge($quantityData, [
            'received_quantity' => $quantityData['shipped_quantity'],
            'actual_arrival_date' => $actualArrivalDate,
            'status' => IncomingScheduleStatus::CONFIRMED,
            'confirmed_at' => now(),
            'confirmed_by' => 0,
            'confirmed_picker_id' => null,
            'partner_unit_price' => $priceData['partner_unit_price'],
            'partner_case_price' => $priceData['partner_case_price'],
            'price_type' => $priceData['price_type'],
            'expiration_date' => $expirationDate,
            'is_receive_matched' => true,
        ]));
    }

    private function receivedPriceWritebackData(
        ?WmsOrderIncomingSchedule $schedule,
        WmsIncomingReceivedDetail $detail
    ): array {
        $rawUnitPrice = $detail->d_unit_price;
        $partnerPrice = is_numeric($rawUnitPrice) ? (float) $rawUnitPrice / 100 : null;
        $priceType = $this->receivedPriceType($schedule, $detail, $partnerPrice);

        return [
            'partner_unit_price' => $priceType === 'PIECE' ? $partnerPrice : null,
            'partner_case_price' => $priceType === 'CASE' ? $partnerPrice : null,
            'price_type' => $priceType,
        ];
    }

    private function receivedPriceType(
        ?WmsOrderIncomingSchedule $schedule,
        WmsIncomingReceivedDetail $detail,
        ?float $partnerPrice
    ): string {
        if ((int) ($detail->d_case_quantity ?? 0) > 0) {
            return 'CASE';
        }

        if ((int) ($detail->d_piece_quantity ?? 0) > 0) {
            return 'PIECE';
        }

        if (! $schedule || $partnerPrice === null) {
            return 'PIECE';
        }

        $casePrice = $this->numericPrice($schedule->case_price);
        if ($casePrice === null) {
            return 'PIECE';
        }

        $caseDiff = abs($partnerPrice - $casePrice);
        $unitPrice = $this->numericPrice($schedule->unit_price);
        $unitDiff = $unitPrice !== null ? abs($partnerPrice - $unitPrice) : null;

        if ($caseDiff <= 0.0001 || ($unitDiff !== null && $caseDiff < $unitDiff)) {
            return 'CASE';
        }

        return 'PIECE';
    }

    private function numericPrice(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function scheduleQuantityWritebackData(
        WmsOrderIncomingSchedule $schedule,
        $details,
        int $receivedPieces
    ): array {
        $quantityType = $this->scheduleQuantityType($schedule);
        $expectedQuantity = (int) $schedule->expected_quantity;
        $receivedQuantity = $this->receivedQuantityInScheduleUnit($schedule, $details, $receivedPieces);

        if (! $this->canRepresentReceivedQuantityInScheduleUnit($schedule, $details, $receivedPieces)) {
            $quantityType = QuantityType::PIECE;
            $expectedQuantity = $this->scheduleQuantityAsPieces($schedule);
            $receivedQuantity = $receivedPieces;
        }

        return [
            'expected_quantity' => $expectedQuantity,
            'quantity_type' => $quantityType->value,
            'shipped_quantity' => $receivedQuantity,
            'shortage_quantity' => max(0, $expectedQuantity - $receivedQuantity),
        ];
    }

    private function receivedQuantityInScheduleUnit(
        ?WmsOrderIncomingSchedule $schedule,
        $details,
        int $receivedPieces
    ): int {
        if (! $schedule || $this->scheduleQuantityType($schedule) === QuantityType::PIECE) {
            return $receivedPieces;
        }

        $details = collect($details);
        if ($this->scheduleQuantityType($schedule) === QuantityType::CASE) {
            $caseQuantity = $details
                ->reject(fn ($detail) => $detail->is_shortage || $detail->match_status === 'SHORTAGE')
                ->sum(fn ($detail) => (int) ($detail->d_case_quantity ?? 0));
            $pieceQuantity = $details
                ->reject(fn ($detail) => $detail->is_shortage || $detail->match_status === 'SHORTAGE')
                ->sum(fn ($detail) => (int) ($detail->d_piece_quantity ?? 0));

            if ($caseQuantity > 0 && $pieceQuantity === 0) {
                return $caseQuantity;
            }
        }

        $capacity = $this->scheduleQuantityCapacity($schedule);
        if ($capacity > 1 && $receivedPieces % $capacity === 0) {
            return intdiv($receivedPieces, $capacity);
        }

        return $receivedPieces;
    }

    private function canRepresentReceivedQuantityInScheduleUnit(
        WmsOrderIncomingSchedule $schedule,
        $details,
        int $receivedPieces
    ): bool {
        $quantityType = $this->scheduleQuantityType($schedule);
        if ($quantityType === QuantityType::PIECE) {
            return true;
        }

        $details = collect($details);
        if ($quantityType === QuantityType::CASE) {
            $hasLoosePieces = $details
                ->reject(fn ($detail) => $detail->is_shortage || $detail->match_status === 'SHORTAGE')
                ->contains(fn ($detail) => (int) ($detail->d_piece_quantity ?? 0) > 0);

            if (! $hasLoosePieces) {
                return true;
            }
        }

        $capacity = $this->scheduleQuantityCapacity($schedule);

        return $capacity > 1 && $receivedPieces % $capacity === 0;
    }

    private function scheduleQuantityAsPieces(WmsOrderIncomingSchedule $schedule, ?int $quantity = null): int
    {
        $quantity ??= (int) $schedule->expected_quantity;
        $quantityType = $this->scheduleQuantityType($schedule);

        if ($quantityType === QuantityType::PIECE) {
            return $quantity;
        }

        return $quantity * $this->scheduleQuantityCapacity($schedule);
    }

    private function scheduleQuantityCapacity(WmsOrderIncomingSchedule $schedule): int
    {
        $item = $schedule->relationLoaded('item')
            ? $schedule->item
            : Item::query()->find($schedule->item_id);

        $capacity = $item?->capacityOfQuantityType($this->scheduleQuantityType($schedule)) ?? 1;

        return max(1, (int) $capacity);
    }

    private function scheduleQuantityType(WmsOrderIncomingSchedule $schedule): QuantityType
    {
        if ($schedule->quantity_type instanceof QuantityType) {
            return $schedule->quantity_type;
        }

        return QuantityType::tryFrom((string) $schedule->quantity_type) ?? QuantityType::PIECE;
    }

    private function recordPriceCheckSource(
        WmsIncomingReceivedFile $file,
        WmsIncomingReceivedDetail $detail,
        WmsOrderIncomingSchedule $schedule
    ): void {
        if (strtoupper((string) $file->format_type) !== 'JX') {
            return;
        }

        try {
            $this->priceCheckSourceRecorder()->record($file, $detail, $schedule);
        } catch (\Throwable $throwable) {
            Log::warning('[IncomingReceiveService] 単価チェック原本保存に失敗しました', [
                'received_file_id' => $file->id,
                'received_detail_id' => $detail->id,
                'incoming_schedule_id' => $schedule->id,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    private function priceCheckSourceRecorder(): IncomingPriceCheckSourceRecorder
    {
        return $this->priceCheckSourceRecorder ??= app(IncomingPriceCheckSourceRecorder::class);
    }

    /**
     * 商品マスタの default_expiration_days から賞味期限を算出
     */
    private function calculateExpirationDate(?int $itemId, $baseDate): ?string
    {
        if (! $itemId || ! $baseDate) {
            return null;
        }

        $item = Item::find($itemId);
        if (! $item || ! $item->default_expiration_days) {
            return null;
        }

        $base = $baseDate instanceof Carbon ? $baseDate : Carbon::parse($baseDate);

        return $base->copy()->addDays($item->default_expiration_days)->format('Y-m-d');
    }
}
