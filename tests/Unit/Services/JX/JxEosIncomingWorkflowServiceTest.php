<?php

namespace Tests\Unit\Services\JX;

use App\Models\WmsIncomingReceivedFile;
use App\Models\WmsJxEosImportBatch;
use App\Models\WmsJxTransmissionLog;
use App\Services\AutoOrder\IncomingReceiveService;
use App\Services\JX\Eos\JxEosImportService;
use App\Services\JX\Eos\JxEosIncomingWorkflowService;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class JxEosIncomingWorkflowServiceTest extends TestCase
{
    private ?string $messageId = null;

    protected function tearDown(): void
    {
        if ($this->messageId) {
            $fileIds = WmsIncomingReceivedFile::query()
                ->where('received_message_id', $this->messageId)
                ->pluck('id');

            if ($fileIds->isNotEmpty()) {
                WmsIncomingReceivedFile::query()->whereIn('id', $fileIds->all())->delete();
            }

            WmsJxEosImportBatch::query()
                ->where('source_message_id', $this->messageId)
                ->delete();

            WmsJxTransmissionLog::query()
                ->where('message_id', $this->messageId)
                ->delete();
        }

        Mockery::close();

        parent::tearDown();
    }

    public function test_import_and_apply_creates_received_file_then_matches_and_applies(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('jx/incoming.dat', 'EOS-CONTENT');

        $log = $this->createReceiveLog('local:jx/incoming.dat');
        $batch = $this->makeBatch($log);
        $file = null;

        $eosImport = Mockery::mock(JxEosImportService::class);
        $eosImport->shouldReceive('importFromContent')
            ->once()
            ->withArgs(fn ($targetLog, string $content, string $disk, string $sourcePath): bool => $targetLog->is($log)
                && $content === 'EOS-CONTENT'
                && $disk === 'local'
                && $sourcePath === 'local:jx/incoming.dat')
            ->andReturn($batch);

        $incomingReceive = Mockery::mock(IncomingReceiveService::class);
        $incomingReceive->shouldReceive('parseJxData')
            ->once()
            ->andReturnUsing(function (string $content, string $filename, ?int $contractorId, array $metadata) use (&$file) {
                $this->assertSame('EOS-CONTENT', $content);
                $this->assertSame('incoming.dat', $filename);
                $this->assertNull($contractorId);
                $this->assertSame($this->messageId, $metadata['received_message_id']);
                $this->assertSame('local:jx/incoming.dat', $metadata['raw_file_path']);

                $file = WmsIncomingReceivedFile::create([
                    'filename' => 'test-jx-eos-workflow-'.$this->messageId.'.dat',
                    'format_type' => 'JX',
                    'status' => 'PENDING',
                    'received_message_id' => $this->messageId,
                    'raw_sha256' => $metadata['raw_sha256'],
                ]);

                return $file;
            });

        $incomingReceive->shouldReceive('matchWithSchedules')
            ->once()
            ->andReturnUsing(function (WmsIncomingReceivedFile $targetFile) use (&$file): array {
                $this->assertTrue($targetFile->is($file));
                $targetFile->update(['status' => 'MATCHED']);

                return [
                    'matched' => 1,
                    'unmatched' => 0,
                    'shortage' => 0,
                    'total' => 1,
                ];
            });

        $incomingReceive->shouldReceive('applyMatched')
            ->once()
            ->andReturnUsing(function (WmsIncomingReceivedFile $targetFile) use (&$file): array {
                $this->assertTrue($targetFile->is($file));
                $targetFile->update(['status' => 'APPLIED']);

                return [
                    'applied' => 1,
                    'schedule_ids' => [123],
                    'errors' => [],
                ];
            });

        $result = (new JxEosIncomingWorkflowService($eosImport, $incomingReceive))
            ->importAndApply($log);

        $this->assertTrue($result['eos_imported']);
        $this->assertTrue($result['incoming_imported']);
        $this->assertSame('APPLIED', $result['received_file']->status);
        $this->assertSame(1, $result['match']['matched']);
        $this->assertSame(1, $result['apply']['applied']);
        $this->assertNull($result['skipped_apply_reason']);
    }

    public function test_import_and_apply_reuses_applied_file_without_reapplying(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('jx/incoming.dat', 'EOS-CONTENT');

        $log = $this->createReceiveLog('local:jx/incoming.dat');
        $batch = $this->makeBatch($log);
        $file = WmsIncomingReceivedFile::create([
            'filename' => 'test-jx-eos-workflow-'.$this->messageId.'.dat',
            'format_type' => 'JX',
            'status' => 'APPLIED',
            'received_message_id' => $this->messageId,
            'raw_sha256' => hash('sha256', 'EOS-CONTENT'),
        ]);

        $eosImport = Mockery::mock(JxEosImportService::class);
        $eosImport->shouldReceive('importFromContent')
            ->once()
            ->andReturn($batch);

        $incomingReceive = Mockery::mock(IncomingReceiveService::class);
        $incomingReceive->shouldReceive('parseJxData')->never();
        $incomingReceive->shouldReceive('matchWithSchedules')->never();
        $incomingReceive->shouldReceive('applyMatched')->never();

        $result = (new JxEosIncomingWorkflowService($eosImport, $incomingReceive))
            ->importAndApply($log);

        $this->assertFalse($result['incoming_imported']);
        $this->assertTrue($result['received_file']->is($file));
        $this->assertSame('APPLIED', $result['received_file']->status);
        $this->assertSame('既に入荷予定へ適用済みです。', $result['skipped_apply_reason']);
        $this->assertSame(0, $result['apply']['applied']);
    }

    public function test_import_and_apply_reuses_skipped_file_without_matching_or_applying(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('jx/incoming.dat', 'EOS-CONTENT');

        $log = $this->createReceiveLog('local:jx/incoming.dat');
        $batch = $this->makeBatch($log);
        $file = WmsIncomingReceivedFile::create([
            'filename' => 'test-jx-eos-workflow-'.$this->messageId.'.dat',
            'format_type' => 'JX',
            'status' => WmsIncomingReceivedFile::STATUS_SKIPPED,
            'received_message_id' => $this->messageId,
            'raw_sha256' => hash('sha256', 'EOS-CONTENT'),
        ]);

        $eosImport = Mockery::mock(JxEosImportService::class);
        $eosImport->shouldReceive('importFromContent')
            ->once()
            ->andReturn($batch);

        $incomingReceive = Mockery::mock(IncomingReceiveService::class);
        $incomingReceive->shouldReceive('parseJxData')->never();
        $incomingReceive->shouldReceive('matchWithSchedules')->never();
        $incomingReceive->shouldReceive('applyMatched')->never();

        $result = (new JxEosIncomingWorkflowService($eosImport, $incomingReceive))
            ->importAndApply($log);

        $this->assertFalse($result['incoming_imported']);
        $this->assertTrue($result['received_file']->is($file));
        $this->assertSame(WmsIncomingReceivedFile::STATUS_SKIPPED, $result['received_file']->status);
        $this->assertSame('リリース前EOS受信データとして処理対象外です。', $result['skipped_apply_reason']);
        $this->assertSame(0, $result['apply']['applied']);
    }

    private function createReceiveLog(string $filePath): WmsJxTransmissionLog
    {
        $this->messageId = 'test-jx-eos-workflow-'.str_replace('.', '', (string) microtime(true));

        return WmsJxTransmissionLog::create([
            'jx_setting_id' => null,
            'direction' => WmsJxTransmissionLog::DIRECTION_RECEIVE,
            'environment' => WmsJxTransmissionLog::ENV_TEST,
            'operation_type' => WmsJxTransmissionLog::OPERATION_GET,
            'message_id' => $this->messageId,
            'document_type' => 'EOS',
            'format_type' => 'JX',
            'status' => WmsJxTransmissionLog::STATUS_SUCCESS,
            'data_size' => 11,
            'file_path' => $filePath,
            'http_code' => 200,
            'transmitted_at' => now(),
        ]);
    }

    private function makeBatch(WmsJxTransmissionLog $log): WmsJxEosImportBatch
    {
        $batch = new WmsJxEosImportBatch([
            'wms_jx_transmission_log_id' => $log->id,
            'source_message_id' => $this->messageId,
            'status' => WmsJxEosImportBatch::STATUS_SUCCEEDED,
            'is_current' => true,
            'import_version' => 1,
            'finet_code' => '123456',
            'slip_count' => 1,
            'line_count' => 1,
        ]);

        $batch->id = 999001;
        $batch->exists = true;

        return $batch;
    }
}
