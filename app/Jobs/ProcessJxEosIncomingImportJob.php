<?php

namespace App\Jobs;

use App\Models\WmsJxTransmissionLog;
use App\Services\JX\Eos\JxEosIncomingWorkflowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessJxEosIncomingImportJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(
        public int $jxTransmissionLogId,
        public bool $forceEosReimport = false,
    ) {}

    public function handle(JxEosIncomingWorkflowService $service): void
    {
        $log = WmsJxTransmissionLog::query()->findOrFail($this->jxTransmissionLogId);

        $service->importAndApply($log, forceEosReimport: $this->forceEosReimport);
    }

    public function uniqueId(): string
    {
        return (string) $this->jxTransmissionLogId;
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }
}
