<?php

namespace App\Services\JX\Eos;

use App\Models\WmsIncomingReceivedFile;
use App\Models\WmsJxTransmissionLog;
use Illuminate\Support\Facades\DB;

class JxEosIncomingSkipService
{
    private const FORMAT_TYPE = 'JX';

    public function skip(WmsJxTransmissionLog $log, ?int $userId = null, ?string $reason = null): WmsIncomingReceivedFile
    {
        $this->assertSkippableLog($log);

        return DB::connection('sakemaru')->transaction(function () use ($log, $userId, $reason): WmsIncomingReceivedFile {
            $lockedLog = WmsJxTransmissionLog::query()
                ->whereKey($log->id)
                ->lockForUpdate()
                ->with('jxSetting')
                ->firstOrFail();

            $existing = $this->findExistingIncomingFile($lockedLog, lock: true);
            if ($existing) {
                if ($existing->status === WmsIncomingReceivedFile::STATUS_APPLIED) {
                    throw new \RuntimeException('既に入荷予定へ適用済みのため対象外にできません。');
                }

                if ($existing->status === WmsIncomingReceivedFile::STATUS_SKIPPED) {
                    return $existing;
                }

                $existing->update(WmsIncomingReceivedFile::onlyExistingColumns([
                    'status' => WmsIncomingReceivedFile::STATUS_SKIPPED,
                    'received_by' => $userId,
                    'error_message' => $this->skipReason($reason),
                ]));

                return $existing->refresh();
            }

            return WmsIncomingReceivedFile::create(WmsIncomingReceivedFile::onlyExistingColumns([
                'contractor_id' => $this->logContractorId($lockedLog),
                'filename' => $this->filenameFromLogPath((string) $lockedLog->file_path, (int) $lockedLog->id),
                'raw_file_path' => $lockedLog->file_path,
                'raw_file_size' => $lockedLog->data_size,
                'received_message_id' => filled($lockedLog->message_id) ? $lockedLog->message_id : null,
                'confirm_status' => 'SENT',
                'confirmed_at' => $lockedLog->transmitted_at ?? $lockedLog->created_at,
                'format_type' => self::FORMAT_TYPE,
                'status' => WmsIncomingReceivedFile::STATUS_SKIPPED,
                'parsed_slip_count' => 0,
                'parsed_detail_count' => 0,
                'received_by' => $userId,
                'error_message' => $this->skipReason($reason),
            ]));
        });
    }

    private function assertSkippableLog(WmsJxTransmissionLog $log): void
    {
        if ($log->direction !== WmsJxTransmissionLog::DIRECTION_RECEIVE) {
            throw new \RuntimeException('対象外にできるのはJX受信ログのみです。');
        }

        if ($log->operation_type !== WmsJxTransmissionLog::OPERATION_GET) {
            throw new \RuntimeException('対象外にできるのはGetDocumentログのみです。');
        }

        if ($log->status !== WmsJxTransmissionLog::STATUS_SUCCESS) {
            throw new \RuntimeException('対象外にできるのは成功ログのみです。');
        }

        if (blank($log->file_path)) {
            throw new \RuntimeException('保存ファイルパスがないため対象外にできません。');
        }
    }

    private function findExistingIncomingFile(WmsJxTransmissionLog $log, bool $lock = false): ?WmsIncomingReceivedFile
    {
        $query = WmsIncomingReceivedFile::query()
            ->where('format_type', self::FORMAT_TYPE)
            ->orderByDesc('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        if (filled($log->message_id)) {
            return $query
                ->where('received_message_id', $log->message_id)
                ->first();
        }

        return $query
            ->where('raw_file_path', $log->file_path)
            ->first();
    }

    private function logContractorId(WmsJxTransmissionLog $log): ?int
    {
        $contractorId = (int) ($log->jxSetting?->contractor_id ?? 0);

        return $contractorId > 0 ? $contractorId : null;
    }

    private function filenameFromLogPath(string $filePath, int $logId): string
    {
        if (str_contains($filePath, ':')) {
            [, $path] = explode(':', $filePath, 2);
            $filePath = ltrim($path, '/');
        }

        return basename($filePath) ?: "skipped_eos_receive_log_{$logId}.dat";
    }

    private function skipReason(?string $reason = null): string
    {
        if (filled($reason)) {
            return $reason;
        }

        return 'ユーザ操作でEOS受信データを取込対象外にしました。';
    }
}
