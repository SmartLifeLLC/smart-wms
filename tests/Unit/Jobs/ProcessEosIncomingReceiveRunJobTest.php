<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessEosIncomingReceiveRunJob;
use App\Models\WmsEosIncomingReceiveRun;
use App\Services\AutoOrder\EosIncomingAutoReceiveService;
use Mockery;
use Tests\TestCase;

class ProcessEosIncomingReceiveRunJobTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_receive_and_import_only_flag_calls_receive_only_runner(): void
    {
        $job = new ProcessEosIncomingReceiveRunJob(
            'manual:test',
            null,
            WmsEosIncomingReceiveRun::TRIGGER_MANUAL,
            false,
            null,
            true,
        );

        $service = Mockery::mock(EosIncomingAutoReceiveService::class);
        $service->shouldReceive('runReceiveAndImportOnly')
            ->once()
            ->with('manual:test', WmsEosIncomingReceiveRun::TRIGGER_MANUAL, null)
            ->andReturn(new WmsEosIncomingReceiveRun);
        $service->shouldReceive('run')->never();
        $service->shouldReceive('runForJxTransmissionLogs')->never();

        $job->handle($service);

        $this->addToAssertionCount(1);
    }

    public function test_default_job_calls_full_auto_receive_runner(): void
    {
        $job = new ProcessEosIncomingReceiveRunJob(
            'scheduled:test',
            null,
            WmsEosIncomingReceiveRun::TRIGGER_SCHEDULED,
            true,
        );

        $service = Mockery::mock(EosIncomingAutoReceiveService::class);
        $service->shouldReceive('run')
            ->once()
            ->with('scheduled:test', WmsEosIncomingReceiveRun::TRIGGER_SCHEDULED, null, true)
            ->andReturn(new WmsEosIncomingReceiveRun);
        $service->shouldReceive('runReceiveAndImportOnly')->never();
        $service->shouldReceive('runForJxTransmissionLogs')->never();

        $job->handle($service);

        $this->addToAssertionCount(1);
    }

    public function test_target_jx_logs_keep_using_selected_log_runner(): void
    {
        $job = new ProcessEosIncomingReceiveRunJob(
            'manual-jx-eos:test',
            null,
            WmsEosIncomingReceiveRun::TRIGGER_MANUAL,
            true,
            [10, 20],
            true,
        );

        $service = Mockery::mock(EosIncomingAutoReceiveService::class);
        $service->shouldReceive('runForJxTransmissionLogs')
            ->once()
            ->with([10, 20], 'manual-jx-eos:test', WmsEosIncomingReceiveRun::TRIGGER_MANUAL, true)
            ->andReturn(new WmsEosIncomingReceiveRun);
        $service->shouldReceive('run')->never();
        $service->shouldReceive('runReceiveAndImportOnly')->never();

        $job->handle($service);

        $this->addToAssertionCount(1);
    }
}
