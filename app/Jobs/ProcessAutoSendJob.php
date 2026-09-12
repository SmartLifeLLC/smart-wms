<?php

namespace App\Jobs;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\SettlementStatus;
use App\Models\WmsAutoOrderExecutionLog;
use App\Models\WmsAutoOrderJobControl;
use App\Models\WmsContractorSetting;
use App\Models\WmsOrderCandidate;
use App\Models\WmsQueueProgress;
use App\Models\WmsStockTransferCandidate;
use App\Services\AutoOrder\OrderExecutionService;
use App\Services\AutoOrder\OrderTransmissionService;
use App\Services\AutoOrder\TransferCandidateApprovalService;
use App\Services\AutoOrder\TransferCandidateExecutionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 自動送信ジョブ
 *
 * 候補生成完了後、is_auto_transmission=true の発注先に対して
 * 承認→確定→ファイル生成→JX送信を自動実行する
 *
 * batchCode=null の場合: 仕入先の未送信候補を全バッチから統合して処理
 */
class ProcessAutoSendJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600; // 10分

    public int $tries = 1;

    public function __construct(
        public string $progressId,
        public ?string $batchCode,
        public int $contractorId,
        public ?int $executionLogId = null,
    ) {}

    public function handle(): void
    {
        ini_set('memory_limit', '-1');

        $progress = WmsQueueProgress::findByJobId($this->progressId);

        if (! $progress) {
            Log::error('Auto-send queue progress not found', ['progress_id' => $this->progressId]);

            return;
        }

        try {
            $progress->markAsProcessing(100, '自動送信を開始しています...');

            $systemUserId = 0; // システム自動実行

            // 対象の発注先IDスコープ（親 + 子）
            $allContractorIds = WmsContractorSetting::getContractorIdsWithChildren($this->contractorId);

            $results = [
                'contractor_id' => $this->contractorId,
                'batch_code' => $this->batchCode,
                'steps' => [],
            ];

            if ($this->batchCode) {
                // バッチ指定あり: 従来の単一バッチ処理
                $this->processSingleBatch($progress, $allContractorIds, $systemUserId, $results);
            } else {
                // バッチ指定なし: 仕入先の未送信候補を全バッチから統合処理
                $this->processAllUnsent($progress, $allContractorIds, $systemUserId, $results);
            }

            // 完了
            $progress->markAsCompleted($results, '自動送信が完了しました');

            // 実行ログの送信ステータスを更新
            if ($this->executionLogId) {
                WmsAutoOrderExecutionLog::where('id', $this->executionLogId)
                    ->update(['transmission_status' => 'SUCCESS']);
            }

            Log::info('Auto-send job completed', [
                'batch_code' => $this->batchCode,
                'contractor_id' => $this->contractorId,
                'results' => $results,
            ]);

        } catch (\Exception $e) {
            Log::error('Auto-send job failed', [
                'batch_code' => $this->batchCode,
                'contractor_id' => $this->contractorId,
                'error' => $e->getMessage(),
            ]);

            $progress->markAsFailed($e->getMessage());

            // ジョブ失敗時は関連する確定待ちをキャンセルして永久PENDINGを防止
            if ($this->batchCode) {
                WmsAutoOrderJobControl::where('batch_code', $this->batchCode)
                    ->where('settlement_status', SettlementStatus::PENDING)
                    ->update(['settlement_status' => SettlementStatus::CANCELLED]);
            }

            // 実行ログの送信ステータスを更新
            if ($this->executionLogId) {
                WmsAutoOrderExecutionLog::where('id', $this->executionLogId)
                    ->update(['transmission_status' => 'FAILED']);
            }

            throw $e;
        }
    }

    /**
     * 単一バッチ処理（従来互換）
     */
    private function processSingleBatch(
        WmsQueueProgress $progress,
        array $allContractorIds,
        int $systemUserId,
        array &$results
    ): void {
        // Step 1: 残りPENDING発注候補を強制承認
        $progress->update(['progress' => 10, 'message' => '発注候補を承認中...']);

        $approvedOrders = WmsOrderCandidate::where('batch_code', $this->batchCode)
            ->where('status', CandidateStatus::PENDING)
            ->whereIn('contractor_id', $allContractorIds)
            ->update(['status' => CandidateStatus::APPROVED, 'modified_by' => $systemUserId, 'modified_at' => now()]);

        $results['steps']['approve_orders'] = $approvedOrders;
        Log::info('Auto-send: order candidates force-approved', ['count' => $approvedOrders, 'batch_code' => $this->batchCode]);

        // Step 2: 残りPENDING移動候補を強制承認（在庫確認含む）
        // Note: 移動候補はINTERNAL contractor IDを持つため、batch_codeでスコープする
        $progress->update(['progress' => 25, 'message' => '移動候補を承認中...']);

        $approvalService = app(TransferCandidateApprovalService::class);
        $pendingTransfers = WmsStockTransferCandidate::where('batch_code', $this->batchCode)
            ->where('status', CandidateStatus::PENDING)
            ->get();

        $approvedTransfers = 0;
        foreach ($pendingTransfers as $transfer) {
            try {
                $approvalService->approveCandidate($transfer, $systemUserId);
                $approvedTransfers++;
            } catch (\Exception $e) {
                Log::warning('Auto-send: transfer candidate approval failed, skipping', [
                    'candidate_id' => $transfer->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $results['steps']['approve_transfers'] = $approvedTransfers;

        // Step 3: APPROVED移動候補を確定（stock_transfer_queue生成）
        $progress->update(['progress' => 40, 'message' => '移動候補を確定中...']);

        $transferExecutionService = app(TransferCandidateExecutionService::class);
        $transferResults = $transferExecutionService->executeBatch($this->batchCode, $systemUserId);
        $results['steps']['execute_transfers'] = $transferResults->count();

        // Step 4: APPROVED発注候補を確定（入庫予定生成）
        $progress->update(['progress' => 55, 'message' => '発注候補を確定中...']);

        $orderExecutionService = app(OrderExecutionService::class);
        $incomingSchedules = $orderExecutionService->confirmBatch($this->batchCode, $systemUserId);
        $results['steps']['confirm_orders'] = $incomingSchedules->count();

        // Step 5: JXファイル生成
        $progress->update(['progress' => 70, 'message' => 'JXファイルを生成中...']);

        $transmissionService = app(OrderTransmissionService::class);
        $jxResult = $transmissionService->generateOrderFiles($this->batchCode);
        $results['steps']['jx_files'] = count($jxResult['files'] ?? []);

        // Step 6: JXファイル送信
        $progress->update(['progress' => 85, 'message' => 'JXファイルを送信中...']);

        $transmitResult = $transmissionService->transmitOrderFilesViaJx($this->batchCode);
        $results['steps']['jx_transmitted'] = count($transmitResult['transmitted'] ?? []);
        $results['steps']['jx_errors'] = count($transmitResult['errors'] ?? []);

        // Step 7: settlement_status更新
        $progress->update(['progress' => 95, 'message' => '確定処理中...']);

        WmsAutoOrderJobControl::where('batch_code', $this->batchCode)
            ->where('settlement_status', SettlementStatus::PENDING)
            ->update(['settlement_status' => SettlementStatus::CONFIRMED]);

        $results['steps']['settlement_updated'] = true;
    }

    /**
     * 仕入先の未送信候補を全バッチから統合処理
     */
    private function processAllUnsent(
        WmsQueueProgress $progress,
        array $allContractorIds,
        int $systemUserId,
        array &$results
    ): void {
        // Step 1: 残りPENDING発注候補を強制承認（全バッチ）
        $progress->update(['progress' => 10, 'message' => '発注候補を承認中（全バッチ統合）...']);

        $approvedOrders = WmsOrderCandidate::where('status', CandidateStatus::PENDING)
            ->whereIn('contractor_id', $allContractorIds)
            ->update(['status' => CandidateStatus::APPROVED, 'modified_by' => $systemUserId, 'modified_at' => now()]);

        $results['steps']['approve_orders'] = $approvedOrders;
        Log::info('Auto-send: order candidates force-approved (all batches)', ['count' => $approvedOrders, 'contractor_id' => $this->contractorId]);

        // Step 2: 残りPENDING移動候補を強制承認（関連バッチ）
        // Note: 移動候補はINTERNAL contractor IDを持つため、EXTERNAL contractor IDではヒットしない
        //       発注候補のbatch_codeから関連する移動候補を特定する
        $progress->update(['progress' => 25, 'message' => '移動候補を承認中...']);

        $relatedBatchCodes = WmsOrderCandidate::whereIn('contractor_id', $allContractorIds)
            ->whereIn('status', [CandidateStatus::PENDING, CandidateStatus::APPROVED, CandidateStatus::CONFIRMED])
            ->distinct()
            ->pluck('batch_code')
            ->toArray();

        $approvalService = app(TransferCandidateApprovalService::class);
        $pendingTransfers = ! empty($relatedBatchCodes)
            ? WmsStockTransferCandidate::where('status', CandidateStatus::PENDING)
                ->whereIn('batch_code', $relatedBatchCodes)
                ->get()
            : collect();

        $approvedTransfers = 0;
        foreach ($pendingTransfers as $transfer) {
            try {
                $approvalService->approveCandidate($transfer, $systemUserId);
                $approvedTransfers++;
            } catch (\Exception $e) {
                Log::warning('Auto-send: transfer candidate approval failed, skipping', [
                    'candidate_id' => $transfer->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $results['steps']['approve_transfers'] = $approvedTransfers;

        // Step 3: APPROVED移動候補を確定（関連バッチ）
        $progress->update(['progress' => 40, 'message' => '移動候補を確定中...']);

        $transferExecutionService = app(TransferCandidateExecutionService::class);
        $approvedTransferCandidates = ! empty($relatedBatchCodes)
            ? WmsStockTransferCandidate::where('status', CandidateStatus::APPROVED)
                ->whereIn('batch_code', $relatedBatchCodes)
                ->get()
            : collect();

        $executedTransfers = 0;
        foreach ($approvedTransferCandidates as $candidate) {
            try {
                $transferExecutionService->executeCandidate($candidate, $systemUserId);
                $executedTransfers++;
            } catch (\Exception $e) {
                Log::error('Failed to execute transfer candidate', [
                    'candidate_id' => $candidate->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        $results['steps']['execute_transfers'] = $executedTransfers;

        // Step 4: APPROVED発注候補を確定（全バッチ）
        $progress->update(['progress' => 55, 'message' => '発注候補を確定中...']);

        $orderExecutionService = app(OrderExecutionService::class);
        $approvedOrderCandidates = WmsOrderCandidate::whereIn('status', [CandidateStatus::APPROVED, CandidateStatus::CONFIRMED])
            ->whereIn('contractor_id', $allContractorIds)
            ->get();

        $confirmedOrders = 0;
        foreach ($approvedOrderCandidates as $candidate) {
            try {
                $orderExecutionService->confirmCandidate($candidate, $systemUserId);
                $confirmedOrders++;
            } catch (\Exception $e) {
                Log::error('Failed to confirm candidate', [
                    'candidate_id' => $candidate->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        $results['steps']['confirm_orders'] = $confirmedOrders;

        // Step 5: JXファイル生成（仕入先単位で未送信候補を統合）
        $progress->update(['progress' => 70, 'message' => 'JXファイルを生成中（統合）...']);

        $transmissionService = app(OrderTransmissionService::class);
        $jxResult = $transmissionService->generateOrderFilesForContractor($allContractorIds);
        $results['steps']['jx_files'] = count($jxResult['files'] ?? []);
        $results['batch_codes'] = $jxResult['batch_codes'] ?? [];

        // Step 6: JXファイル送信（未送信ドキュメントを1件ずつ送信）
        $progress->update(['progress' => 85, 'message' => 'JXファイルを送信中...']);

        $transmitResult = $transmissionService->transmitPendingDocumentsForContractor($allContractorIds);
        $results['steps']['jx_transmitted'] = count($transmitResult['transmitted'] ?? []);
        $results['steps']['jx_errors'] = count($transmitResult['errors'] ?? []);

        // Step 7: 影響する全バッチのsettlement_status更新
        $progress->update(['progress' => 95, 'message' => '確定処理中...']);

        $affectedBatchCodes = collect($results['batch_codes'] ?? []);

        // 候補から影響するバッチコードも収集
        $candidateBatchCodes = WmsOrderCandidate::whereIn('contractor_id', $allContractorIds)
            ->whereNotNull('wms_order_jx_document_id')
            ->distinct()
            ->pluck('batch_code');

        $allBatchCodes = $affectedBatchCodes->merge($candidateBatchCodes)->unique()->values()->toArray();

        if (! empty($allBatchCodes)) {
            WmsAutoOrderJobControl::whereIn('batch_code', $allBatchCodes)
                ->where('settlement_status', SettlementStatus::PENDING)
                ->update(['settlement_status' => SettlementStatus::CONFIRMED]);
        }

        $results['steps']['settlement_updated'] = true;
        $results['steps']['settled_batch_codes'] = $allBatchCodes;
    }
}
