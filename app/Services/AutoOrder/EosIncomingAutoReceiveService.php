<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Models\WmsEosIncomingReceiveRun;
use App\Models\WmsEosIncomingReceiveSchedule;
use App\Models\WmsEosIncomingReceiveSetting;
use App\Models\WmsIncomingReceivedFile;
use App\Models\WmsJxTransmissionLog;
use App\Models\WmsOrderIncomingSchedule;
use App\Models\WmsOrderJxSetting;
use App\Services\JX\Eos\JxEosIncomingWorkflowService;
use App\Services\JX\JxDocumentReceiver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EosIncomingAutoReceiveService
{
    private const OLD_SHORTAGE_COMPLETION_CHUNK_SIZE = 1000;

    public function __construct(
        private readonly JxEosIncomingWorkflowService $workflowService,
        private readonly IncomingTransmissionService $transmissionService,
    ) {}

    public function run(
        string $runKey,
        string $triggerType,
        ?WmsEosIncomingReceiveSchedule $schedule = null,
        bool $autoPurchaseTransmission = true,
    ): WmsEosIncomingReceiveRun {
        [$run, $isDuplicate] = $this->startRun($runKey, $triggerType, $schedule);

        if ($isDuplicate) {
            return $run;
        }

        $stats = $this->emptyStats();
        $metadata = $this->emptyMetadata();

        try {
            $setting = $run->setting ?: WmsEosIncomingReceiveSetting::ensureDefault();
            $setting->loadMissing('schedules');

            $run->addLog('info', 'start', 'EOSデータ自動受信を開始しました', [
                'trigger_type' => $triggerType,
                'schedule_id' => $schedule?->id,
                'schedule_label' => $schedule?->label(),
            ]);

            [$receivedDocumentCount, $targetLogs, $receiveErrors] = $this->receiveJxDocuments($run);
            $stats['received_jx_document_count'] = $receivedDocumentCount;
            $stats['target_jx_log_count'] = $targetLogs->count();
            $stats['error_count'] += count($receiveErrors);
            $metadata['jx_transmission_log_ids'] = $targetLogs->pluck('id')->map(fn ($id): int => (int) $id)->all();

            return $this->completeRun($run, $setting, $targetLogs, $stats, $metadata, $autoPurchaseTransmission);
        } catch (\Throwable $throwable) {
            return $this->failRun($run, $stats, $metadata, $throwable);
        }
    }

    public function runReceiveAndImportOnly(
        string $runKey,
        string $triggerType,
        ?WmsEosIncomingReceiveSchedule $schedule = null,
    ): WmsEosIncomingReceiveRun {
        [$run, $isDuplicate] = $this->startRun($runKey, $triggerType, $schedule);

        if ($isDuplicate) {
            return $run;
        }

        $stats = $this->emptyStats();
        $metadata = $this->emptyMetadata();

        try {
            $setting = $run->setting ?: WmsEosIncomingReceiveSetting::ensureDefault();

            $run->addLog('info', 'start', 'JXデータ受信とEOSログ取込のみを開始しました', [
                'trigger_type' => $triggerType,
                'schedule_id' => $schedule?->id,
                'schedule_label' => $schedule?->label(),
            ]);

            [$receivedDocumentCount, $targetLogs, $receiveErrors] = $this->receiveJxDocuments($run);
            $stats['received_jx_document_count'] = $receivedDocumentCount;
            $stats['target_jx_log_count'] = $targetLogs->count();
            $stats['error_count'] += count($receiveErrors);
            $metadata['jx_transmission_log_ids'] = $targetLogs->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $metadata['incoming_received_file_ids'] = $this->importLogsOnly($run, $targetLogs, $stats);
            $stats['active_jx_setting_count'] = WmsOrderJxSetting::query()->active()->count();

            $status = $stats['error_count'] > 0
                ? WmsEosIncomingReceiveRun::STATUS_PARTIAL_FAILED
                : WmsEosIncomingReceiveRun::STATUS_SUCCEEDED;

            $run->update(array_merge($stats, [
                'status' => $status,
                'finished_at' => now(),
                'error_summary' => $this->errorSummary($run),
                'metadata' => $this->limitMetadata($metadata),
            ]));

            $setting->update(['last_run_at' => now()]);

            $run->addLog('info', 'finish', 'JXデータ受信とEOSログ取込のみが完了しました', [
                'status' => $status,
                'stats' => $stats,
            ]);

            return $run->fresh(['logs']);
        } catch (\Throwable $throwable) {
            return $this->failRun($run, $stats, $metadata, $throwable);
        }
    }

    public function runForJxTransmissionLogs(
        array $jxTransmissionLogIds,
        string $runKey,
        string $triggerType = WmsEosIncomingReceiveRun::TRIGGER_MANUAL,
        bool $autoPurchaseTransmission = true,
    ): WmsEosIncomingReceiveRun {
        [$run, $isDuplicate] = $this->startRun($runKey, $triggerType, null);

        if ($isDuplicate) {
            return $run;
        }

        $stats = $this->emptyStats();
        $metadata = $this->emptyMetadata();

        try {
            $setting = $run->setting ?: WmsEosIncomingReceiveSetting::ensureDefault();
            $setting->loadMissing('schedules');

            $requestedIds = $this->normalizeJxTransmissionLogIds($jxTransmissionLogIds);
            $targetLogs = $this->manualTargetLogs($requestedIds);
            $targetIds = $targetLogs->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $skippedIds = array_values(array_diff($requestedIds, $targetIds));

            $stats['active_jx_setting_count'] = WmsOrderJxSetting::query()->active()->count();
            $stats['target_jx_log_count'] = $targetLogs->count();
            $metadata['requested_jx_transmission_log_ids'] = $requestedIds;
            $metadata['jx_transmission_log_ids'] = $targetIds;

            $run->addLog('info', 'start', 'EOSデータ手動取込を開始しました', [
                'trigger_type' => $triggerType,
                'requested_jx_transmission_log_ids' => $requestedIds,
                'target_jx_transmission_log_ids' => $targetIds,
                'auto_purchase_transmission' => $autoPurchaseTransmission,
            ]);

            if ($skippedIds !== []) {
                $run->addLog('warning', 'target_filter', '取込対象外または処理済みのJXログをスキップしました', [
                    'skipped_jx_transmission_log_ids' => $skippedIds,
                ]);
            }

            return $this->completeRun($run, $setting, $targetLogs, $stats, $metadata, $autoPurchaseTransmission);
        } catch (\Throwable $throwable) {
            return $this->failRun($run, $stats, $metadata, $throwable);
        }
    }

    private function startRun(
        string $runKey,
        string $triggerType,
        ?WmsEosIncomingReceiveSchedule $schedule,
    ): array {
        return DB::connection('sakemaru')->transaction(function () use ($runKey, $triggerType, $schedule): array {
            $existing = WmsEosIncomingReceiveRun::query()
                ->where('run_key', $runKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return [$existing, true];
            }

            $setting = $schedule?->setting ?: WmsEosIncomingReceiveSetting::ensureDefault();

            $run = WmsEosIncomingReceiveRun::query()->create([
                'run_key' => $runKey,
                'setting_id' => $setting->id,
                'schedule_id' => $schedule?->id,
                'execution_date' => now()->toDateString(),
                'scheduled_time' => $schedule?->receive_time,
                'trigger_type' => $triggerType,
                'status' => WmsEosIncomingReceiveRun::STATUS_RUNNING,
                'started_at' => now(),
            ]);

            return [$run, false];
        });
    }

    private function receiveJxDocuments(WmsEosIncomingReceiveRun $run): array
    {
        $settings = WmsOrderJxSetting::query()
            ->active()
            ->orderBy('id')
            ->get();

        $run->update(['active_jx_setting_count' => $settings->count()]);

        if ($settings->isEmpty()) {
            $run->addLog('warning', 'receive', '有効なJX接続設定がありません。');

            return [0, collect(), []];
        }

        $receiveDocumentTypes = $settings
            ->pluck('receive_document_type')
            ->map(fn ($documentType): string => trim((string) $documentType))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $receivedCount = 0;
        $messageIds = [];
        $errors = [];

        foreach ($settings as $setting) {
            try {
                $receiver = new JxDocumentReceiver($setting);
                $receiver->setStorageDisk('s3');
                $receiver->setEnvironment(WmsJxTransmissionLog::ENV_PRODUCTION);

                $documents = $receiver->receiveAll();
                $receivedCount += $documents->count();

                foreach ($documents as $document) {
                    if (filled($document->messageId)) {
                        $messageIds[] = (string) $document->messageId;
                    }
                }

                $run->addLog('info', 'receive', "{$setting->name}: JX受信 {$documents->count()}件", [
                    'jx_setting_id' => $setting->id,
                    'document_count' => $documents->count(),
                ]);

                if ($receiver->getLastError() !== null) {
                    $errors[] = "{$setting->name}: {$receiver->getLastError()}";
                    $run->addLog('error', 'receive', "{$setting->name}: {$receiver->getLastError()}", [
                        'jx_setting_id' => $setting->id,
                    ]);
                }
            } catch (\Throwable $throwable) {
                $errors[] = "{$setting->name}: {$throwable->getMessage()}";
                $run->addLog('error', 'receive', "{$setting->name}: {$throwable->getMessage()}", [
                    'jx_setting_id' => $setting->id,
                ]);
            }
        }

        $messageIds = array_values(array_unique(array_filter($messageIds)));
        if ($messageIds === []) {
            return [$receivedCount, collect(), $errors];
        }

        $logs = WmsJxTransmissionLog::query()
            ->pendingEosIncomingImport()
            ->where('environment', WmsJxTransmissionLog::ENV_PRODUCTION)
            ->whereIn('message_id', $messageIds)
            ->when($receiveDocumentTypes !== [], fn ($query) => $query->whereIn('document_type', $receiveDocumentTypes))
            ->orderBy('id')
            ->get();

        return [$receivedCount, $logs, $errors];
    }

    private function importAndApplyLogs(WmsEosIncomingReceiveRun $run, $logs, array &$stats): array
    {
        $confirmedScheduleIds = [];

        foreach ($logs as $log) {
            try {
                $result = $this->workflowService->importAndApply(
                    $log,
                    forceEosReimport: false,
                    allowPartialApply: true,
                );

                $match = $result['match'];
                $apply = $result['apply'];
                $file = $result['received_file'];

                $stats['eos_imported_count']++;
                $stats['incoming_matched_count'] += (int) $match['matched'] + (int) $match['shortage'];
                $stats['incoming_unmatched_count'] += (int) $match['unmatched'];
                $stats['unknown_slip_count'] += (int) $match['unmatched'];
                $stats['incoming_confirmed_schedule_count'] += (int) $apply['applied'];
                $stats['error_count'] += count($apply['errors']);

                $autoConfirmedUnknownScheduleIds = $this->autoConfirmUnassignedJxSlips($run, $file, $stats);
                $confirmedScheduleIds = array_merge(
                    $confirmedScheduleIds,
                    $apply['schedule_ids'] ?? [],
                    $autoConfirmedUnknownScheduleIds,
                );

                $run->addLog('info', 'import_apply', "JXログ {$log->id}: EOS取込と入荷予定更新を実行しました", [
                    'jx_transmission_log_id' => $log->id,
                    'incoming_received_file_id' => $file->id,
                    'matched' => $match['matched'],
                    'shortage' => $match['shortage'],
                    'unmatched' => $match['unmatched'],
                    'applied' => $apply['applied'],
                    'auto_confirmed_unknown_schedules' => count($autoConfirmedUnknownScheduleIds),
                    'apply_errors' => count($apply['errors']),
                    'skipped_apply_reason' => $result['skipped_apply_reason'],
                ]);

                foreach ($apply['errors'] as $error) {
                    $run->addLog('error', 'import_apply', $error['error'] ?? '入荷予定更新エラー', [
                        'jx_transmission_log_id' => $log->id,
                        'incoming_received_file_id' => $file->id,
                        'error' => $error,
                    ]);
                }
            } catch (\Throwable $throwable) {
                $stats['error_count']++;
                $run->addLog('error', 'import_apply', "JXログ {$log->id}: {$throwable->getMessage()}", [
                    'jx_transmission_log_id' => $log->id,
                    'exception' => get_class($throwable),
                ]);
            }
        }

        return collect($confirmedScheduleIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function autoConfirmUnassignedJxSlips(
        WmsEosIncomingReceiveRun $run,
        WmsIncomingReceivedFile $file,
        array &$stats,
    ): array {
        $slips = $file->slips()
            ->where('match_status', 'NO_ASSIGNMENT')
            ->whereDoesntHave('importErrors', function ($query): void {
                $query
                    ->whereIn('error_code', ['ITEM_NOT_FOUND', 'SCHEDULE_ITEM_NOT_FOUND'])
                    ->where('is_resolved', true);
            })
            ->with(['file', 'details'])
            ->orderBy('id')
            ->get();

        if ($slips->isEmpty()) {
            return [];
        }

        $service = app(IncomingReceiveService::class);
        $scheduleIds = [];
        $confirmedSlipCount = 0;

        foreach ($slips as $slip) {
            try {
                $result = $service->confirmUnassignedJxSlip($slip, 0);
                $resultScheduleIds = collect($result['schedule_ids'] ?? [])
                    ->map(fn ($id): int => (int) $id)
                    ->filter(fn (int $id): bool => $id > 0)
                    ->values()
                    ->all();

                $scheduleIds = array_merge($scheduleIds, $resultScheduleIds);
                $confirmedSlipCount++;
                $stats['incoming_confirmed_schedule_count'] += (int) ($result['created'] ?? 0) + (int) ($result['updated'] ?? 0);

                $run->addLog('info', 'unknown_slip_auto_confirm', "伝票番号不明JX受信伝票 {$slip->slip_number} を入荷完了として自動作成しました", [
                    'incoming_received_file_id' => $file->id,
                    'received_slip_id' => $slip->id,
                    'slip_number' => $slip->slip_number,
                    'created' => $result['created'] ?? 0,
                    'updated' => $result['updated'] ?? 0,
                    'skipped' => $result['skipped'] ?? 0,
                    'schedule_ids' => $resultScheduleIds,
                ]);
            } catch (\Throwable $throwable) {
                $run->addLog('warning', 'unknown_slip_auto_confirm', "伝票番号不明JX受信伝票 {$slip->slip_number} は自動入荷完了できませんでした", [
                    'incoming_received_file_id' => $file->id,
                    'received_slip_id' => $slip->id,
                    'slip_number' => $slip->slip_number,
                    'error' => $throwable->getMessage(),
                ]);
            }
        }

        if ($confirmedSlipCount > 0) {
            $stats['incoming_matched_count'] += $confirmedSlipCount;
            $stats['incoming_unmatched_count'] = max(0, (int) $stats['incoming_unmatched_count'] - $confirmedSlipCount);
            $stats['unknown_slip_count'] = max(0, (int) $stats['unknown_slip_count'] - $confirmedSlipCount);
        }

        return collect($scheduleIds)
            ->unique()
            ->values()
            ->all();
    }

    private function importLogsOnly(WmsEosIncomingReceiveRun $run, $logs, array &$stats): array
    {
        if ($logs->isEmpty()) {
            $run->addLog('info', 'import_only', '今回受信したEOS取込対象ログはありません。');

            return [];
        }

        $incomingReceivedFileIds = [];

        foreach ($logs as $log) {
            try {
                $result = $this->workflowService->importOnly($log);
                $file = $result['received_file'];

                $stats['eos_imported_count']++;
                $incomingReceivedFileIds[] = (int) $file->id;

                $run->addLog('info', 'import_only', "JXログ {$log->id}: EOSログ取込を実行しました", [
                    'jx_transmission_log_id' => $log->id,
                    'eos_import_batch_id' => $result['batch']->id,
                    'incoming_received_file_id' => $file->id,
                    'incoming_file_status' => $file->status,
                    'eos_imported' => $result['eos_imported'],
                    'incoming_imported' => $result['incoming_imported'],
                ]);
            } catch (\Throwable $throwable) {
                $stats['error_count']++;
                $run->addLog('error', 'import_only', "JXログ {$log->id}: {$throwable->getMessage()}", [
                    'jx_transmission_log_id' => $log->id,
                    'exception' => get_class($throwable),
                ]);
            }
        }

        return collect($incomingReceivedFileIds)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function completeRun(
        WmsEosIncomingReceiveRun $run,
        WmsEosIncomingReceiveSetting $setting,
        $targetLogs,
        array $stats,
        array $metadata,
        bool $autoPurchaseTransmission,
    ): WmsEosIncomingReceiveRun {
        $confirmedScheduleIds = $this->importAndApplyLogs($run, $targetLogs, $stats);
        $metadata['confirmed_schedule_ids'] = $confirmedScheduleIds;

        if ($autoPurchaseTransmission) {
            [$autoPurchaseScheduleIds, $purchaseSkipped] = $this->filterAutoPurchaseScheduleIds(
                $confirmedScheduleIds,
                (string) $setting->exclude_purchase_warehouse_code,
            );
            $stats['purchase_skipped_warehouse91_count'] = $purchaseSkipped['warehouse'];
            $stats['purchase_skipped_not_eos_sent_count'] = $purchaseSkipped['not_eos_sent'];
            $metadata['auto_purchase_schedule_ids'] = $autoPurchaseScheduleIds;

            $transmitResult = $this->transmitPurchases($run, $autoPurchaseScheduleIds);
            $stats['purchase_queue_count'] = $transmitResult['queue_count'];
            $stats['purchase_transmitted_schedule_count'] = $transmitResult['schedule_count'];
            $stats['error_count'] += count($transmitResult['errors']);
            $metadata['purchase_errors'] = $transmitResult['errors'];
        } else {
            $run->addLog('info', 'purchase_transmission', '仕入データ自動生成は無効のためスキップしました');
        }

        $stats['shortage_completed_count'] = $this->completeOldSchedulesAsShortage($run, $setting);
        $stats['active_jx_setting_count'] = WmsOrderJxSetting::query()->active()->count();

        $status = $stats['error_count'] > 0
            ? WmsEosIncomingReceiveRun::STATUS_PARTIAL_FAILED
            : WmsEosIncomingReceiveRun::STATUS_SUCCEEDED;

        $run->update(array_merge($stats, [
            'status' => $status,
            'finished_at' => now(),
            'error_summary' => $this->errorSummary($run),
            'metadata' => $this->limitMetadata($metadata),
        ]));

        $setting->update(['last_run_at' => now()]);

        $run->addLog('info', 'finish', 'EOSデータ受信処理が完了しました', [
            'status' => $status,
            'stats' => $stats,
        ]);

        return $run->fresh(['logs']);
    }

    private function normalizeJxTransmissionLogIds(array $ids): array
    {
        return collect($ids)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function manualTargetLogs(array $ids)
    {
        if ($ids === []) {
            return collect();
        }

        return WmsJxTransmissionLog::query()
            ->pendingEosIncomingImport()
            ->where('environment', WmsJxTransmissionLog::ENV_PRODUCTION)
            ->whereKey($ids)
            ->orderBy('id')
            ->get();
    }

    private function filterAutoPurchaseScheduleIds(array $scheduleIds, string $excludeWarehouseCode): array
    {
        $scheduleIds = collect($scheduleIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($scheduleIds === []) {
            return [[], ['warehouse' => 0, 'not_eos_sent' => 0]];
        }

        $eligibleIds = [];
        $skippedWarehouse = 0;
        $skippedNotEosSent = 0;

        $schedules = WmsOrderIncomingSchedule::query()
            ->whereKey($scheduleIds)
            ->where('status', IncomingScheduleStatus::CONFIRMED->value)
            ->whereNull('purchase_queue_id')
            ->with(['warehouse', 'orderCandidate'])
            ->orderBy('id')
            ->get();

        foreach ($schedules as $schedule) {
            $warehouseCode = trim((string) ($schedule->warehouse?->code ?? ''));

            if ($excludeWarehouseCode !== '' && $warehouseCode === $excludeWarehouseCode) {
                $skippedWarehouse++;

                continue;
            }

            if (! $schedule->isEosSent()) {
                $skippedNotEosSent++;

                continue;
            }

            $eligibleIds[] = (int) $schedule->id;
        }

        return [
            $eligibleIds,
            [
                'warehouse' => $skippedWarehouse,
                'not_eos_sent' => $skippedNotEosSent,
            ],
        ];
    }

    private function transmitPurchases(WmsEosIncomingReceiveRun $run, array $scheduleIds): array
    {
        if ($scheduleIds === []) {
            $run->addLog('info', 'purchase_transmission', '仕入データ自動生成対象の入荷確定データはありません。');

            return [
                'success' => true,
                'queue_count' => 0,
                'schedule_count' => 0,
                'errors' => [],
            ];
        }

        $result = $this->transmissionService->transmitConfirmedIncomings(scheduleIds: $scheduleIds);

        $run->addLog($result['success'] ? 'info' : 'warning', 'purchase_transmission', '仕入データ自動生成を実行しました', [
            'queue_count' => $result['queue_count'],
            'schedule_count' => $result['schedule_count'],
            'error_count' => count($result['errors']),
        ]);

        foreach ($result['errors'] as $error) {
            $run->addLog('error', 'purchase_transmission', $error['error'] ?? '仕入データ自動生成エラー', [
                'error' => $error,
            ]);
        }

        return $result;
    }

    private function completeOldSchedulesAsShortage(
        WmsEosIncomingReceiveRun $run,
        WmsEosIncomingReceiveSetting $setting,
    ): int {
        $purchaseCompleted = $this->completePurchaseSchedulesAsShortage($run, $setting);
        $transferCompleted = $this->completeTransferSchedulesAsReceived($run);

        return $purchaseCompleted + $transferCompleted;
    }

    private function completePurchaseSchedulesAsShortage(
        WmsEosIncomingReceiveRun $run,
        WmsEosIncomingReceiveSetting $setting,
    ): int {
        $cutoffDate = Carbon::today()->subDays((int) $setting->shortage_completion_days)->toDateString();

        $completed = $this->completeSchedulesAsShortage(
            WmsOrderIncomingSchedule::query()
                ->withoutTransferSource()
                ->where('order_date', '<=', $cutoffDate)
                ->whereNull('purchase_queue_id'),
        );

        $run->addLog('info', 'old_purchase_schedule_cleanup', "発注日が{$setting->shortage_completion_days}日前までの入荷予定を自動整理しました: {$completed}件", [
            'completed_count' => $completed,
            'cutoff_order_date' => $cutoffDate,
            'target' => 'purchase',
        ]);

        return $completed;
    }

    private function completeTransferSchedulesAsReceived(WmsEosIncomingReceiveRun $run): int
    {
        $cutoffDate = Carbon::today()->subDay()->toDateString();

        $completed = $this->completeSchedulesAsReceived(
            WmsOrderIncomingSchedule::query()
                ->withTransferSource()
                ->where('expected_arrival_date', '<=', $cutoffDate),
        );

        $run->addLog('info', 'old_transfer_completion', "移動系の予定日を1日以上過ぎた入荷予定を完了しました: {$completed}件", [
            'completed_count' => $completed,
            'cutoff_date' => $cutoffDate,
            'target' => 'transfer',
        ]);

        return $completed;
    }

    private function completeSchedulesAsReceived($query): int
    {
        $completed = 0;
        $now = now();

        $query
            ->whereIn('status', [
                IncomingScheduleStatus::PENDING->value,
                IncomingScheduleStatus::PARTIAL->value,
            ])
            ->select('id')
            ->orderBy('id')
            ->chunkById(self::OLD_SHORTAGE_COMPLETION_CHUNK_SIZE, function ($schedules) use (&$completed, $now): void {
                $ids = $schedules
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->filter(fn (int $id): bool => $id > 0)
                    ->all();

                if ($ids === []) {
                    return;
                }

                $completed += WmsOrderIncomingSchedule::query()
                    ->whereKey($ids)
                    ->whereIn('status', [
                        IncomingScheduleStatus::PENDING->value,
                        IncomingScheduleStatus::PARTIAL->value,
                    ])
                    ->update([
                        'received_quantity' => DB::raw('COALESCE(`expected_quantity`, 0)'),
                        'shipped_quantity' => DB::raw('COALESCE(`expected_quantity`, 0)'),
                        'shortage_quantity' => 0,
                        'actual_arrival_date' => DB::raw('COALESCE(`actual_arrival_date`, `expected_arrival_date`, CURRENT_DATE)'),
                        'status' => IncomingScheduleStatus::CONFIRMED->value,
                        'confirmed_at' => $now,
                        'confirmed_by' => 0,
                        'confirmed_picker_id' => null,
                        'updated_at' => $now,
                    ]);
            });

        return $completed;
    }

    private function completeSchedulesAsShortage($query): int
    {
        $completed = 0;
        $now = now();

        $query
            ->whereIn('status', [
                IncomingScheduleStatus::PENDING->value,
                IncomingScheduleStatus::PARTIAL->value,
            ])
            ->select('id')
            ->orderBy('id')
            ->chunkById(self::OLD_SHORTAGE_COMPLETION_CHUNK_SIZE, function ($schedules) use (&$completed, $now): void {
                $ids = $schedules
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->filter(fn (int $id): bool => $id > 0)
                    ->all();

                if ($ids === []) {
                    return;
                }

                $completed += WmsOrderIncomingSchedule::query()
                    ->whereKey($ids)
                    ->whereIn('status', [
                        IncomingScheduleStatus::PENDING->value,
                        IncomingScheduleStatus::PARTIAL->value,
                    ])
                    ->update([
                        'received_quantity' => 0,
                        'shipped_quantity' => 0,
                        'shortage_quantity' => DB::raw('GREATEST(0, COALESCE(`expected_quantity`, 0))'),
                        'actual_arrival_date' => DB::raw('COALESCE(`actual_arrival_date`, `expected_arrival_date`, CURRENT_DATE)'),
                        'status' => IncomingScheduleStatus::DELETED->value,
                        'confirmed_at' => $now,
                        'confirmed_by' => 0,
                        'confirmed_picker_id' => null,
                        'updated_at' => $now,
                    ]);
            });

        return $completed;
    }

    private function emptyStats(): array
    {
        return [
            'active_jx_setting_count' => 0,
            'received_jx_document_count' => 0,
            'target_jx_log_count' => 0,
            'eos_imported_count' => 0,
            'incoming_matched_count' => 0,
            'incoming_unmatched_count' => 0,
            'incoming_confirmed_schedule_count' => 0,
            'purchase_queue_count' => 0,
            'purchase_transmitted_schedule_count' => 0,
            'purchase_skipped_warehouse91_count' => 0,
            'purchase_skipped_not_eos_sent_count' => 0,
            'unknown_slip_count' => 0,
            'shortage_completed_count' => 0,
            'error_count' => 0,
        ];
    }

    private function emptyMetadata(): array
    {
        return [
            'requested_jx_transmission_log_ids' => [],
            'jx_transmission_log_ids' => [],
            'incoming_received_file_ids' => [],
            'confirmed_schedule_ids' => [],
            'auto_purchase_schedule_ids' => [],
            'purchase_errors' => [],
        ];
    }

    private function failRun(
        WmsEosIncomingReceiveRun $run,
        array $stats,
        array $metadata,
        \Throwable $throwable,
    ): WmsEosIncomingReceiveRun {
        Log::error('[EosIncomingAutoReceive] EOSデータ受信処理でエラーが発生しました', [
            'run_id' => $run->id,
            'run_key' => $run->run_key,
            'error' => $throwable->getMessage(),
        ]);
        report($throwable);

        $run->addLog('error', 'fatal', $throwable->getMessage(), [
            'exception' => get_class($throwable),
        ]);
        $run->update(array_merge($stats, [
            'status' => WmsEosIncomingReceiveRun::STATUS_FAILED,
            'finished_at' => now(),
            'error_count' => $stats['error_count'] + 1,
            'error_summary' => $throwable->getMessage(),
            'metadata' => $this->limitMetadata($metadata),
        ]));

        return $run->fresh(['logs']);
    }

    private function errorSummary(WmsEosIncomingReceiveRun $run): ?string
    {
        $messages = $run->logs()
            ->where('level', 'error')
            ->latest('id')
            ->limit(5)
            ->pluck('message')
            ->reverse()
            ->values()
            ->all();

        return $messages === [] ? null : implode("\n", $messages);
    }

    private function limitMetadata(array $metadata): array
    {
        foreach (['requested_jx_transmission_log_ids', 'jx_transmission_log_ids', 'incoming_received_file_ids', 'confirmed_schedule_ids', 'auto_purchase_schedule_ids'] as $key) {
            if (count($metadata[$key] ?? []) > 200) {
                $metadata[$key] = array_slice($metadata[$key], 0, 200);
                $metadata["{$key}_truncated"] = true;
            }
        }

        if (count($metadata['purchase_errors'] ?? []) > 20) {
            $metadata['purchase_errors'] = array_slice($metadata['purchase_errors'], 0, 20);
            $metadata['purchase_errors_truncated'] = true;
        }

        return $metadata;
    }
}
