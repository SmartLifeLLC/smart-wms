<?php

namespace App\Console\Commands;

use App\Models\QuantityUpdateQueue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RetryTransientQuantityUpdateFailuresCommand extends Command
{
    protected $signature = 'wms:retry-transient-quantity-update-failures
                            {--limit=20 : Maximum rows to reset per run}
                            {--min-age-seconds=10 : Minimum age after failure before retry}
                            {--cooldown-minutes=60 : Minimum minutes before retrying the same queue again}
                            {--max-retries=3 : Maximum automatic retries per queue}
                            {--dry-run : Show retry candidates without updating}';

    protected $description = 'Reset transient quantity_update_queue failures back to BEFORE for retry.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $minAgeSeconds = max(0, (int) $this->option('min-age-seconds'));
        $cooldownMinutes = max(1, (int) $this->option('cooldown-minutes'));
        $maxRetries = max(1, (int) $this->option('max-retries'));
        $dryRun = (bool) $this->option('dry-run');

        $candidates = QuantityUpdateQueue::query()
            ->where('status', QuantityUpdateQueue::STATUS_FINISHED)
            ->where('is_success', false)
            ->where(function ($query) {
                $query->where('error_message', 'like', '%SAVEPOINT trans% does not exist%')
                    ->orWhere('error_message', 'like', '%Deadlock found when trying to get lock%')
                    ->orWhere('error_message', 'like', '%Lock wait timeout exceeded%');
            })
            ->where('updated_at', '<=', now()->subSeconds($minAgeSeconds))
            ->orderBy('id')
            ->limit($limit)
            ->get([
                'id',
                'request_id',
                'status',
                'is_success',
                'error_message',
                'updated_at',
            ]);

        if ($candidates->isEmpty()) {
            $this->info('No transient quantity_update_queue failures found.');

            return self::SUCCESS;
        }

        $retried = 0;
        $skipped = 0;

        foreach ($candidates as $candidate) {
            $cacheKey = $this->cooldownCacheKey((int) $candidate->id);

            if (Cache::has($cacheKey)) {
                $skipped++;
                $this->line("skip queue_id={$candidate->id}: retry cooldown active");

                continue;
            }

            $retryAttempt = $this->retryAttemptCount((int) $candidate->id);
            if ($retryAttempt >= $maxRetries) {
                $skipped++;
                $this->line("skip queue_id={$candidate->id}: max retries reached");

                continue;
            }

            if ($dryRun) {
                $this->line("dry-run queue_id={$candidate->id} request_id={$candidate->request_id}");
                $retried++;

                continue;
            }

            $updated = DB::connection($candidate->getConnectionName())->transaction(function () use ($candidate): int {
                $locked = QuantityUpdateQueue::query()
                    ->whereKey($candidate->id)
                    ->lockForUpdate()
                    ->first();

                if (! $locked
                    || $locked->status !== QuantityUpdateQueue::STATUS_FINISHED
                    || $locked->is_success !== false
                    || ! $this->isTransientFailure((string) $locked->error_message)
                ) {
                    return 0;
                }

                return QuantityUpdateQueue::query()
                    ->whereKey($locked->id)
                    ->update([
                        'status' => QuantityUpdateQueue::STATUS_BEFORE,
                        'is_success' => null,
                        'error_message' => null,
                        'updated_at' => now(),
                    ]);
            });

            if ($updated === 0) {
                $skipped++;
                $this->line("skip queue_id={$candidate->id}: state changed");

                continue;
            }

            Cache::put($cacheKey, true, now()->addMinutes($cooldownMinutes));
            $retryAttempt = $this->incrementRetryAttemptCount((int) $candidate->id);
            $retried++;

            Log::warning('Reset transient quantity_update_queue failure for retry', [
                'queue_id' => $candidate->id,
                'request_id' => $candidate->request_id,
                'cooldown_minutes' => $cooldownMinutes,
                'retry_attempt' => $retryAttempt,
                'max_retries' => $maxRetries,
            ]);

            $this->info("retry queued queue_id={$candidate->id} request_id={$candidate->request_id} attempt={$retryAttempt}/{$maxRetries}");
        }

        $this->info("Completed. retried={$retried} skipped={$skipped}");

        return self::SUCCESS;
    }

    private function cooldownCacheKey(int $queueId): string
    {
        return "quantity-update-queue:transient-retry:{$queueId}";
    }

    private function retryAttemptCount(int $queueId): int
    {
        return (int) Cache::get($this->retryAttemptCountCacheKey($queueId), 0);
    }

    private function incrementRetryAttemptCount(int $queueId): int
    {
        $attempts = $this->retryAttemptCount($queueId) + 1;
        Cache::put($this->retryAttemptCountCacheKey($queueId), $attempts, now()->addDay());

        return $attempts;
    }

    private function retryAttemptCountCacheKey(int $queueId): string
    {
        return "quantity-update-queue:transient-retry-count:{$queueId}";
    }

    private function isTransientFailure(string $errorMessage): bool
    {
        return preg_match('/SAVEPOINT trans\\d+ does not exist/', $errorMessage) === 1
            || str_contains($errorMessage, 'Deadlock found when trying to get lock')
            || str_contains($errorMessage, 'Lock wait timeout exceeded');
    }
}
