<?php

namespace App\Services\AutoOrder;

use App\Models\WmsOrderSlipNumberAssignment;
use App\Models\WmsOrderSlipNumberSequence;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class LegacyEosSlipNumberService
{
    public const DOCUMENT_TYPE_EOS_ORDER = 'EOS_ORDER';

    private const FIXED_MIDDLE_CODE = '10';

    private const BASE_YEAR = 1980;

    private const MAX_SEQUENCE = 99999;

    /**
     * @return array{slip_number: string, store_code: string, year_code: int, sequence_no: int}
     */
    public function allocateForWarehouse(mixed $warehouse, CarbonInterface|string|null $orderDate = null): array
    {
        $storeCode = $this->resolveStoreCode($warehouse);
        $yearCode = $this->yearCode($orderDate);
        $warehouseId = is_object($warehouse) && isset($warehouse->id) ? (int) $warehouse->id : null;

        return $this->allocate($storeCode, $yearCode, 1, $warehouseId)[0];
    }

    /**
     * @return array<int, array{slip_number: string, store_code: string, year_code: int, sequence_no: int}>
     */
    public function allocate(string $storeCode, int $yearCode, int $count, ?int $warehouseId = null): array
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('採番数は1以上で指定してください');
        }

        $storeCode = $this->normalizeStoreCode($storeCode);
        $yearCode = $this->normalizeYearCode($yearCode);

        return DB::connection('sakemaru')->transaction(function () use ($storeCode, $yearCode, $count, $warehouseId): array {
            $sequence = $this->lockedSequence($storeCode, $yearCode);

            if (! $sequence) {
                $sequence = $this->createAndLockSequence($storeCode, $yearCode, $warehouseId);
            } elseif ($warehouseId !== null && $sequence->warehouse_id === null) {
                $sequence->warehouse_id = $warehouseId;
            }

            $start = $sequence->current_sequence + 1;
            $end = $sequence->current_sequence + $count;

            if ($end > self::MAX_SEQUENCE) {
                throw new \RuntimeException("旧EOS伝票番号の連番上限を超えました: 店舗{$storeCode} 年度{$yearCode}");
            }

            $sequence->current_sequence = $end;
            $sequence->save();

            $numbers = [];
            for ($sequenceNo = $start; $sequenceNo <= $end; $sequenceNo++) {
                $numbers[] = [
                    'slip_number' => $this->formatSlipNumber($storeCode, $yearCode, $sequenceNo),
                    'store_code' => $storeCode,
                    'year_code' => $yearCode,
                    'sequence_no' => $sequenceNo,
                ];
            }

            return $numbers;
        }, 3);
    }

    public function formatSlipNumber(string $storeCode, int $yearCode, int $sequence): string
    {
        $storeCode = $this->normalizeStoreCode($storeCode);
        $yearCode = $this->normalizeYearCode($yearCode);

        if ($sequence < 1 || $sequence > self::MAX_SEQUENCE) {
            throw new \RuntimeException("旧EOS伝票番号の連番上限を超えました: {$sequence}");
        }

        return $storeCode
            .str_pad((string) $yearCode, 2, '0', STR_PAD_LEFT)
            .self::FIXED_MIDDLE_CODE
            .str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }

    public function isLegacySlipNumber(?string $slipNumber): bool
    {
        return is_string($slipNumber)
            && preg_match('/^\d{11}$/', $slipNumber) === 1
            && substr($slipNumber, 4, 2) === self::FIXED_MIDDLE_CODE
            && (int) substr($slipNumber, 6, 5) >= 1;
    }

    public function resolveStoreCode(mixed $warehouse): string
    {
        $raw = is_object($warehouse) ? ($warehouse->code ?? null) : $warehouse;

        if ($raw === null || $raw === '') {
            throw new \RuntimeException('旧EOS伝票番号の店舗CDを解決できません');
        }

        return $this->normalizeStoreCode((string) $raw);
    }

    public function yearCode(CarbonInterface|string|null $date = null): int
    {
        $year = $date instanceof CarbonInterface
            ? $date->year
            : Carbon::parse($date ?? now())->year;

        return $this->normalizeYearCode($year - self::BASE_YEAR);
    }

    private function lockedSequence(string $storeCode, int $yearCode): ?WmsOrderSlipNumberSequence
    {
        return WmsOrderSlipNumberSequence::query()
            ->where('document_type', self::DOCUMENT_TYPE_EOS_ORDER)
            ->where('store_code', $storeCode)
            ->where('year_code', $yearCode)
            ->lockForUpdate()
            ->first();
    }

    private function createAndLockSequence(string $storeCode, int $yearCode, ?int $warehouseId): WmsOrderSlipNumberSequence
    {
        try {
            WmsOrderSlipNumberSequence::query()->create([
                'document_type' => self::DOCUMENT_TYPE_EOS_ORDER,
                'warehouse_id' => $warehouseId,
                'store_code' => $storeCode,
                'year_code' => $yearCode,
                'current_sequence' => $this->initialSequence($storeCode, $yearCode),
            ]);
        } catch (QueryException $e) {
            if (! $this->isDuplicateKeyException($e)) {
                throw $e;
            }
        }

        $sequence = $this->lockedSequence($storeCode, $yearCode);

        if (! $sequence) {
            throw new \RuntimeException("旧EOS伝票番号の採番行を作成できませんでした: 店舗{$storeCode} 年度{$yearCode}");
        }

        return $sequence;
    }

    private function initialSequence(string $storeCode, int $yearCode): int
    {
        $prefix = $storeCode.str_pad((string) $yearCode, 2, '0', STR_PAD_LEFT).self::FIXED_MIDDLE_CODE;

        $scheduleMax = DB::connection('sakemaru')
            ->table('wms_order_incoming_schedules')
            ->where('slip_number', 'like', $prefix.'%')
            ->where('slip_number', 'REGEXP', '^[0-9]{11}$')
            ->selectRaw('MAX(CAST(SUBSTRING(slip_number, 7, 5) AS UNSIGNED)) as max_sequence')
            ->value('max_sequence');

        $assignmentMax = WmsOrderSlipNumberAssignment::query()
            ->where('document_type', self::DOCUMENT_TYPE_EOS_ORDER)
            ->where('store_code', $storeCode)
            ->where('year_code', $yearCode)
            ->max('sequence_no');

        return max((int) $scheduleMax, (int) $assignmentMax);
    }

    private function normalizeStoreCode(string $storeCode): string
    {
        $digits = preg_replace('/\D/', '', $storeCode) ?? '';

        if ($digits === '') {
            throw new \RuntimeException("旧EOS伝票番号の店舗CDが数値ではありません: {$storeCode}");
        }

        $storeNumber = (int) $digits;
        if ($storeNumber < 0 || $storeNumber > 99) {
            throw new \RuntimeException("旧EOS伝票番号は2桁店舗CDのみ対応しています: {$storeCode}");
        }

        return str_pad((string) $storeNumber, 2, '0', STR_PAD_LEFT);
    }

    private function normalizeYearCode(int $yearCode): int
    {
        if ($yearCode < 0 || $yearCode > 99) {
            throw new \RuntimeException("旧EOS伝票番号の年度コード範囲外です: {$yearCode}");
        }

        return $yearCode;
    }

    private function isDuplicateKeyException(QueryException $e): bool
    {
        return (string) ($e->errorInfo[1] ?? '') === '1062';
    }
}
