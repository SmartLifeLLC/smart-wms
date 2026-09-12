<?php

namespace App\Services\AutoOrder;

use App\Models\WmsIncomingPriceCheckSource;
use App\Models\WmsIncomingReceivedDetail;
use App\Models\WmsIncomingReceivedFile;
use App\Models\WmsIncomingReceivedSlip;
use App\Models\WmsJxEosImportBatch;
use App\Models\WmsJxEosLine;
use App\Models\WmsJxTransmissionLog;
use App\Models\WmsOrderCandidate;
use App\Models\WmsOrderIncomingSchedule;
use App\Models\WmsOrderJxDocument;
use App\Models\WmsOrderSlipNumberAssignment;
use BackedEnum;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class IncomingPriceCheckSourceRecorder
{
    private const SOURCE_TYPE = 'JX_EOS_RECEIVED';

    private const SOURCE_SCHEMA_VERSION = '2026-08-30';

    private const SHIPPING_JAN_CODE = '9999999999996';

    private const PRICE_DIFF_THRESHOLD = 1.0;

    /** @var array<int, WmsJxTransmissionLog|null> */
    private array $jxLogCache = [];

    /** @var array<int, WmsJxEosImportBatch|null> */
    private array $eosBatchCache = [];

    /** @var array<string, WmsJxEosLine|null> */
    private array $eosLineCache = [];

    /** @var array<string, WmsOrderSlipNumberAssignment|null> */
    private array $assignmentCache = [];

    /** @var array<int, array<string, array>> */
    private array $sentDocumentLineCache = [];

    public function record(
        WmsIncomingReceivedFile $file,
        WmsIncomingReceivedDetail $detail,
        WmsOrderIncomingSchedule $schedule
    ): WmsIncomingPriceCheckSource {
        $detail->loadMissing(['slip']);
        $schedule->loadMissing(['warehouse', 'contractor', 'supplier', 'item', 'orderCandidate']);

        $slip = $detail->slip;
        $jxLog = $this->resolveJxTransmissionLog($file);
        $eosBatch = $this->resolveEosBatch($jxLog);
        $eosLine = $this->resolveEosLine($eosBatch, $slip, $detail);
        $assignment = $this->resolveSlipAssignment($slip);
        $orderCandidate = $this->resolveOrderCandidate($schedule);
        $sentLinePayload = $this->resolveSentLinePayload($assignment, $orderCandidate, $slip, $detail);

        $sourceKeys = $this->buildSourceKeys($file, $slip, $detail);
        $receivedPrice = $this->receivedUnitPrice($detail);
        $receivedAmount = $this->receivedAmount($detail, $eosLine);
        $comparison = $this->comparisonPayload($schedule, $receivedPrice, $receivedAmount, $detail, $sentLinePayload);
        $isExcluded = $this->isPriceCheckExcluded($detail);

        $attributes = $this->encodeJsonAttributes([
            'source_type' => self::SOURCE_TYPE,
            'source_schema_version' => self::SOURCE_SCHEMA_VERSION,
            'source_key' => $sourceKeys['source_key'],
            'source_document_key' => $sourceKeys['document_key'],
            'source_line_key' => $sourceKeys['line_key'],
            'received_file_id' => $file->id,
            'received_slip_id' => $slip?->id,
            'received_detail_id' => $detail->id,
            'incoming_schedule_id' => $schedule->id,
            'order_candidate_id' => $orderCandidate?->id ?? $schedule->order_candidate_id,
            'wms_order_jx_document_id' => $assignment?->wms_order_jx_document_id ?? $orderCandidate?->wms_order_jx_document_id,
            'wms_order_slip_number_assignment_id' => $assignment?->id,
            'wms_jx_transmission_log_id' => $jxLog?->id,
            'wms_jx_eos_import_batch_id' => $eosBatch?->id,
            'wms_jx_eos_line_id' => $eosLine?->id,
            'warehouse_id' => $schedule->warehouse_id,
            'warehouse_code' => $schedule->warehouse?->code,
            'contractor_id' => $schedule->contractor_id,
            'contractor_code' => $schedule->contractor?->code ?? $slip?->b_contractor_code,
            'contractor_name' => $schedule->contractor?->name,
            'supplier_id' => $schedule->supplier_id,
            'item_id' => $schedule->item_id,
            'item_code' => $schedule->item_code ?? $schedule->item?->code,
            'item_name' => $schedule->item?->name_main ?? $detail->d_product_name,
            'search_code' => $schedule->search_code,
            'received_jan_code' => $detail->d_jan_code,
            'received_item_code' => $detail->d_item_code,
            'slip_number' => $slip?->slip_number,
            'schedule_slip_number' => $schedule->slip_number,
            'line_number' => $detail->d_line_number,
            'order_date' => $this->dateString($schedule->order_date),
            'expected_arrival_date' => $this->dateString($schedule->expected_arrival_date),
            'received_delivery_date' => $this->parseJxYymmdd($slip?->b_delivery_date),
            'recorded_at' => now(),
            'match_status' => $detail->match_status,
            'schedule_status' => $this->enumValue($schedule->status),
            'quantity_type' => $this->enumValue($schedule->quantity_type),
            'expected_quantity' => $this->decimal($schedule->expected_quantity),
            'received_total_quantity' => $this->decimal($detail->total_quantity),
            'received_pack_quantity' => $this->decimal($detail->d_pack_quantity),
            'received_case_quantity' => $this->decimal($detail->d_case_quantity),
            'received_piece_quantity' => $this->decimal($detail->d_piece_quantity),
            'shipped_quantity' => $this->decimal($schedule->shipped_quantity),
            'shortage_quantity' => $this->decimal($schedule->shortage_quantity),
            'sent_price_type' => $sentLinePayload['price_type'] ?? null,
            'sent_unit_price_raw' => $sentLinePayload['unit_price_raw'] ?? null,
            'sent_unit_price' => $sentLinePayload['unit_price'] ?? null,
            'sent_candidate_unit_price' => $this->decimal($orderCandidate?->purchase_unit_price),
            'master_unit_price' => $this->decimal($schedule->unit_price),
            'master_case_price' => $this->decimal($schedule->case_price),
            'received_unit_price_raw' => $detail->d_unit_price,
            'received_unit_price' => $receivedPrice,
            'received_amount' => $receivedAmount,
            'comparison_price_type' => $comparison['price_type'],
            'comparison_master_price' => $comparison['master_price'],
            'comparison_received_price' => $comparison['received_price'],
            'comparison_price_diff' => $comparison['price_diff'],
            'current_price_mismatch' => ! $isExcluded && (bool) $comparison['has_mismatch'],
            'is_price_check_excluded' => $isExcluded,
            'price_check_excluded_reason' => $isExcluded ? 'SHIPPING_LINE' : null,
            'received_payload' => $this->receivedPayload($file, $slip, $detail),
            'schedule_payload' => $this->schedulePayload($schedule),
            'sent_payload' => $this->sentPayload($assignment, $orderCandidate, $sentLinePayload),
            'eos_payload' => $this->eosPayload($eosBatch, $eosLine),
            'calculation_payload' => $comparison,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        WmsIncomingPriceCheckSource::query()->upsert(
            [$attributes],
            ['source_key'],
            array_values(array_diff(array_keys($attributes), ['source_key', 'created_at']))
        );

        return WmsIncomingPriceCheckSource::query()
            ->where('source_key', $sourceKeys['source_key'])
            ->firstOrFail();
    }

    public function recordForSchedule(WmsOrderIncomingSchedule $schedule): int
    {
        $details = WmsIncomingReceivedDetail::query()
            ->with(['file', 'slip'])
            ->where('matched_schedule_id', $schedule->id)
            ->whereIn('match_status', ['MATCHED', 'PARTIAL', 'SHORTAGE'])
            ->whereHas('file', fn ($query) => $query->where('format_type', 'JX'))
            ->orderBy('id')
            ->get();

        return $this->recordDetails($details, $schedule);
    }

    /**
     * @param  Collection<int, WmsIncomingReceivedDetail>  $details
     */
    private function recordDetails(Collection $details, WmsOrderIncomingSchedule $schedule): int
    {
        $count = 0;

        foreach ($details as $detail) {
            if (! $detail->file) {
                continue;
            }

            $this->record($detail->file, $detail, $schedule);
            $count++;
        }

        return $count;
    }

    private function buildSourceKeys(
        WmsIncomingReceivedFile $file,
        ?WmsIncomingReceivedSlip $slip,
        WmsIncomingReceivedDetail $detail
    ): array {
        $documentIdentity = $file->raw_sha256
            ?: $file->received_message_id
            ?: 'received_file:'.$file->id;

        $lineIdentity = implode('|', [
            'received_detail',
            $slip?->slip_number,
            $detail->d_line_number,
            $detail->d_jan_code,
            $detail->d_item_code,
            $detail->d_pack_quantity,
            $detail->d_case_quantity,
            $detail->d_piece_quantity,
            $detail->d_unit_price,
        ]);

        return [
            'document_key' => hash('sha256', self::SOURCE_TYPE.'|document|'.$documentIdentity),
            'line_key' => hash('sha256', self::SOURCE_TYPE.'|line|'.$documentIdentity.'|'.$lineIdentity),
            'source_key' => hash('sha256', self::SOURCE_TYPE.'|source|'.$documentIdentity.'|'.$lineIdentity),
        ];
    }

    private function resolveJxTransmissionLog(WmsIncomingReceivedFile $file): ?WmsJxTransmissionLog
    {
        if (array_key_exists($file->id, $this->jxLogCache)) {
            return $this->jxLogCache[$file->id];
        }

        $query = WmsJxTransmissionLog::query()
            ->where('direction', WmsJxTransmissionLog::DIRECTION_RECEIVE)
            ->where('operation_type', WmsJxTransmissionLog::OPERATION_GET)
            ->where('status', WmsJxTransmissionLog::STATUS_SUCCESS)
            ->orderByDesc('id');

        $log = null;
        if (filled($file->received_message_id)) {
            $log = (clone $query)->where('message_id', $file->received_message_id)->first();
        }

        if (! $log && filled($file->raw_file_path)) {
            $rawPath = $this->normalizeStoragePath((string) $file->raw_file_path)['path'];
            $log = (clone $query)
                ->where(function ($query) use ($rawPath) {
                    $query->where('file_path', $rawPath)
                        ->orWhere('file_path', 's3:'.$rawPath)
                        ->orWhere('file_path', 'local:'.$rawPath);
                })
                ->first();
        }

        return $this->jxLogCache[$file->id] = $log;
    }

    private function resolveEosBatch(?WmsJxTransmissionLog $log): ?WmsJxEosImportBatch
    {
        if (! $log) {
            return null;
        }

        if (array_key_exists($log->id, $this->eosBatchCache)) {
            return $this->eosBatchCache[$log->id];
        }

        return $this->eosBatchCache[$log->id] = WmsJxEosImportBatch::query()
            ->where('wms_jx_transmission_log_id', $log->id)
            ->where('is_current', true)
            ->orderByDesc('id')
            ->first();
    }

    private function resolveEosLine(
        ?WmsJxEosImportBatch $batch,
        ?WmsIncomingReceivedSlip $slip,
        WmsIncomingReceivedDetail $detail
    ): ?WmsJxEosLine {
        if (! $batch || ! $slip) {
            return null;
        }

        $cacheKey = implode(':', [$batch->id, $slip->slip_number, $detail->d_line_number, $detail->id]);
        if (array_key_exists($cacheKey, $this->eosLineCache)) {
            return $this->eosLineCache[$cacheKey];
        }

        $query = WmsJxEosLine::query()
            ->where('import_batch_id', $batch->id)
            ->where('line_number', $detail->d_line_number)
            ->whereHas('slip', fn ($query) => $query->where('slip_number', $slip->slip_number));

        if (filled($detail->d_jan_code)) {
            $query->where('jan_code', $detail->d_jan_code);
        }

        if (filled($detail->d_item_code)) {
            $query->where('item_code', $detail->d_item_code);
        }

        return $this->eosLineCache[$cacheKey] = $query->orderBy('id')->first();
    }

    private function resolveSlipAssignment(?WmsIncomingReceivedSlip $slip): ?WmsOrderSlipNumberAssignment
    {
        if (! $slip) {
            return null;
        }

        if (array_key_exists($slip->slip_number, $this->assignmentCache)) {
            return $this->assignmentCache[$slip->slip_number];
        }

        return $this->assignmentCache[$slip->slip_number] = WmsOrderSlipNumberAssignment::query()
            ->with('document')
            ->where('slip_number', $slip->slip_number)
            ->whereIn('status', [
                WmsOrderSlipNumberAssignment::STATUS_ACTIVE,
                WmsOrderSlipNumberAssignment::STATUS_TRANSMITTED,
            ])
            ->orderByDesc('id')
            ->first();
    }

    private function resolveOrderCandidate(WmsOrderIncomingSchedule $schedule): ?WmsOrderCandidate
    {
        if ($schedule->relationLoaded('orderCandidate')) {
            return $schedule->orderCandidate;
        }

        if (! $schedule->order_candidate_id) {
            return null;
        }

        return WmsOrderCandidate::query()->find($schedule->order_candidate_id);
    }

    private function resolveSentLinePayload(
        ?WmsOrderSlipNumberAssignment $assignment,
        ?WmsOrderCandidate $orderCandidate,
        ?WmsIncomingReceivedSlip $slip,
        WmsIncomingReceivedDetail $detail
    ): ?array {
        $document = $assignment?->document;

        if (! $document && $orderCandidate?->wms_order_jx_document_id) {
            $document = WmsOrderJxDocument::query()->find($orderCandidate->wms_order_jx_document_id);
        }

        if (! $document || ! $slip) {
            return null;
        }

        $lines = $this->sentDocumentLines($document);
        $lineKey = $slip->slip_number.'|'.$detail->d_line_number;

        if (isset($lines[$lineKey])) {
            return $lines[$lineKey];
        }

        foreach ($lines as $payload) {
            if (($payload['slip_number'] ?? null) !== $slip->slip_number) {
                continue;
            }

            if (
                filled($detail->d_item_code)
                && ($payload['item_code'] ?? null) === $detail->d_item_code
            ) {
                return $payload;
            }

            if (
                filled($detail->d_jan_code)
                && ($payload['jan_code'] ?? null) === $detail->d_jan_code
            ) {
                return $payload;
            }
        }

        return null;
    }

    private function sentDocumentLines(WmsOrderJxDocument $document): array
    {
        if (array_key_exists($document->id, $this->sentDocumentLineCache)) {
            return $this->sentDocumentLineCache[$document->id];
        }

        $content = $this->readStorageContent($document->file_path);
        if ($content === null) {
            return $this->sentDocumentLineCache[$document->id] = [];
        }

        $content = str_replace(["\r\n", "\r", "\n"], '', $content);
        $records = str_split($content, 128);
        $currentSlipNumber = null;
        $lines = [];

        foreach ($records as $record) {
            if (strlen($record) < 128) {
                continue;
            }

            if ($record[0] === 'B') {
                $currentSlipNumber = trim(substr($record, 3, 11));

                continue;
            }

            if ($record[0] !== 'D' || $currentSlipNumber === null) {
                continue;
            }

            $lineNumber = (int) trim(substr($record, 3, 2));
            $caseQuantity = (int) trim(substr($record, 94, 7));
            $unitPriceRaw = (int) trim(substr($record, 108, 10));
            $payload = [
                'slip_number' => $currentSlipNumber,
                'line_number' => $lineNumber,
                'product_name' => $this->convertSjisToUtf8(substr($record, 5, 64)),
                'jan_code' => trim(substr($record, 69, 13)),
                'item_code' => trim(substr($record, 82, 6)),
                'pack_quantity' => (int) trim(substr($record, 88, 6)),
                'case_quantity' => $caseQuantity,
                'piece_quantity' => (int) trim(substr($record, 101, 7)),
                'unit_price_raw' => $unitPriceRaw,
                'unit_price' => round($unitPriceRaw / 100, 2),
                'price_type' => $caseQuantity > 0 ? 'CASE' : 'PIECE',
                'raw_record_hash' => hash('sha256', $record),
                'raw_record_base64' => base64_encode($record),
            ];

            $lines[$currentSlipNumber.'|'.$lineNumber] = $payload;
        }

        return $this->sentDocumentLineCache[$document->id] = $lines;
    }

    private function readStorageContent(?string $filePath): ?string
    {
        if (blank($filePath)) {
            return null;
        }

        $source = $this->normalizeStoragePath((string) $filePath);

        try {
            if (! Storage::disk($source['disk'])->exists($source['path'])) {
                return null;
            }

            return Storage::disk($source['disk'])->get($source['path']);
        } catch (\Throwable $throwable) {
            Log::warning('[IncomingPriceCheckSourceRecorder] 送信JX原本を読めません', [
                'file_path' => $filePath,
                'error' => $throwable->getMessage(),
            ]);

            return null;
        }
    }

    private function normalizeStoragePath(string $filePath): array
    {
        if (str_starts_with($filePath, 's3:')) {
            return ['disk' => 's3', 'path' => ltrim(substr($filePath, 3), '/')];
        }

        if (str_starts_with($filePath, 'local:')) {
            return ['disk' => 'local', 'path' => ltrim(substr($filePath, 6), '/')];
        }

        return ['disk' => 's3', 'path' => ltrim($filePath, '/')];
    }

    private function comparisonPayload(
        WmsOrderIncomingSchedule $schedule,
        ?float $receivedPrice,
        ?float $receivedAmount,
        WmsIncomingReceivedDetail $detail,
        ?array $sentLinePayload
    ): array {
        $priceType = $this->comparisonPriceType($schedule, $detail, $receivedPrice, $sentLinePayload);
        $masterPrice = $priceType === 'CASE'
            ? $this->decimal($schedule->case_price)
            : $this->decimal($schedule->unit_price);
        $masterUnitPrice = $this->decimal($schedule->unit_price);
        $storedPartnerPrice = $priceType === 'CASE'
            ? $this->decimal($schedule->partner_case_price)
            : $this->decimal($schedule->partner_unit_price);
        $partnerPrice = $storedPartnerPrice ?? $receivedPrice;

        $diff = ($masterPrice !== null && $partnerPrice !== null)
            ? round($partnerPrice - $masterPrice, 4)
            : null;
        $quantityAdjustedPieces = $this->quantityAdjustedPieces($schedule, $detail);
        $quantityAdjustedUnitPrice = $this->unitPriceFromAmount($receivedAmount, $quantityAdjustedPieces);
        $quantityAdjustedDiff = ($quantityAdjustedUnitPrice !== null && $masterUnitPrice !== null)
            ? round($quantityAdjustedUnitPrice - $masterUnitPrice, 4)
            : null;
        $purchaseAdjustmentAmount = $this->purchaseAdjustmentAmount($schedule);
        $purchaseAdjustmentCaseEquivalent = $this->purchaseAdjustmentCaseEquivalent($detail);
        $adjustmentAdjustedUnitPrice = (
            $receivedAmount !== null
            && $purchaseAdjustmentAmount !== null
            && $purchaseAdjustmentCaseEquivalent !== null
            && $quantityAdjustedPieces !== null
        )
            ? $this->unitPriceFromAmount(
                $receivedAmount - ($purchaseAdjustmentAmount * $purchaseAdjustmentCaseEquivalent),
                $quantityAdjustedPieces,
            )
            : null;
        $adjustmentAdjustedDiff = ($adjustmentAdjustedUnitPrice !== null && $masterUnitPrice !== null)
            ? round($adjustmentAdjustedUnitPrice - $masterUnitPrice, 4)
            : null;
        $hasMismatch = $diff !== null
            && abs($diff) >= self::PRICE_DIFF_THRESHOLD
            && (
                $quantityAdjustedDiff === null
                || abs($quantityAdjustedDiff) >= self::PRICE_DIFF_THRESHOLD
            )
            && (
                $adjustmentAdjustedDiff === null
                || abs($adjustmentAdjustedDiff) >= self::PRICE_DIFF_THRESHOLD
            );

        return [
            'price_type' => $priceType,
            'master_price' => $masterPrice,
            'received_price' => $partnerPrice,
            'price_diff' => $diff,
            'quantity_adjusted_piece_quantity' => $quantityAdjustedPieces,
            'quantity_adjusted_unit_price' => $quantityAdjustedUnitPrice,
            'quantity_adjusted_price_diff' => $quantityAdjustedDiff,
            'purchase_adjustment_amount' => $purchaseAdjustmentAmount,
            'purchase_adjustment_case_equivalent_quantity' => $purchaseAdjustmentCaseEquivalent,
            'adjustment_adjusted_unit_price' => $adjustmentAdjustedUnitPrice,
            'adjustment_adjusted_price_diff' => $adjustmentAdjustedDiff,
            'has_mismatch' => $hasMismatch,
            'rule_version' => 'p-box-adjusted-price-compare-2026-08-30',
        ];
    }

    private function quantityAdjustedPieces(WmsOrderIncomingSchedule $schedule, WmsIncomingReceivedDetail $detail): ?float
    {
        $totalQuantity = $this->decimal($detail->total_quantity);
        $capacityCase = $this->decimal($schedule->item?->capacity_case);

        if ($capacityCase !== null && $capacityCase <= 1) {
            if ($totalQuantity !== null && $totalQuantity > 0) {
                return $totalQuantity;
            }

            $pieceQuantity = $this->decimal($detail->d_piece_quantity);

            return $pieceQuantity !== null && $pieceQuantity > 0 ? $pieceQuantity : null;
        }

        $packQuantity = $this->decimal($detail->d_pack_quantity);
        $caseQuantity = $this->decimal($detail->d_case_quantity);
        $pieceQuantity = $this->decimal($detail->d_piece_quantity);

        if ($packQuantity !== null && $packQuantity > 0 && $caseQuantity !== null && $caseQuantity > 0) {
            return round(($packQuantity * $caseQuantity) + max(0, $pieceQuantity ?? 0), 4);
        }

        if ($totalQuantity !== null && $totalQuantity > 0) {
            return $totalQuantity;
        }

        return $pieceQuantity !== null && $pieceQuantity > 0 ? $pieceQuantity : null;
    }

    private function purchaseAdjustmentAmount(WmsOrderIncomingSchedule $schedule): ?float
    {
        $pBoxPrice = $this->decimal($schedule->item?->p_box_price);

        return $pBoxPrice !== null && $pBoxPrice > 0 ? $pBoxPrice : null;
    }

    private function purchaseAdjustmentCaseEquivalent(WmsIncomingReceivedDetail $detail): ?float
    {
        $packQuantity = $this->decimal($detail->d_pack_quantity);

        if ($packQuantity === null || $packQuantity <= 0) {
            return null;
        }

        $totalQuantity = $this->decimal($detail->total_quantity);
        if ($totalQuantity !== null && $totalQuantity > 0) {
            return round($totalQuantity / $packQuantity, 4);
        }

        $caseQuantity = $this->decimal($detail->d_case_quantity) ?? 0;
        $pieceQuantity = $this->decimal($detail->d_piece_quantity) ?? 0;
        $caseEquivalent = $caseQuantity + ($pieceQuantity / $packQuantity);

        return $caseEquivalent > 0 ? round($caseEquivalent, 4) : null;
    }

    private function unitPriceFromAmount(?float $amount, ?float $quantity): ?float
    {
        if ($amount === null || $quantity === null || $quantity == 0.0) {
            return null;
        }

        return round($amount / $quantity, 4);
    }

    private function comparisonPriceType(
        WmsOrderIncomingSchedule $schedule,
        WmsIncomingReceivedDetail $detail,
        ?float $receivedPrice,
        ?array $sentLinePayload
    ): string {
        $priceType = $schedule->price_type ?: 'PIECE';

        if (
            $this->isZeroReceivedQuantity($detail)
            && ($sentLinePayload['price_type'] ?? null) === 'CASE'
            && $receivedPrice !== null
        ) {
            $casePrice = $this->decimal($schedule->case_price);
            $unitPrice = $this->decimal($schedule->unit_price);

            if ($casePrice !== null) {
                $caseDiff = abs($receivedPrice - $casePrice);
                $unitDiff = $unitPrice !== null ? abs($receivedPrice - $unitPrice) : null;

                if ($caseDiff <= 0.0001 || ($unitDiff !== null && $caseDiff < $unitDiff)) {
                    return 'CASE';
                }
            }
        }

        return $priceType;
    }

    private function isZeroReceivedQuantity(WmsIncomingReceivedDetail $detail): bool
    {
        return (bool) $detail->is_shortage
            || (int) ($detail->total_quantity ?? 0) === 0
            || (
                (int) ($detail->d_case_quantity ?? 0) === 0
                && (int) ($detail->d_piece_quantity ?? 0) === 0
            );
    }

    private function receivedPayload(
        WmsIncomingReceivedFile $file,
        ?WmsIncomingReceivedSlip $slip,
        WmsIncomingReceivedDetail $detail
    ): array {
        return [
            'file' => [
                'id' => $file->id,
                'contractor_id' => $file->contractor_id,
                'filename' => $file->filename,
                'format_type' => $file->format_type,
                'status' => $file->status,
                'raw_file_path' => $file->raw_file_path,
                'raw_file_size' => $file->raw_file_size,
                'raw_sha256' => $file->raw_sha256,
                'received_message_id' => $file->received_message_id,
                'a_data_type' => $file->a_data_type,
                'a_created_date' => $file->a_created_date,
                'a_created_time' => $file->a_created_time,
                'a_record_count' => $file->a_record_count,
                'a_slip_count' => $file->a_slip_count,
                'finet_sender_code' => $file->finet_sender_code,
                'finet_sender_name' => $file->finet_sender_name,
            ],
            'slip' => [
                'id' => $slip?->id,
                'slip_number' => $slip?->slip_number,
                'match_status' => $slip?->match_status,
                'b_data_type' => $slip?->b_data_type,
                'b_shop_code' => $slip?->b_shop_code,
                'b_category_code' => $slip?->b_category_code,
                'b_slip_type' => $slip?->b_slip_type,
                'b_order_date' => $slip?->b_order_date,
                'b_delivery_date' => $slip?->b_delivery_date,
                'b_delivery_route' => $slip?->b_delivery_route,
                'b_contractor_code' => $slip?->b_contractor_code,
                'b_shop_name' => $slip?->b_shop_name,
                'b_delivery_place' => $slip?->b_delivery_place,
                'b_note' => $slip?->b_note,
                'b_direct_type' => $slip?->b_direct_type,
                'matched_schedule_id' => $slip?->matched_schedule_id,
            ],
            'detail' => [
                'id' => $detail->id,
                'd_data_type' => $detail->d_data_type,
                'd_line_number' => $detail->d_line_number,
                'd_product_name' => $detail->d_product_name,
                'd_jan_code' => $detail->d_jan_code,
                'd_item_code' => $detail->d_item_code,
                'd_pack_quantity' => $detail->d_pack_quantity,
                'd_case_quantity' => $detail->d_case_quantity,
                'd_piece_quantity' => $detail->d_piece_quantity,
                'd_unit_price' => $detail->d_unit_price,
                'd_total_pieces' => $detail->d_total_pieces,
                'd_note' => $detail->d_note,
                'd_amount' => $detail->d_amount,
                'total_quantity' => $detail->total_quantity,
                'is_shortage' => $detail->is_shortage,
                'match_status' => $detail->match_status,
                'matched_item_id' => $detail->matched_item_id,
                'matched_schedule_id' => $detail->matched_schedule_id,
                'expected_quantity' => $detail->expected_quantity,
            ],
        ];
    }

    private function schedulePayload(WmsOrderIncomingSchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'warehouse_id' => $schedule->warehouse_id,
            'warehouse_code' => $schedule->warehouse?->code,
            'item_id' => $schedule->item_id,
            'item_code' => $schedule->item_code,
            'item_name' => $schedule->item?->name_main,
            'search_code' => $schedule->search_code,
            'contractor_id' => $schedule->contractor_id,
            'contractor_code' => $schedule->contractor?->code,
            'contractor_name' => $schedule->contractor?->name,
            'supplier_id' => $schedule->supplier_id,
            'order_candidate_id' => $schedule->order_candidate_id,
            'order_source' => $this->enumValue($schedule->order_source),
            'slip_number' => $schedule->slip_number,
            'expected_quantity' => $schedule->expected_quantity,
            'received_quantity' => $schedule->received_quantity,
            'shipped_quantity' => $schedule->shipped_quantity,
            'shortage_quantity' => $schedule->shortage_quantity,
            'quantity_type' => $this->enumValue($schedule->quantity_type),
            'order_date' => $this->dateString($schedule->order_date),
            'expected_arrival_date' => $this->dateString($schedule->expected_arrival_date),
            'status' => $this->enumValue($schedule->status),
            'unit_price' => $schedule->unit_price,
            'case_price' => $schedule->case_price,
            'partner_unit_price' => $schedule->partner_unit_price,
            'partner_case_price' => $schedule->partner_case_price,
            'price_type' => $schedule->price_type,
            'purchase_queue_id' => $schedule->purchase_queue_id,
            'purchase_slip_number' => $schedule->purchase_slip_number,
        ];
    }

    private function sentPayload(
        ?WmsOrderSlipNumberAssignment $assignment,
        ?WmsOrderCandidate $orderCandidate,
        ?array $sentLinePayload
    ): array {
        return [
            'assignment' => [
                'id' => $assignment?->id,
                'wms_order_jx_document_id' => $assignment?->wms_order_jx_document_id,
                'document_type' => $assignment?->document_type,
                'slip_number' => $assignment?->slip_number,
                'b_record_sequence' => $assignment?->b_record_sequence,
                'status' => $assignment?->status,
                'order_candidate_ids' => $assignment?->order_candidate_ids,
            ],
            'order_candidate' => [
                'id' => $orderCandidate?->id,
                'batch_code' => $orderCandidate?->batch_code,
                'warehouse_id' => $orderCandidate?->warehouse_id,
                'item_id' => $orderCandidate?->item_id,
                'item_code' => $orderCandidate?->item_code,
                'search_code' => $orderCandidate?->search_code,
                'contractor_id' => $orderCandidate?->contractor_id,
                'supplier_id' => $orderCandidate?->supplier_id,
                'ordering_code' => $orderCandidate?->ordering_code,
                'order_quantity' => $orderCandidate?->order_quantity,
                'quantity_type' => $this->enumValue($orderCandidate?->quantity_type),
                'purchase_unit_price' => $orderCandidate?->purchase_unit_price,
                'wms_order_jx_document_id' => $orderCandidate?->wms_order_jx_document_id,
            ],
            'jx_d_record' => $sentLinePayload,
        ];
    }

    private function eosPayload(?WmsJxEosImportBatch $batch, ?WmsJxEosLine $line): array
    {
        return [
            'batch' => [
                'id' => $batch?->id,
                'wms_jx_transmission_log_id' => $batch?->wms_jx_transmission_log_id,
                'status' => $batch?->status,
                'is_current' => $batch?->is_current,
                'source_message_id' => $batch?->source_message_id,
                'source_file_path' => $batch?->source_file_path,
                'file_sha256' => $batch?->file_sha256,
            ],
            'line' => $line ? [
                'id' => $line->id,
                'source_record_no' => $line->source_record_no,
                'line_sequence' => $line->line_sequence,
                'line_number' => $line->line_number,
                'jan_code' => $line->jan_code,
                'item_code' => $line->item_code,
                'pack_quantity' => $line->pack_quantity,
                'case_quantity' => $line->case_quantity,
                'piece_quantity' => $line->piece_quantity,
                'total_quantity' => $line->total_quantity,
                'unit_price_raw' => $line->unit_price_raw,
                'unit_price' => $line->unit_price,
                'amount' => $line->amount,
                'line_hash' => $line->line_hash,
                'raw_record_hash' => $line->raw_record_hash,
                'raw_record_base64' => $line->raw_record_base64,
            ] : null,
        ];
    }

    private function receivedUnitPrice(WmsIncomingReceivedDetail $detail): ?float
    {
        return is_numeric($detail->d_unit_price) ? round(((float) $detail->d_unit_price) / 100, 4) : null;
    }

    private function receivedAmount(WmsIncomingReceivedDetail $detail, ?WmsJxEosLine $eosLine): ?float
    {
        if ($eosLine && $eosLine->amount !== null) {
            return $this->decimal($eosLine->amount);
        }

        return $this->decimal($detail->d_amount);
    }

    private function isPriceCheckExcluded(WmsIncomingReceivedDetail $detail): bool
    {
        return (string) $detail->d_jan_code === self::SHIPPING_JAN_CODE;
    }

    private function decimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function enumValue(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value === null) {
            return null;
        }

        return (string) $value;
    }

    private function dateString(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return (string) $value;
    }

    private function parseJxYymmdd(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            if (preg_match('/^\d{6}$/', $value)) {
                return \Carbon\Carbon::createFromFormat('ymd', $value)->format('Y-m-d');
            }

            if (preg_match('/^\d{8}$/', $value)) {
                return \Carbon\Carbon::createFromFormat('Ymd', $value)->format('Y-m-d');
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function convertSjisToUtf8(string $value): string
    {
        $converted = mb_convert_encoding($value, 'UTF-8', 'SJIS-win');

        return trim($converted !== false ? $converted : $value);
    }

    private function encodeJsonAttributes(array $attributes): array
    {
        foreach ([
            'received_payload',
            'schedule_payload',
            'sent_payload',
            'eos_payload',
            'calculation_payload',
        ] as $key) {
            if (! array_key_exists($key, $attributes)) {
                continue;
            }

            $attributes[$key] = json_encode($attributes[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $attributes;
    }
}
