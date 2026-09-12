<?php

namespace App\Services\JX\Eos;

use App\Models\WmsJxEosDocument;
use App\Models\WmsJxEosImportBatch;
use App\Models\WmsJxEosLine;
use App\Models\WmsJxEosSlip;
use App\Models\WmsJxTransmissionLog;
use App\Models\WmsOrderJxSetting;
use App\Services\AutoOrder\IncomingReceivedQuantityNormalizer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class JxEosImportService
{
    private const RECORD_LENGTH = 128;

    private const ENCODING = 'SJIS-win';

    private const IMPORTER_VERSION = '2026-06-08';

    public function importFromLog(WmsJxTransmissionLog $log): WmsJxEosImportBatch
    {
        $this->assertImportableLog($log);

        [$disk, $path] = $this->resolveSource($log->file_path);
        $content = $this->readSourceContent($disk, $path);

        return $this->importFromContent($log, $content, $disk, $log->file_path);
    }

    public function importFromContent(
        WmsJxTransmissionLog $log,
        string $content,
        string $sourceDisk = 'manual',
        ?string $sourceFilePath = null
    ): WmsJxEosImportBatch {
        $this->assertImportableLog($log);

        $normalizedContent = $this->normalizeContent($content);
        $fileHash = hash('sha256', $normalizedContent);
        $records = $this->splitRecords($normalizedContent);

        $batch = $this->createBatch($log, $sourceDisk, $sourceFilePath ?? $log->file_path, $fileHash, strlen($normalizedContent), count($records));

        try {
            DB::connection('sakemaru')->transaction(function () use ($batch, $log, $records): void {
                $stats = $this->persistRecords($batch, $log, $records);
                $detectedSetting = $this->detectJxSetting($stats['finet_code'] ?? null);
                $detectedContractor = $detectedSetting?->contractor;

                WmsJxEosImportBatch::where('wms_jx_transmission_log_id', $log->id)
                    ->where('id', '!=', $batch->id)
                    ->where('is_current', true)
                    ->update([
                        'is_current' => false,
                        'status' => WmsJxEosImportBatch::STATUS_SUPERSEDED,
                        'superseded_at' => now(),
                        'updated_at' => now(),
                    ]);

                $batch->update([
                    'status' => WmsJxEosImportBatch::STATUS_SUCCEEDED,
                    'is_current' => true,
                    'finet_code' => $stats['finet_code'] ?? null,
                    'detected_jx_setting_id' => $detectedSetting?->id,
                    'detected_contractor_id' => $detectedContractor?->id,
                    'detected_contractor_code' => $detectedContractor?->code,
                    'wrapper_record_count' => $stats['wrapper_record_count'] ?? null,
                    'document_count' => $stats['document_count'],
                    'slip_count' => $stats['slip_count'],
                    'line_count' => $stats['line_count'],
                    'stats_json' => $stats,
                    'imported_at' => now(),
                ]);
            });
        } catch (\Throwable $throwable) {
            $batch->update([
                'status' => WmsJxEosImportBatch::STATUS_FAILED,
                'error_message' => $throwable->getMessage(),
                'imported_at' => now(),
            ]);

            throw $throwable;
        }

        return $batch->fresh(['documents', 'slips', 'lines', 'detectedJxSetting', 'detectedContractor']);
    }

    private function assertImportableLog(WmsJxTransmissionLog $log): void
    {
        if ($log->direction !== WmsJxTransmissionLog::DIRECTION_RECEIVE) {
            throw new \RuntimeException('EOS取込は受信ログのみ対象です。');
        }

        if ($log->operation_type !== WmsJxTransmissionLog::OPERATION_GET) {
            throw new \RuntimeException('EOS取込はGetDocumentログのみ対象です。');
        }

        if ($log->status !== WmsJxTransmissionLog::STATUS_SUCCESS) {
            throw new \RuntimeException('EOS取込は成功ログのみ対象です。');
        }

        if (blank($log->file_path)) {
            throw new \RuntimeException('保存ファイルパスがないためEOS取込できません。');
        }
    }

    private function resolveSource(?string $filePath): array
    {
        $filePath = (string) $filePath;

        if (str_starts_with($filePath, 's3:')) {
            return ['s3', substr($filePath, 3)];
        }

        if (str_starts_with($filePath, 'local:')) {
            return ['local', substr($filePath, 6)];
        }

        if (is_file($filePath)) {
            return ['absolute', $filePath];
        }

        return ['s3', $filePath];
    }

    private function readSourceContent(string $disk, string $path): string
    {
        if ($disk === 'absolute') {
            $content = file_get_contents($path);

            if ($content === false) {
                throw new \RuntimeException("EOS原本ファイルを読み込めません: {$path}");
            }

            return $content;
        }

        if (! Storage::disk($disk)->exists($path)) {
            throw new \RuntimeException("EOS原本ファイルが存在しません: {$disk}:{$path}");
        }

        $content = Storage::disk($disk)->get($path);
        if ($content === null || $content === false) {
            throw new \RuntimeException("EOS原本ファイルを読み込めません: {$disk}:{$path}");
        }

        return $content;
    }

    private function normalizeContent(string $content): string
    {
        $content = str_replace(["\r\n", "\r", "\n"], '', $content);

        if ($content === '') {
            throw new \RuntimeException('EOS原本ファイルが空です。');
        }

        return $content;
    }

    private function splitRecords(string $content): array
    {
        $length = strlen($content);
        if ($length % self::RECORD_LENGTH !== 0) {
            throw new \RuntimeException("EOS原本ファイルが128バイト固定長ではありません。サイズ={$length}");
        }

        return str_split($content, self::RECORD_LENGTH);
    }

    private function createBatch(
        WmsJxTransmissionLog $log,
        string $sourceDisk,
        ?string $sourceFilePath,
        string $fileHash,
        int $fileSize,
        int $recordCount
    ): WmsJxEosImportBatch {
        $version = ((int) WmsJxEosImportBatch::where('wms_jx_transmission_log_id', $log->id)->max('import_version')) + 1;
        $user = auth()->user();

        return WmsJxEosImportBatch::create([
            'wms_jx_transmission_log_id' => $log->id,
            'jx_setting_id' => $log->jx_setting_id,
            'import_version' => $version,
            'importer_version' => self::IMPORTER_VERSION,
            'status' => WmsJxEosImportBatch::STATUS_IMPORTING,
            'is_current' => false,
            'source_disk' => $sourceDisk,
            'source_file_path' => $sourceFilePath,
            'source_message_id' => $log->message_id,
            'source_document_type' => $log->document_type,
            'file_sha256' => $fileHash,
            'file_size' => $fileSize,
            'record_count' => $recordCount,
            'imported_by' => $user?->id,
            'imported_by_name' => $user?->name,
        ]);
    }

    private function persistRecords(WmsJxEosImportBatch $batch, WmsJxTransmissionLog $log, array $records): array
    {
        $stats = [
            'finet_code' => null,
            'wrapper' => null,
            'wrapper_record_count' => null,
            'record_count' => count($records),
            'document_count' => 0,
            'slip_count' => 0,
            'line_count' => 0,
            'footer_count' => 0,
            'unknown_record_count' => 0,
        ];

        $currentDocument = null;
        $currentSlip = null;
        $documentSequence = 0;
        $slipSequence = 0;
        $lineSequence = 0;

        foreach ($records as $index => $record) {
            $sourceRecordNo = $index + 1;
            $type = $record[0] ?? '';

            if ($type === '1') {
                $wrapper = $this->parseWrapperRecord($record);
                $stats['wrapper'] = $wrapper;
                $stats['finet_code'] = $wrapper['finet_code'] ?: $stats['finet_code'];
                $stats['wrapper_record_count'] = $wrapper['wrapper_record_count'];

                continue;
            }

            if ($type === '8' || $type === '9') {
                $stats['footer_count']++;

                continue;
            }

            if ($type === 'A') {
                $documentSequence++;
                $currentDocument = $this->createDocument($batch, $log, $record, $sourceRecordNo, $documentSequence);
                $currentSlip = null;
                $stats['document_count']++;

                continue;
            }

            if ($type === 'B') {
                $slipSequence++;
                $currentSlip = $this->createSlip($batch, $log, $currentDocument, $record, $sourceRecordNo, $slipSequence);
                $stats['slip_count']++;

                continue;
            }

            if ($type === 'D') {
                $lineSequence++;
                $this->createLine($batch, $log, $currentDocument, $currentSlip, $record, $sourceRecordNo, $lineSequence);
                $stats['line_count']++;
                if ($currentSlip) {
                    $currentSlip->increment('detail_count');
                }

                continue;
            }

            $stats['unknown_record_count']++;
        }

        if (blank($stats['finet_code']) && $log->jxSetting) {
            $stats['finet_code'] = $log->jxSetting->receiver_station_code;
        }

        return $stats;
    }

    private function parseWrapperRecord(string $record): array
    {
        return [
            'wrapper_serial_number' => $this->trimField($record, 1, 7),
            'wrapper_document_type' => $this->trimField($record, 8, 2),
            'wrapper_created_date' => $this->trimField($record, 10, 6),
            'wrapper_created_time' => $this->trimField($record, 16, 6),
            'wrapper_file_number' => $this->trimField($record, 22, 2),
            'wrapper_process_date' => $this->trimField($record, 24, 6),
            'wrapper_receiver_trading_code' => $this->trimField($record, 30, 12),
            'finet_code' => $this->trimField($record, 42, 6),
            'wrapper_final_receiver_code' => $this->trimField($record, 50, 6),
            'wrapper_direct_receiver_company_code' => $this->trimField($record, 58, 6),
            'wrapper_sender_trading_code' => $this->trimField($record, 66, 12),
            'wrapper_sender_office_code' => $this->trimField($record, 78, 12),
            'wrapper_sender_name' => $this->convertToUtf8($this->trimField($record, 90, 15)),
            'wrapper_sender_office_name' => $this->convertToUtf8($this->trimField($record, 105, 10)),
            'wrapper_record_count' => $this->toInt($this->trimField($record, 115, 6)),
            'wrapper_record_size' => $this->toInt($this->trimField($record, 121, 3)),
        ];
    }

    private function createDocument(
        WmsJxEosImportBatch $batch,
        WmsJxTransmissionLog $log,
        string $record,
        int $sourceRecordNo,
        int $documentSequence
    ): WmsJxEosDocument {
        return WmsJxEosDocument::create([
            'import_batch_id' => $batch->id,
            'wms_jx_transmission_log_id' => $log->id,
            'source_record_no' => $sourceRecordNo,
            'document_sequence' => $documentSequence,
            'data_type' => $this->trimField($record, 1, 2),
            'processing_date' => $this->dateFromYmd($this->trimField($record, 3, 8)),
            'processing_time' => $this->trimField($record, 11, 6),
            'sender_code' => $this->trimField($record, 17, 8),
            'receiver_code' => $this->trimField($record, 25, 8),
            'declared_record_count' => $this->toInt($this->trimField($record, 33, 6)),
            'declared_slip_count' => $this->toInt($this->trimField($record, 39, 6)),
            'company_name' => $this->convertToUtf8($this->trimField($record, 45, 15)),
            'raw_record_hash' => hash('sha256', $record),
            'raw_record_base64' => base64_encode($record),
        ]);
    }

    private function createSlip(
        WmsJxEosImportBatch $batch,
        WmsJxTransmissionLog $log,
        ?WmsJxEosDocument $document,
        string $record,
        int $sourceRecordNo,
        int $slipSequence
    ): WmsJxEosSlip {
        $dataType = $this->trimField($record, 1, 2);
        if ($dataType !== '02') {
            throw new \RuntimeException("Bレコードのデータ種別が02ではありません。レコード番号={$sourceRecordNo}, 種別={$dataType}");
        }

        $slipNumber = str_replace('-', '', $this->trimField($record, 3, 11));
        $orderType = $this->trimField($record, 104, 1);
        $shopCode = $this->trimField($record, 14, 4);
        $slipType = $this->trimField($record, 21, 2);

        return WmsJxEosSlip::create([
            'import_batch_id' => $batch->id,
            'document_id' => $document?->id,
            'wms_jx_transmission_log_id' => $log->id,
            'source_record_no' => $sourceRecordNo,
            'slip_sequence' => $slipSequence,
            'slip_number' => $slipNumber,
            'data_type' => $dataType,
            'shop_code' => $shopCode,
            'warehouse_code' => $shopCode,
            'warehouse_name' => $this->warehouseName($shopCode),
            'category_code' => $this->trimField($record, 18, 3),
            'slip_type' => $slipType,
            'slip_type_label' => $this->slipTypeLabel($slipType),
            'is_return_slip' => $slipType === '05',
            'is_shipment_slip' => $slipType === '02',
            'order_date' => $this->dateFromYymmdd($this->trimField($record, 23, 6)),
            'delivery_date' => $this->dateFromYymmdd($this->trimField($record, 29, 6)),
            'delivery_route' => $this->trimField($record, 35, 3),
            'contractor_code' => $this->trimField($record, 38, 4),
            'shop_name' => $this->convertToUtf8($this->trimField($record, 42, 15)),
            'delivery_place' => $this->convertToUtf8($this->trimField($record, 57, 10)),
            'note' => $this->convertToUtf8($this->trimField($record, 67, 25)),
            'maker_direct_delivery_type' => $this->trimField($record, 92, 1),
            'order_number' => $this->trimField($record, 93, 11),
            'order_type' => $orderType,
            'order_type_label' => $this->orderTypeLabel($orderType),
            'direct_type' => $this->trimField($record, 92, 1),
            'detail_count' => 0,
            'raw_record_hash' => hash('sha256', $record),
            'raw_record_base64' => base64_encode($record),
        ]);
    }

    private function createLine(
        WmsJxEosImportBatch $batch,
        WmsJxTransmissionLog $log,
        ?WmsJxEosDocument $document,
        ?WmsJxEosSlip $slip,
        string $record,
        int $sourceRecordNo,
        int $lineSequence
    ): WmsJxEosLine {
        $packQuantity = $this->toInt($this->trimField($record, 88, 6));
        $caseQuantity = $this->toInt($this->trimField($record, 94, 7));
        $pieceQuantity = $this->toInt($this->trimField($record, 101, 7));
        $janCode = $this->trimField($record, 69, 13);
        $unitPriceRaw = $this->toInt($this->trimField($record, 108, 10));
        $unitPrice = round($unitPriceRaw / 100, 2);
        $totalQuantity = app(IncomingReceivedQuantityNormalizer::class)
            ->normalize($caseQuantity, $pieceQuantity, $packQuantity, $janCode);

        return WmsJxEosLine::create([
            'import_batch_id' => $batch->id,
            'document_id' => $document?->id,
            'slip_id' => $slip?->id,
            'wms_jx_transmission_log_id' => $log->id,
            'source_record_no' => $sourceRecordNo,
            'line_sequence' => $lineSequence,
            'line_number' => $this->toInt($this->trimField($record, 3, 2)),
            'data_type' => $this->trimField($record, 1, 2),
            'product_name' => $this->convertToUtf8($this->trimField($record, 5, 64)),
            'jan_code' => $janCode,
            'item_code' => $this->trimField($record, 82, 6),
            'pack_quantity' => $packQuantity,
            'case_quantity' => $caseQuantity,
            'piece_quantity' => $pieceQuantity,
            'total_quantity' => $totalQuantity,
            'unit_price_raw' => $unitPriceRaw,
            'unit_price' => $unitPrice,
            'amount' => round($this->pricedQuantity($caseQuantity, $pieceQuantity) * $unitPrice, 2),
            'is_shortage' => $caseQuantity === 0 && $pieceQuantity === 0,
            'line_hash' => hash('sha256', $log->id.'|'.$sourceRecordNo.'|'.$record),
            'raw_record_hash' => hash('sha256', $record),
            'raw_record_base64' => base64_encode($record),
        ]);
    }

    private function pricedQuantity(int $caseQuantity, int $pieceQuantity): int
    {
        return $caseQuantity > 0 ? $caseQuantity : $pieceQuantity;
    }

    private function detectJxSetting(?string $finetCode): ?WmsOrderJxSetting
    {
        if (blank($finetCode)) {
            return null;
        }

        return WmsOrderJxSetting::with('contractor')
            ->where('receiver_station_code', $finetCode)
            ->where('is_active', true)
            ->first();
    }

    private function trimField(string $record, int $offset, int $length): string
    {
        return trim(substr($record, $offset, $length));
    }

    private function convertToUtf8(string $sjisString): string
    {
        if ($sjisString === '') {
            return '';
        }

        $utf8 = mb_convert_encoding($sjisString, 'UTF-8', self::ENCODING);

        return $utf8 !== false ? $utf8 : $sjisString;
    }

    private function toInt(string $value): int
    {
        $value = trim($value);

        return $value === '' ? 0 : (int) $value;
    }

    private function orderTypeLabel(?string $value): ?string
    {
        return match ($value) {
            '0' => 'EDI出荷',
            '1' => 'FAX注文出荷',
            '2' => '電話注文出荷',
            default => null,
        };
    }

    private function slipTypeLabel(?string $value): ?string
    {
        return match ($value) {
            '02' => '出荷',
            '05' => '返品',
            default => null,
        };
    }

    private function warehouseName(?string $shopCode): ?string
    {
        return match ($shopCode) {
            '0001' => '本店',
            '0002' => '二の宮店',
            default => null,
        };
    }

    private function dateFromYmd(string $value): ?string
    {
        $value = trim($value);
        if (! preg_match('/^\d{8}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Ymd', $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function dateFromYymmdd(string $value): ?string
    {
        $value = trim($value);
        if (! preg_match('/^\d{6}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('ymd', $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
