<?php

namespace Tests\Unit\Services\JX;

use App\Models\WmsIncomingReceivedFile;
use App\Models\WmsJxTransmissionLog;
use App\Services\JX\Eos\JxEosIncomingSkipService;
use Tests\TestCase;

class JxEosIncomingSkipServiceTest extends TestCase
{
    private string $messagePrefix = 'test-jx-eos-skip-';

    protected function tearDown(): void
    {
        WmsIncomingReceivedFile::query()
            ->where('received_message_id', 'like', $this->messagePrefix.'%')
            ->delete();

        WmsJxTransmissionLog::query()
            ->where('message_id', 'like', $this->messagePrefix.'%')
            ->delete();

        parent::tearDown();
    }

    public function test_skip_creates_skipped_received_file_without_duplicates(): void
    {
        $log = $this->createReceiveLog();
        $service = app(JxEosIncomingSkipService::class);

        $file = $service->skip($log);
        $secondFile = $service->skip($log->fresh());

        $this->assertTrue($file->is($secondFile));
        $this->assertSame(WmsIncomingReceivedFile::STATUS_SKIPPED, $file->status);
        $this->assertSame($log->message_id, $file->received_message_id);
        $this->assertSame(0, $file->parsed_slip_count);
        $this->assertSame(0, $file->parsed_detail_count);
        $this->assertSame(
            1,
            WmsIncomingReceivedFile::query()
                ->where('received_message_id', $log->message_id)
                ->where('format_type', 'JX')
                ->count()
        );
    }

    public function test_skip_updates_existing_pending_file(): void
    {
        $log = $this->createReceiveLog();
        $file = WmsIncomingReceivedFile::create([
            'filename' => 'test-jx-eos-skip-existing.dat',
            'format_type' => 'JX',
            'status' => WmsIncomingReceivedFile::STATUS_PENDING,
            'received_message_id' => $log->message_id,
            'parsed_slip_count' => 1,
            'parsed_detail_count' => 1,
        ]);

        $skippedFile = app(JxEosIncomingSkipService::class)->skip($log);

        $this->assertTrue($skippedFile->is($file));
        $this->assertSame(WmsIncomingReceivedFile::STATUS_SKIPPED, $skippedFile->status);
        $this->assertSame(1, $skippedFile->parsed_slip_count);
        $this->assertSame(1, $skippedFile->parsed_detail_count);
    }

    public function test_skip_rejects_applied_file(): void
    {
        $log = $this->createReceiveLog();
        WmsIncomingReceivedFile::create([
            'filename' => 'test-jx-eos-skip-applied.dat',
            'format_type' => 'JX',
            'status' => WmsIncomingReceivedFile::STATUS_APPLIED,
            'received_message_id' => $log->message_id,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('既に入荷予定へ適用済みのため対象外にできません。');

        app(JxEosIncomingSkipService::class)->skip($log);
    }

    private function createReceiveLog(): WmsJxTransmissionLog
    {
        $messageId = $this->messagePrefix.str_replace('.', '', (string) microtime(true));

        return WmsJxTransmissionLog::create([
            'jx_setting_id' => null,
            'direction' => WmsJxTransmissionLog::DIRECTION_RECEIVE,
            'environment' => WmsJxTransmissionLog::ENV_TEST,
            'operation_type' => WmsJxTransmissionLog::OPERATION_GET,
            'message_id' => $messageId,
            'document_type' => 'EOS',
            'format_type' => 'JX',
            'status' => WmsJxTransmissionLog::STATUS_SUCCESS,
            'data_size' => 9600,
            'file_path' => 's3:jx-client/received-data/test/skipped-eos.dat',
            'http_code' => 200,
            'transmitted_at' => now(),
        ]);
    }
}
