<?php

namespace Tests\Unit\Console\AutoOrder;

use App\Models\WmsIncomingReceivedFile;
use App\Models\WmsJxTransmissionLog;
use Tests\TestCase;

class SkipPendingEosIncomingLogsCommandTest extends TestCase
{
    private const MESSAGE_PREFIX = 'test-eos-skip-pending-';

    private const ENVIRONMENT = 'command-test';

    protected function tearDown(): void
    {
        WmsIncomingReceivedFile::query()
            ->where('received_message_id', 'like', self::MESSAGE_PREFIX.'%')
            ->delete();

        WmsJxTransmissionLog::query()
            ->where('message_id', 'like', self::MESSAGE_PREFIX.'%')
            ->delete();

        parent::tearDown();
    }

    public function test_dry_run_does_not_create_skipped_file(): void
    {
        $log = $this->createReceiveLog();

        $this->artisan('wms:eos-incoming-skip-pending', [
            '--before' => now()->format('Y-m-d H:i:s'),
            '--environment' => self::ENVIRONMENT,
        ])->assertExitCode(0);

        $this->assertFalse(
            WmsIncomingReceivedFile::query()
                ->where('received_message_id', $log->message_id)
                ->exists()
        );
    }

    public function test_apply_marks_pending_eos_log_as_skipped(): void
    {
        $log = $this->createReceiveLog();

        $this->artisan('wms:eos-incoming-skip-pending', [
            '--before' => now()->format('Y-m-d H:i:s'),
            '--environment' => self::ENVIRONMENT,
            '--apply' => true,
        ])->assertExitCode(0);

        $file = WmsIncomingReceivedFile::query()
            ->where('received_message_id', $log->message_id)
            ->firstOrFail();

        $this->assertSame(WmsIncomingReceivedFile::STATUS_SKIPPED, $file->status);
        $this->assertSame('リリース前EOS受信データとして処理対象外にしました。', $file->error_message);
        $this->assertSame(0, $file->parsed_slip_count);
        $this->assertSame(0, $file->parsed_detail_count);
    }

    private function createReceiveLog(): WmsJxTransmissionLog
    {
        $messageId = self::MESSAGE_PREFIX.str_replace('.', '', (string) microtime(true));

        return WmsJxTransmissionLog::query()->create([
            'jx_setting_id' => null,
            'direction' => WmsJxTransmissionLog::DIRECTION_RECEIVE,
            'environment' => self::ENVIRONMENT,
            'operation_type' => WmsJxTransmissionLog::OPERATION_GET,
            'message_id' => $messageId,
            'document_type' => '90',
            'format_type' => 'JX',
            'status' => WmsJxTransmissionLog::STATUS_SUCCESS,
            'data_size' => 1536,
            'file_path' => "s3:jx-received/test/{$messageId}.dat",
            'http_code' => 200,
            'transmitted_at' => now()->subMinute(),
        ]);
    }
}
