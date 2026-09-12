<?php

namespace App\Console\Commands\AutoOrder;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\OrderChannel;
use App\Enums\AutoOrder\OrderDataFileChannel;
use App\Models\WmsOrderCandidate;
use App\Models\WmsOrderDataFile;
use App\Services\AutoOrder\OrderRegistrationSearchService;
use Illuminate\Console\Command;

class BackfillOrderChannelsCommand extends Command
{
    protected $signature = 'wms:backfill-order-channels
        {--chunk=500 : 1回に処理する件数}
        {--dry-run : 更新せず件数のみ確認}';

    protected $description = '既存の発注確定済みデータへEOS/FAX発注区分を後方互換ルールで補完する';

    public function handle(OrderRegistrationSearchService $searchService): int
    {
        $chunkSize = max(50, min(2000, (int) $this->option('chunk')));
        $dryRun = (bool) $this->option('dry-run');
        $jxContractorIds = $searchService->jxContractorIds();

        $this->info('既存発注区分バックフィルを開始します'.($dryRun ? '（dry-run）' : ''));

        $eos = 0;
        $fax = 0;
        $processed = 0;

        WmsOrderCandidate::query()
            ->whereNull('order_channel')
            ->where('status', CandidateStatus::CONFIRMED)
            ->orderBy('id')
            ->chunkById($chunkSize, function ($candidates) use ($dryRun, $jxContractorIds, &$eos, &$fax, &$processed): void {
                foreach ($candidates as $candidate) {
                    $channel = in_array((int) $candidate->contractor_id, $jxContractorIds, true)
                        && ! $this->hasBlockingFaxDataFile($candidate)
                            ? OrderChannel::EOS
                            : OrderChannel::FAX;

                    if ($channel === OrderChannel::EOS) {
                        $eos++;
                    } else {
                        $fax++;
                    }

                    $processed++;

                    if (! $dryRun) {
                        $candidate->update(['order_channel' => $channel]);
                    }
                }
            });

        $this->info("処理対象: {$processed}件 / EOS: {$eos}件 / FAX: {$fax}件");

        return self::SUCCESS;
    }

    private function hasBlockingFaxDataFile(WmsOrderCandidate $candidate): bool
    {
        return WmsOrderDataFile::query()
            ->where('batch_code', $candidate->batch_code)
            ->where('warehouse_id', $candidate->warehouse_id)
            ->where('contractor_id', $candidate->contractor_id)
            ->where('expected_arrival_date', $candidate->expected_arrival_date)
            ->where(function ($query): void {
                $query
                    ->whereNull('order_channel')
                    ->orWhere('order_channel', OrderDataFileChannel::FAX->value);
            })
            ->where(function ($query) use ($candidate): void {
                $query
                    ->whereNull('candidate_ids')
                    ->orWhereRaw('JSON_CONTAINS(candidate_ids, JSON_ARRAY(?))', [(int) $candidate->id]);
            })
            ->exists();
    }
}
