<?php

namespace App\Services\JX\Eos;

use App\Models\WmsIncomingReceivedFile;
use App\Models\WmsJxEosImportBatch;
use App\Models\WmsJxTransmissionLog;
use App\Services\AutoOrder\IncomingReceiveService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class JxEosIncomingWorkflowService
{
    private const FORMAT_TYPE = 'JX';

    public function __construct(
        private readonly JxEosImportService $eosImportService,
        private readonly IncomingReceiveService $incomingReceiveService,
    ) {}

    /**
     * EOS原本の正規化取込、入荷受信ファイル作成、入荷予定照合、入荷予定更新を一括実行する。
     */
    public function importAndApply(
        WmsJxTransmissionLog $log,
        bool $forceEosReimport = false,
        bool $allowPartialApply = false,
    ): array {
        $workflowStartedAt = microtime(true);
        $this->assertImportableLog($log);
        $log->loadMissing(['jxSetting', 'currentEosImport']);

        [$disk, $path] = $this->resolveSource((string) $log->file_path);
        $content = $this->readSourceContent($disk, $path);

        $importStartedAt = microtime(true);
        $batch = $this->resolveEosBatch($log, $content, $disk, $forceEosReimport);
        Log::info('[JxEosIncomingWorkflow] EOS原本取込確認が完了しました', [
            'jx_transmission_log_id' => $log->id,
            'eos_import_batch_id' => $batch->id,
            'elapsed_ms' => $this->elapsedMs($importStartedAt),
        ]);

        $eosImported = $forceEosReimport
            || ! $log->currentEosImport
            || $log->currentEosImport->status !== WmsJxEosImportBatch::STATUS_SUCCEEDED;

        $file = $this->findExistingIncomingFile($log, $content);
        $incomingImported = false;

        if (! $file) {
            $parseStartedAt = microtime(true);
            $file = $this->parseIncomingFile($log, $content, $path);
            $incomingImported = true;
            Log::info('[JxEosIncomingWorkflow] 入荷受信ファイル作成が完了しました', [
                'jx_transmission_log_id' => $log->id,
                'incoming_received_file_id' => $file->id,
                'elapsed_ms' => $this->elapsedMs($parseStartedAt),
            ]);
        }

        $match = $this->summarizeMatchStatus($file);
        $apply = $this->emptyApplyResult();
        $skippedApplyReason = null;

        if ($file->isWorkflowTerminal()) {
            $skippedApplyReason = $file->isSkipped()
                ? 'リリース前EOS受信データとして処理対象外です。'
                : '既に入荷予定へ適用済みです。';
        } else {
            if ($file->status !== WmsIncomingReceivedFile::STATUS_MATCHED) {
                $matchStartedAt = microtime(true);
                $match = $this->incomingReceiveService->matchWithSchedules($file);
                $file->refresh();
                Log::info('[JxEosIncomingWorkflow] 入荷予定照合が完了しました', [
                    'jx_transmission_log_id' => $log->id,
                    'incoming_received_file_id' => $file->id,
                    'matched' => $match['matched'],
                    'shortage' => $match['shortage'],
                    'unmatched' => $match['unmatched'],
                    'elapsed_ms' => $this->elapsedMs($matchStartedAt),
                ]);
            }

            if ($file->status === WmsIncomingReceivedFile::STATUS_MATCHED
                || ($allowPartialApply && ($match['matched'] + $match['shortage']) > 0)
            ) {
                $applyStartedAt = microtime(true);
                $apply = $this->incomingReceiveService->applyMatched($file);
                $file->refresh();
                Log::info('[JxEosIncomingWorkflow] 入荷予定適用が完了しました', [
                    'jx_transmission_log_id' => $log->id,
                    'incoming_received_file_id' => $file->id,
                    'applied' => $apply['applied'],
                    'apply_errors' => count($apply['errors']),
                    'elapsed_ms' => $this->elapsedMs($applyStartedAt),
                ]);
            } else {
                $skippedApplyReason = "受信ファイルの状態が MATCHED ではありません（現在: {$file->status}）。";
            }
        }

        Log::info('[JxEosIncomingWorkflow] EOS取込と入荷予定更新が完了しました', [
            'jx_transmission_log_id' => $log->id,
            'message_id' => $log->message_id,
            'eos_import_batch_id' => $batch->id,
            'incoming_received_file_id' => $file->id,
            'incoming_file_status' => $file->status,
            'incoming_imported' => $incomingImported,
            'matched' => $match['matched'],
            'shortage' => $match['shortage'],
            'unmatched' => $match['unmatched'],
            'applied' => $apply['applied'],
            'apply_errors' => count($apply['errors']),
            'skipped_apply_reason' => $skippedApplyReason,
            'elapsed_ms' => $this->elapsedMs($workflowStartedAt),
        ]);

        return [
            'batch' => $batch,
            'eos_imported' => $eosImported,
            'received_file' => $file,
            'incoming_imported' => $incomingImported,
            'match' => $match,
            'apply' => $apply,
            'skipped_apply_reason' => $skippedApplyReason,
        ];
    }

    /**
     * EOS原本の正規化取込と入荷受信ファイル作成のみを実行する。
     */
    public function importOnly(
        WmsJxTransmissionLog $log,
        bool $forceEosReimport = false,
    ): array {
        $workflowStartedAt = microtime(true);
        $this->assertImportableLog($log);
        $log->loadMissing(['jxSetting', 'currentEosImport']);

        [$disk, $path] = $this->resolveSource((string) $log->file_path);
        $content = $this->readSourceContent($disk, $path);

        $importStartedAt = microtime(true);
        $batch = $this->resolveEosBatch($log, $content, $disk, $forceEosReimport);
        Log::info('[JxEosIncomingWorkflow] EOS原本取込確認が完了しました', [
            'jx_transmission_log_id' => $log->id,
            'eos_import_batch_id' => $batch->id,
            'elapsed_ms' => $this->elapsedMs($importStartedAt),
        ]);

        $eosImported = $forceEosReimport
            || ! $log->currentEosImport
            || $log->currentEosImport->status !== WmsJxEosImportBatch::STATUS_SUCCEEDED;

        $file = $this->findExistingIncomingFile($log, $content);
        $incomingImported = false;

        if (! $file) {
            $parseStartedAt = microtime(true);
            $file = $this->parseIncomingFile($log, $content, $path);
            $incomingImported = true;
            Log::info('[JxEosIncomingWorkflow] 入荷受信ファイル作成が完了しました', [
                'jx_transmission_log_id' => $log->id,
                'incoming_received_file_id' => $file->id,
                'elapsed_ms' => $this->elapsedMs($parseStartedAt),
            ]);
        }

        Log::info('[JxEosIncomingWorkflow] EOS取込のみが完了しました', [
            'jx_transmission_log_id' => $log->id,
            'message_id' => $log->message_id,
            'eos_import_batch_id' => $batch->id,
            'incoming_received_file_id' => $file->id,
            'incoming_file_status' => $file->status,
            'incoming_imported' => $incomingImported,
            'elapsed_ms' => $this->elapsedMs($workflowStartedAt),
        ]);

        return [
            'batch' => $batch,
            'eos_imported' => $eosImported,
            'received_file' => $file,
            'incoming_imported' => $incomingImported,
        ];
    }

    private function assertImportableLog(WmsJxTransmissionLog $log): void
    {
        if ($log->direction !== WmsJxTransmissionLog::DIRECTION_RECEIVE) {
            throw new \RuntimeException('EOS取込は受信ログのみ対象です。');
        }

        if ($log->operation_type !== WmsJxTransmissionLog::OPERATION_GET) {
            throw new \RuntimeException('EOS取込はGetDocumentログのみ対象です。');
        }

        if ($log->status !== WmsJxTransmissionLog::STATUS_SUCCESS) {
            throw new \RuntimeException('EOS取込は成功ログのみ対象です。');
        }

        if (blank($log->file_path)) {
            throw new \RuntimeException('保存ファイルパスがないためEOS取込できません。');
        }
    }

    private function resolveEosBatch(
        WmsJxTransmissionLog $log,
        string $content,
        string $disk,
        bool $forceEosReimport
    ): WmsJxEosImportBatch {
        if (
            ! $forceEosReimport
            && $log->currentEosImport
            && $log->currentEosImport->status === WmsJxEosImportBatch::STATUS_SUCCEEDED
        ) {
            return $log->currentEosImport;
        }

        return $this->eosImportService->importFromContent(
            $log,
            $content,
            $disk,
            $log->file_path
        );
    }

    private function parseIncomingFile(WmsJxTransmissionLog $log, string $content, string $path): WmsIncomingReceivedFile
    {
        $filename = basename($path) ?: "jx_receive_log_{$log->id}.dat";
        $metadata = [
            'raw_file_path' => $log->file_path,
            'raw_file_size' => strlen($content),
            'raw_sha256' => hash('sha256', $content),
            'received_message_id' => filled($log->message_id) ? $log->message_id : null,
            'confirm_status' => 'SENT',
            'confirmed_at' => $log->transmitted_at ?? $log->created_at,
        ];

        try {
            return $this->incomingReceiveService->parseJxData(
                $content,
                $filename,
                $this->logContractorId($log),
                $metadata
            );
        } catch (\Throwable $throwable) {
            WmsIncomingReceivedFile::create(WmsIncomingReceivedFile::onlyExistingColumns(array_merge([
                'contractor_id' => $this->logContractorId($log),
                'filename' => $filename,
                'format_type' => self::FORMAT_TYPE,
                'status' => 'ERROR',
                'error_message' => $throwable->getMessage(),
            ], $metadata)));

            throw $throwable;
        }
    }

    private function findExistingIncomingFile(WmsJxTransmissionLog $log, string $content): ?WmsIncomingReceivedFile
    {
        $query = WmsIncomingReceivedFile::query()
            ->where('format_type', self::FORMAT_TYPE)
            ->whereIn('status', [
                WmsIncomingReceivedFile::STATUS_PENDING,
                WmsIncomingReceivedFile::STATUS_MATCHED,
                ...WmsIncomingReceivedFile::TERMINAL_STATUSES,
            ])
            ->orderByDesc('id');

        if (filled($log->message_id)) {
            return $query
                ->where('received_message_id', $log->message_id)
                ->first();
        }

        return $query
            ->where('raw_sha256', hash('sha256', $content))
            ->first();
    }

    private function logContractorId(WmsJxTransmissionLog $log): ?int
    {
        $log->loadMissing('jxSetting');
        $contractorId = (int) ($log->jxSetting?->contractor_id ?? 0);

        return $contractorId > 0 ? $contractorId : null;
    }

    private function summarizeMatchStatus(WmsIncomingReceivedFile $file): array
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

    private function emptyApplyResult(): array
    {
        return [
            'applied' => 0,
            'schedule_ids' => [],
            'errors' => [],
        ];
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function resolveSource(string $filePath): array
    {
        if (str_starts_with($filePath, 's3:')) {
            return ['s3', ltrim(substr($filePath, 3), '/')];
        }

        if (str_starts_with($filePath, 'local:')) {
            return ['local', ltrim(substr($filePath, 6), '/')];
        }

        if (is_file($filePath)) {
            return ['absolute', $filePath];
        }

        return ['s3', $filePath];
    }

    private function readSourceContent(string $disk, string $path): string
    {
        if ($disk === 'absolute') {
            $content = file_get_contents($path);

            if ($content === false) {
                throw new \RuntimeException("EOS原本ファイルを読み込めません: {$path}");
            }

            return $content;
        }

        if (! Storage::disk($disk)->exists($path)) {
            throw new \RuntimeException("EOS原本ファイルが存在しません: {$disk}:{$path}");
        }

        $content = Storage::disk($disk)->get($path);
        if ($content === null || $content === false) {
            throw new \RuntimeException("EOS原本ファイルを読み込めません: {$disk}:{$path}");
        }

        return $content;
    }
}
