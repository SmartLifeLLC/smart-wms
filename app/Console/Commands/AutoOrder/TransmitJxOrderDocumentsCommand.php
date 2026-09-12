<?php

namespace App\Console\Commands\AutoOrder;

use App\Enums\AutoOrder\TransmissionDocumentStatus;
use App\Enums\AutoOrder\TransmissionType;
use App\Models\Sakemaru\Contractor;
use App\Models\WmsContractorSetting;
use App\Models\WmsOrderJxDocument;
use App\Services\AutoOrder\OrderTransmissionService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class TransmitJxOrderDocumentsCommand extends Command
{
    protected $signature = 'wms:transmit-jx-order-documents
        {--date= : 送信対象日 YYYY-MM-DD}
        {--time= : 現在時刻として扱う時刻 HH:MM}
        {--contractor= : 代表または子の発注先ID/CD}
        {--force : 送信時刻前でも実行する}
        {--dry-run : DB更新・JX送信を行わず対象だけ確認}';

    protected $description = '生成済みのJX発注データを自動送信する';

    public function handle(OrderTransmissionService $transmissionService): int
    {
        $targetDate = $this->resolveTargetDate();
        [$orderDateFrom, $orderDateUntil] = $this->targetOrderDateRange($targetDate);
        $currentTime = $this->resolveCurrentTime();
        $dayOfWeek = $targetDate->dayOfWeek;
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $this->info("=== JX発注データ自動送信 {$targetDate->format('Y-m-d')} {$currentTime} ===");

        $settings = $this->targetSettings($dayOfWeek, $currentTime, $force, $dryRun);

        if ($settings->isEmpty()) {
            $this->info('自動送信対象のJX発注先はありません');

            return self::SUCCESS;
        }

        $summary = [];
        $totalTransmitted = 0;
        $totalSkipped = 0;
        $totalErrors = 0;

        foreach ($settings as $setting) {
            $label = $setting->contractor
                ? "[{$setting->contractor->code}]{$setting->contractor->name}"
                : "発注先ID:{$setting->contractor_id}";
            $contractorIds = WmsContractorSetting::getContractorIdsWithChildren((int) $setting->contractor_id);
            $transmissionTime = $setting->jxTransmissionTimeForDay($dayOfWeek);

            if ($transmissionTime === null) {
                $this->line("  {$label} → スキップ（JX送信時刻が未設定）");
                $totalSkipped++;

                continue;
            }

            if ($dryRun) {
                $pendingCount = $this->pendingDocumentCount($contractorIds, $orderDateFrom, $orderDateUntil);
                $this->line("  {$label} → dry-run 送信時刻:{$transmissionTime} 対象発注日:{$orderDateFrom->format('Y-m-d')}〜{$orderDateUntil->format('Y-m-d')} 送信対象JX文書:{$pendingCount}");
                $summary[] = [
                    'contractor_id' => $setting->contractor_id,
                    'transmission_time' => $transmissionTime,
                    'order_date_from' => $orderDateFrom->toDateString(),
                    'order_date_until' => $orderDateUntil->toDateString(),
                    'pending_documents' => $pendingCount,
                ];

                continue;
            }

            try {
                $result = $transmissionService->transmitPendingJxDocumentsForContractor(
                    $contractorIds,
                    $orderDateFrom,
                    $orderDateUntil
                );
                $transmitted = count($result['transmitted'] ?? []);
                $skipped = count($result['skipped'] ?? []) + count($result['skipped_non_jx'] ?? []);
                $errors = count($result['errors'] ?? []);

                $totalTransmitted += $transmitted;
                $totalSkipped += $skipped;
                $totalErrors += $errors;

                $summary[] = [
                    'contractor_id' => $setting->contractor_id,
                    'label' => $label,
                    'transmission_time' => $transmissionTime,
                    'order_date_from' => $orderDateFrom->toDateString(),
                    'order_date_until' => $orderDateUntil->toDateString(),
                    'transmitted' => $transmitted,
                    'skipped' => $skipped,
                    'errors' => $errors,
                    'message' => $result['message'] ?? null,
                ];

                if ($errors > 0) {
                    Log::error('JX auto transmission contractor failed', [
                        'contractor_id' => $setting->contractor_id,
                        'transmission_time' => $transmissionTime,
                        'order_date_from' => $orderDateFrom->toDateString(),
                        'order_date_until' => $orderDateUntil->toDateString(),
                        'errors' => $result['errors'],
                    ]);
                    $this->error("  {$label} → 送信エラー {$errors}件");
                } else {
                    Log::info('JX auto transmission contractor completed', [
                        'contractor_id' => $setting->contractor_id,
                        'transmission_time' => $transmissionTime,
                        'order_date_from' => $orderDateFrom->toDateString(),
                        'order_date_until' => $orderDateUntil->toDateString(),
                        'transmitted' => $transmitted,
                        'skipped' => $skipped,
                    ]);
                    $this->line("  {$label} → 送信完了 {$transmitted}件 / スキップ {$skipped}件");
                }
            } catch (Throwable $e) {
                $totalErrors++;
                $summary[] = [
                    'contractor_id' => $setting->contractor_id,
                    'label' => $label,
                    'transmission_time' => $transmissionTime,
                    'order_date_from' => $orderDateFrom->toDateString(),
                    'order_date_until' => $orderDateUntil->toDateString(),
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ];

                Log::error('JX auto transmission contractor exception', [
                    'contractor_id' => $setting->contractor_id,
                    'transmission_time' => $transmissionTime,
                    'order_date_from' => $orderDateFrom->toDateString(),
                    'order_date_until' => $orderDateUntil->toDateString(),
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);
                $this->error("  {$label} → 例外: {$e->getMessage()}");
            }
        }

        $context = [
            'target_date' => $targetDate->toDateString(),
            'order_date_from' => $orderDateFrom->toDateString(),
            'order_date_until' => $orderDateUntil->toDateString(),
            'current_time' => $currentTime,
            'target_contractors' => $settings->count(),
            'transmitted' => $totalTransmitted,
            'skipped' => $totalSkipped,
            'errors' => $totalErrors,
            'summary' => $summary,
        ];

        Log::info('JX auto transmission finished', $context);

        if ($dryRun) {
            $this->info('dry-run のため送信は行っていません');
        } elseif ($totalErrors > 0) {
            $this->sendSlackError('JX自動送信: エラーあり', $context);
        } elseif ($totalTransmitted > 0 || $totalSkipped > 0) {
            $this->sendSlackInfo('JX自動送信: 完了', $context);
        }

        $this->newLine();
        $this->info("完了: 送信 {$totalTransmitted} / スキップ {$totalSkipped} / エラー {$totalErrors}");

        return $totalErrors > 0 ? self::FAILURE : self::SUCCESS;
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
     * @return array{0: Carbon, 1: Carbon}
     */
    private function targetOrderDateRange(Carbon $targetDate): array
    {
        return [
            $targetDate->copy()->subDay()->startOfDay(),
            $targetDate->copy()->startOfDay(),
        ];
    }

    /**
     * @return Collection<int, WmsContractorSetting>
     */
    private function targetSettings(int $dayOfWeek, string $currentTime, bool $force, bool $dryRun): Collection
    {
        $dayColumn = $this->transmissionDayColumn($dayOfWeek);
        $query = WmsContractorSetting::query()
            ->where('transmission_type', TransmissionType::JX_FINET->value)
            ->where('is_auto_transmission', true)
            ->where($dayColumn, true)
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
            ->filter(function (WmsContractorSetting $setting) use ($dayOfWeek, $currentTime, $force, $dryRun): bool {
                $transmissionTime = $setting->jxTransmissionTimeForDay($dayOfWeek);

                return $transmissionTime !== null
                    && ($force || $dryRun || $transmissionTime <= $currentTime);
            })
            ->values();
    }

    private function transmissionDayColumn(int $dayOfWeek): string
    {
        return match ($dayOfWeek) {
            0 => 'is_transmission_sun',
            1 => 'is_transmission_mon',
            2 => 'is_transmission_tue',
            3 => 'is_transmission_wed',
            4 => 'is_transmission_thu',
            5 => 'is_transmission_fri',
            6 => 'is_transmission_sat',
            default => 'is_transmission_sun',
        };
    }

    private function resolveRepresentativeContractorId(string $contractor): ?int
    {
        $row = Contractor::query()
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
     * @param  array<int>  $contractorIds
     */
    private function pendingDocumentCount(array $contractorIds, Carbon $orderDateFrom, Carbon $orderDateUntil): int
    {
        return WmsOrderJxDocument::query()
            ->where('status', TransmissionDocumentStatus::PENDING)
            ->whereIn('contractor_id', array_unique(array_map('intval', $contractorIds)))
            ->whereDate('order_date', '>=', $orderDateFrom->toDateString())
            ->whereDate('order_date', '<=', $orderDateUntil->toDateString())
            ->count();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function sendSlackInfo(string $message, array $context): void
    {
        $this->sendSlack('info', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function sendSlackError(string $message, array $context): void
    {
        $this->sendSlack('error', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function sendSlack(string $level, string $message, array $context): void
    {
        if (blank(config('logging.channels.slack.url'))) {
            return;
        }

        try {
            Log::channel('slack')->{$level}($message, $context);
        } catch (Throwable $e) {
            Log::warning('Failed to send JX auto transmission log to Slack', [
                'level' => $level,
                'message' => $message,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
