<?php

namespace App\Console\Commands\AutoOrder;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\OrderChannel;
use App\Enums\AutoOrder\OrderDataFileChannel;
use App\Enums\AutoOrder\TransmissionType;
use App\Models\WmsContractorSetting;
use App\Models\WmsJxOrderGenerationRun;
use App\Models\WmsOrderCandidate;
use App\Services\AutoOrder\JxOrderArrivalDateAdjustmentService;
use App\Services\AutoOrder\OrderTransmissionService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class GenerateJxOrderDocumentsCommand extends Command
{
    protected $signature = 'wms:generate-jx-order-documents
        {--date= : 生成対象日 YYYY-MM-DD}
        {--time= : 現在時刻として扱う時刻 HH:MM}
        {--contractor= : 代表または子の発注先ID/CD}
        {--force : 既存の失敗runを再実行}
        {--dry-run : DB更新・ファイル生成を行わず対象だけ確認}';

    protected $description = 'JX発注データを設定時刻と締め時刻に基づいて自動生成する（送信はしない）';

    public function handle(
        JxOrderArrivalDateAdjustmentService $arrivalDateAdjustmentService,
        OrderTransmissionService $orderTransmissionService
    ): int {
        $targetDate = $this->resolveTargetDate();
        $currentTime = $this->resolveCurrentTime();
        $dayOfWeek = $targetDate->dayOfWeek;
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $this->info("=== JX発注データ自動生成 {$targetDate->format('Y-m-d')} {$currentTime} ===");

        $settings = $this->targetSettings($dayOfWeek, $currentTime);

        if ($settings->isEmpty()) {
            $this->info('生成対象のJX設定はありません');

            return self::SUCCESS;
        }

        $successCount = 0;
        $failedCount = 0;
        $skippedCount = 0;

        foreach ($settings as $setting) {
            $representativeContractorId = (int) $setting->contractor_id;
            $generationTime = $setting->jxGenerationTimeForDay($dayOfWeek);
            $cutoffTime = $setting->jxGenerationCutoffTimeForDay($dayOfWeek);
            $label = $setting->contractor
                ? "[{$setting->contractor->code}]{$setting->contractor->name}"
                : "発注先ID:{$representativeContractorId}";

            if ($generationTime === null || $cutoffTime === null) {
                $this->line("  {$label} → スキップ（生成時刻または締め時刻が未設定）");
                $skippedCount++;

                continue;
            }

            $contractorIds = WmsContractorSetting::getContractorIdsWithChildren($representativeContractorId);
            [$modifiedFrom, $modifiedUntil] = $this->targetModifiedAtRange($setting, $targetDate, $cutoffTime);
            $candidates = $this->targetCandidates($contractorIds, $modifiedFrom, $modifiedUntil);

            if ($candidates->isEmpty()) {
                $this->line("  {$label} → 対象候補なし");
                $skippedCount++;

                continue;
            }

            if ($dryRun) {
                $adjustment = $arrivalDateAdjustmentService->adjust($candidates, $targetDate, true);
                $this->line(sprintf(
                    '  %s → dry-run 候補:%d 補正予定:%d 除外:%d 生成対象:%d',
                    $label,
                    $candidates->count(),
                    $adjustment['adjusted_count'],
                    $adjustment['excluded_count'],
                    count($adjustment['eligible_candidate_ids'])
                ));

                continue;
            }

            $run = $this->startRun($representativeContractorId, $targetDate, $generationTime, $cutoffTime, $force);

            if ($run === null) {
                $this->line("  {$label} → スキップ（同日runあり）");
                $skippedCount++;

                continue;
            }

            try {
                $adjustment = $arrivalDateAdjustmentService->adjust($candidates, $targetDate, false);
                $eligibleCandidateIds = $adjustment['eligible_candidate_ids'];

                if (empty($eligibleCandidateIds)) {
                    $this->finishRun($run, WmsJxOrderGenerationRun::STATUS_SKIPPED, [
                        'candidate_count' => $candidates->count(),
                        'eligible_candidate_count' => 0,
                        'adjusted_candidate_count' => $adjustment['adjusted_count'],
                        'generated_document_count' => 0,
                        'generated_order_count' => 0,
                        'summary' => $this->summary($adjustment, []),
                        'error_message' => '入荷予定日補正不可のため生成対象がありません',
                    ]);

                    $this->line("  {$label} → スキップ（補正不可）");
                    $skippedCount++;

                    continue;
                }

                $generationResult = $orderTransmissionService->generateJxFilesForCandidateIds($eligibleCandidateIds);
                $status = ($generationResult['success'] ?? false)
                    ? WmsJxOrderGenerationRun::STATUS_SUCCESS
                    : WmsJxOrderGenerationRun::STATUS_FAILED;

                $this->finishRun($run, $status, [
                    'candidate_count' => $candidates->count(),
                    'eligible_candidate_count' => count($eligibleCandidateIds),
                    'adjusted_candidate_count' => $adjustment['adjusted_count'],
                    'generated_document_count' => count($generationResult['files'] ?? []),
                    'generated_order_count' => (int) ($generationResult['total_orders'] ?? 0),
                    'summary' => $this->summary($adjustment, $generationResult),
                    'error_message' => $status === WmsJxOrderGenerationRun::STATUS_SUCCESS
                        ? null
                        : implode("\n", array_slice($generationResult['errors'] ?? ['JX生成に失敗しました'], 0, 10)),
                ]);

                if ($status === WmsJxOrderGenerationRun::STATUS_SUCCESS) {
                    $this->line(sprintf(
                        '  %s → 生成完了 候補:%d 補正:%d 文書:%d 発注:%d',
                        $label,
                        $candidates->count(),
                        $adjustment['adjusted_count'],
                        count($generationResult['files'] ?? []),
                        (int) ($generationResult['total_orders'] ?? 0)
                    ));
                    $successCount++;
                } else {
                    $this->line("  {$label} → 生成失敗");
                    $failedCount++;
                }
            } catch (Throwable $e) {
                $this->finishRun($run, WmsJxOrderGenerationRun::STATUS_FAILED, [
                    'candidate_count' => $candidates->count(),
                    'summary' => ['exception' => $e::class],
                    'error_message' => $e->getMessage(),
                ]);

                report($e);
                $this->error("  {$label} → 例外: {$e->getMessage()}");
                $failedCount++;
            }
        }

        $this->newLine();
        $this->info("完了: 成功 {$successCount} / 失敗 {$failedCount} / スキップ {$skippedCount}");

        return $failedCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolveTargetDate(): Carbon
    {
        $date = $this->option('date');

        return $date ? Carbon::parse($date)->startOfDay() : now()->startOfDay();
    }

    private function resolveCurrentTime(): string
    {
        $time = $this->option('time');

        if ($time === null || $time === '') {
            return now()->format('H:i');
        }

        return Carbon::parse($time)->format('H:i');
    }

    /**
     * @return Collection<int, WmsContractorSetting>
     */
    private function targetSettings(int $dayOfWeek, string $currentTime): Collection
    {
        $query = WmsContractorSetting::query()
            ->where('transmission_type', TransmissionType::JX_FINET->value)
            ->where('is_jx_auto_generation_enabled', true)
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('transmission_contractor_id')
                    ->orWhereColumn('transmission_contractor_id', 'contractor_id');
            })
            ->with('contractor:id,code,name')
            ->orderBy('contractor_id');

        if ($contractor = $this->option('contractor')) {
            $representativeContractorId = $this->resolveRepresentativeContractorId((string) $contractor);

            if ($representativeContractorId === null) {
                return collect();
            }

            $query->where('contractor_id', $representativeContractorId);
        }

        return $query
            ->get()
            ->filter(function (WmsContractorSetting $setting) use ($dayOfWeek, $currentTime): bool {
                $generationTime = $setting->jxGenerationTimeForDay($dayOfWeek);
                $cutoffTime = $setting->jxGenerationCutoffTimeForDay($dayOfWeek);

                return $generationTime !== null
                    && $cutoffTime !== null
                    && $generationTime <= $currentTime;
            })
            ->values();
    }

    private function resolveRepresentativeContractorId(string $contractor): ?int
    {
        $row = DB::connection('sakemaru')
            ->table('contractors')
            ->where('id', $contractor)
            ->orWhere('code', $contractor)
            ->first(['id']);

        if (! $row) {
            return null;
        }

        $setting = WmsContractorSetting::query()
            ->where('contractor_id', (int) $row->id)
            ->first(['contractor_id', 'transmission_contractor_id']);

        if ($setting?->transmission_contractor_id) {
            return (int) $setting->transmission_contractor_id;
        }

        return (int) $row->id;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function targetModifiedAtRange(
        WmsContractorSetting $setting,
        Carbon $targetDate,
        string $cutoffTime
    ): array {
        $modifiedUntil = Carbon::parse($targetDate->format('Y-m-d').' '.$cutoffTime.':00');
        $previousDate = $targetDate->copy()->subDay();
        $previousCutoffTime = $setting->jxGenerationCutoffTimeForDay($previousDate->dayOfWeek);

        $modifiedFrom = $previousCutoffTime
            ? Carbon::parse($previousDate->format('Y-m-d').' '.$previousCutoffTime.':00')
            : $previousDate->startOfDay();

        return [$modifiedFrom, $modifiedUntil];
    }

    /**
     * @param  array<int>  $contractorIds
     * @return Collection<int, WmsOrderCandidate>
     */
    private function targetCandidates(array $contractorIds, Carbon $modifiedFrom, Carbon $modifiedUntil): Collection
    {
        $candidateTable = (new WmsOrderCandidate)->getTable();

        return WmsOrderCandidate::query()
            ->whereIn('contractor_id', $contractorIds)
            ->where('status', CandidateStatus::CONFIRMED)
            ->where('order_quantity', '>', 0)
            ->whereNull('wms_order_jx_document_id')
            ->where('modified_at', '>=', $modifiedFrom)
            ->where('modified_at', '<=', $modifiedUntil)
            ->where(function ($query) use ($candidateTable): void {
                $query
                    ->where("{$candidateTable}.order_channel", OrderChannel::EOS->value)
                    ->orWhere(function ($query) use ($candidateTable): void {
                        $query
                            ->whereNull("{$candidateTable}.order_channel")
                            ->whereNotExists(function ($query) use ($candidateTable): void {
                                $query
                                    ->selectRaw('1')
                                    ->from('wms_order_data_files')
                                    ->whereColumn('wms_order_data_files.batch_code', "{$candidateTable}.batch_code")
                                    ->whereColumn('wms_order_data_files.warehouse_id', "{$candidateTable}.warehouse_id")
                                    ->whereColumn('wms_order_data_files.contractor_id', "{$candidateTable}.contractor_id")
                                    ->whereColumn('wms_order_data_files.expected_arrival_date', "{$candidateTable}.expected_arrival_date")
                                    ->where(function ($query): void {
                                        $query
                                            ->whereNull('wms_order_data_files.order_channel')
                                            ->orWhere('wms_order_data_files.order_channel', OrderDataFileChannel::FAX->value);
                                    })
                                    ->where(function ($query) use ($candidateTable): void {
                                        $query
                                            ->whereNull('wms_order_data_files.candidate_ids')
                                            ->orWhereRaw("JSON_CONTAINS(wms_order_data_files.candidate_ids, JSON_ARRAY({$candidateTable}.id))");
                                    });
                            });
                    });
            })
            ->with(['item', 'contractor', 'warehouse'])
            ->orderBy('contractor_id')
            ->orderBy('warehouse_id')
            ->orderBy('expected_arrival_date')
            ->orderBy('id')
            ->get();
    }

    private function startRun(
        int $representativeContractorId,
        Carbon $targetDate,
        string $generationTime,
        string $cutoffTime,
        bool $force
    ): ?WmsJxOrderGenerationRun {
        return DB::connection('sakemaru')->transaction(function () use (
            $representativeContractorId,
            $targetDate,
            $generationTime,
            $cutoffTime,
            $force
        ): ?WmsJxOrderGenerationRun {
            $now = now();
            $inserted = DB::connection('sakemaru')
                ->table('wms_jx_order_generation_runs')
                ->insertOrIgnore([
                    'representative_contractor_id' => $representativeContractorId,
                    'target_date' => $targetDate->format('Y-m-d'),
                    'generation_time' => $generationTime,
                    'cutoff_time' => $cutoffTime,
                    'status' => WmsJxOrderGenerationRun::STATUS_RUNNING,
                    'started_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

            $run = WmsJxOrderGenerationRun::query()
                ->where('representative_contractor_id', $representativeContractorId)
                ->where('target_date', $targetDate->format('Y-m-d'))
                ->lockForUpdate()
                ->first();

            if (! $run) {
                return null;
            }

            if (! $inserted && $run->status === WmsJxOrderGenerationRun::STATUS_RUNNING) {
                return null;
            }

            if (! $inserted && ! $force) {
                return null;
            }

            if (! $inserted && $run->status === WmsJxOrderGenerationRun::STATUS_SUCCESS) {
                return null;
            }

            $run->update([
                'generation_time' => $generationTime,
                'cutoff_time' => $cutoffTime,
                'status' => WmsJxOrderGenerationRun::STATUS_RUNNING,
                'candidate_count' => 0,
                'eligible_candidate_count' => 0,
                'adjusted_candidate_count' => 0,
                'generated_document_count' => 0,
                'generated_order_count' => 0,
                'summary' => null,
                'error_message' => null,
                'started_at' => $now,
                'finished_at' => null,
            ]);

            return $run->refresh();
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function finishRun(WmsJxOrderGenerationRun $run, string $status, array $data): void
    {
        $run->update([
            'status' => $status,
            'candidate_count' => $data['candidate_count'] ?? $run->candidate_count,
            'eligible_candidate_count' => $data['eligible_candidate_count'] ?? $run->eligible_candidate_count,
            'adjusted_candidate_count' => $data['adjusted_candidate_count'] ?? $run->adjusted_candidate_count,
            'generated_document_count' => $data['generated_document_count'] ?? $run->generated_document_count,
            'generated_order_count' => $data['generated_order_count'] ?? $run->generated_order_count,
            'summary' => $data['summary'] ?? null,
            'error_message' => $data['error_message'] ?? null,
            'finished_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $adjustment
     * @param  array<string, mixed>  $generationResult
     * @return array<string, mixed>
     */
    private function summary(array $adjustment, array $generationResult): array
    {
        return [
            'arrival_date_adjustment' => [
                'adjusted_count' => $adjustment['adjusted_count'] ?? 0,
                'excluded_count' => $adjustment['excluded_count'] ?? 0,
                'errors' => array_slice($adjustment['errors'] ?? [], 0, 20),
            ],
            'generation' => [
                'success' => $generationResult['success'] ?? null,
                'selected_count' => $generationResult['selected_count'] ?? null,
                'eligible_count' => $generationResult['eligible_count'] ?? null,
                'excluded_count' => $generationResult['excluded_count'] ?? null,
                'skipped_count' => $generationResult['skipped_count'] ?? null,
                'errors' => array_slice($generationResult['errors'] ?? [], 0, 20),
            ],
        ];
    }
}
