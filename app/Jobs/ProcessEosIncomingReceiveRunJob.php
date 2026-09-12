<?php

namespace App\Jobs;

use App\Models\WmsEosIncomingReceiveRun;
use App\Models\WmsEosIncomingReceiveSchedule;
use App\Services\AutoOrder\EosIncomingAutoReceiveService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessEosIncomingReceiveRunJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public int $uniqueFor = 7200;

    public function __construct(
        public string $runKey,
        public ?int $scheduleId = null,
        public string $triggerType = WmsEosIncomingReceiveRun::TRIGGER_SCHEDULED,
        public bool $autoPurchaseTransmission = true,
        public ?array $targetJxTransmissionLogIds = null,
        public bool $receiveAndImportOnly = false,
    ) {}

    public function handle(EosIncomingAutoReceiveService $service): void
    {
        $schedule = $this->scheduleId
            ? WmsEosIncomingReceiveSchedule::query()->with('setting')->find($this->scheduleId)
            : null;

        if ($this->targetJxTransmissionLogIds !== null) {
            $service->runForJxTransmissionLogs(
                $this->targetJxTransmissionLogIds,
                $this->runKey,
                $this->triggerType,
                $this->autoPurchaseTransmission,
            );

            return;
        }

        if ($this->receiveAndImportOnly) {
            $service->runReceiveAndImportOnly($this->runKey, $this->triggerType, $schedule);

            return;
        }

        $service->run($this->runKey, $this->triggerType, $schedule, $this->autoPurchaseTransmission);
    }

    public function uniqueId(): string
    {
        if ($this->targetJxTransmissionLogIds !== null) {
            $ids = collect($this->targetJxTransmissionLogIds)
                ->map(fn ($id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->sort()
                ->values()
                ->implode(',');

            return 'manual-jx-eos:'.$ids;
        }

        return $this->runKey;
    }
}
