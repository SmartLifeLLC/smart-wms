<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\AutoOrder\OrderSource;
use App\Models\WmsOrderIncomingSchedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 入庫完了データの仕入連携サービス
 *
 * 仕様書: storage/specifications/20260315/inbound/purchase-create-queue-batching.md
 */
class IncomingTransmissionService
{
    private array $supplierCodeCache = [];

    private array $itemContractorSupplierIdCache = [];

    /**
     * 入庫完了データを purchase_create_queue にバッチ登録
     *
     * グルーピング基準:
     * - warehouse_code (倉庫コード)
     * - supplier_code (仕入先コード)
     * - slip_number (入荷/EOS伝票番号)
     * - process_date (仕入連携日 = 計上日)
     * - actual_arrival_date / confirmed_at date (入荷日 = 配送日/買掛日)
     *
     * @return array ['success' => bool, 'queue_count' => int, 'schedule_count' => int, 'errors' => array]
     */
    public function transmitConfirmedIncomings(?int $warehouseId = null, ?array $scheduleIds = null, ?int $contractorId = null): array
    {
        $scheduleIds = $this->normalizeScheduleIds($scheduleIds);

        if ($scheduleIds !== null && $scheduleIds === []) {
            return [
                'success' => true,
                'queue_count' => 0,
                'schedule_count' => 0,
                'errors' => [],
            ];
        }

        return DB::connection('sakemaru')->transaction(function () use ($warehouseId, $scheduleIds, $contractorId): array {
            return $this->transmitConfirmedIncomingsInTransaction($warehouseId, $scheduleIds, $contractorId);
        });
    }

    private function transmitConfirmedIncomingsInTransaction(?int $warehouseId, ?array $scheduleIds, ?int $contractorId): array
    {
        // CONFIRMED状態の未処理入庫データを取得する。移動由来は仕入連携対象外。
        // 本部発注対象は仕入キューを作らず、処理済み化だけ行う。
        $query = WmsOrderIncomingSchedule::query()
            ->readyForIncomingTransmission($warehouseId)
            ->with(['warehouse', 'item', 'contractor', 'supplier'])
            ->lockForUpdate();

        if ($scheduleIds !== null) {
            $query->whereKey($scheduleIds);
        }

        if ($contractorId !== null) {
            $query->where('contractor_id', $contractorId);
        }

        $schedules = $query->get();

        if ($schedules->isEmpty()) {
            Log::info('No confirmed incoming schedules to transmit', [
                'warehouse_id' => $warehouseId,
                'schedule_ids' => $scheduleIds,
                'contractor_id' => $contractorId,
            ]);

            return [
                'success' => true,
                'queue_count' => 0,
                'schedule_count' => 0,
                'errors' => [],
            ];
        }

        $purchaseScheduleIds = WmsOrderIncomingSchedule::query()
            ->whereKey($schedules->pluck('id')->all())
            ->forPurchaseTransmission()
            ->pluck('id')
            ->all();

        $purchaseSchedules = $schedules->whereIn('id', $purchaseScheduleIds)->values();
        $nonPurchaseSchedules = $schedules->whereNotIn('id', $purchaseScheduleIds)->values();

        $queueCount = 0;
        $scheduleCount = 0;
        $errors = [];

        if ($scheduleIds !== null && $nonPurchaseSchedules->isNotEmpty()) {
            foreach ($nonPurchaseSchedules as $schedule) {
                $errors[] = [
                    'schedule_id' => $schedule->id,
                    'error' => "ID {$schedule->id} は仕入データ送信対象ではありません。",
                ];
            }

            $nonPurchaseSchedules = collect();
        }

        [$purchaseSchedules, $validationErrors] = $this->filterValidPurchaseSchedules($purchaseSchedules);
        $errors = array_merge($errors, $validationErrors);

        $scheduleCount += $this->markSchedulesAsTransmittedWithoutPurchaseQueue($nonPurchaseSchedules);

        if ($purchaseSchedules->isEmpty()) {
            return [
                'success' => empty($errors),
                'queue_count' => 0,
                'schedule_count' => $scheduleCount,
                'errors' => $errors,
            ];
        }

        // グルーピング: 倉庫 + 仕入先 + 伝票番号 + 計上日 + 配送日 + 買掛日 + 分割キー
        $grouped = $purchaseSchedules->groupBy(function ($schedule) {
            return $this->purchaseGroupKey($schedule);
        });

        foreach ($grouped as $groupKey => $groupSchedules) {
            try {
                // 100件以下で分割（仕様推奨）
                $chunks = $groupSchedules->chunk(100);

                foreach ($chunks as $chunk) {
                    $result = DB::connection('sakemaru')->transaction(function () use ($chunk): array {
                        $queueId = $this->createPurchaseQueueRecord($chunk);
                        $scheduleIds = $chunk
                            ->pluck('id')
                            ->map(fn ($id): int => (int) $id)
                            ->all();

                        $updatedCount = WmsOrderIncomingSchedule::query()
                            ->whereKey($scheduleIds)
                            ->where('status', IncomingScheduleStatus::CONFIRMED->value)
                            ->whereNull('purchase_queue_id')
                            ->update([
                                'status' => IncomingScheduleStatus::TRANSMITTED->value,
                                'purchase_queue_id' => $queueId,
                            ]);

                        if ($updatedCount !== count($scheduleIds)) {
                            throw new \RuntimeException('仕入キュー作成後の送信済み更新件数が一致しません。');
                        }

                        return [
                            'queue_id' => $queueId,
                            'schedule_count' => count($scheduleIds),
                        ];
                    });

                    $queueCount++;
                    $scheduleCount += $result['schedule_count'];

                    Log::info('Purchase queue created from incoming', [
                        'group_key' => $groupKey,
                        'queue_id' => $result['queue_id'],
                        'schedule_count' => $result['schedule_count'],
                    ]);
                }
            } catch (\Exception $e) {
                $errors[] = [
                    'group_key' => $groupKey,
                    'error' => $e->getMessage(),
                ];
                Log::error('Failed to create purchase queue from incoming', [
                    'group_key' => $groupKey,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'success' => empty($errors),
            'queue_count' => $queueCount,
            'schedule_count' => $scheduleCount,
            'errors' => $errors,
        ];
    }

    private function normalizeScheduleIds(?array $scheduleIds): ?array
    {
        if ($scheduleIds === null) {
            return null;
        }

        return collect($scheduleIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function filterValidPurchaseSchedules(Collection $schedules): array
    {
        $errors = [];

        $validSchedules = $schedules
            ->filter(function (WmsOrderIncomingSchedule $schedule) use (&$errors): bool {
                $supplierCode = $this->getSupplierCode($schedule);
                $warehouseCode = $this->getWarehouseCode($schedule);
                $itemCode = $this->getItemCode($schedule);
                $slipNumber = $this->getSlipNumber($schedule);
                $processDate = $this->getProcessDate($schedule);
                $deliveredDate = $this->getDeliveredDate($schedule);
                $accountDate = $this->getAccountDate($schedule);

                if (
                    $supplierCode === ''
                    || $warehouseCode === ''
                    || $itemCode === ''
                    || $slipNumber === ''
                    || $processDate === null
                    || $deliveredDate === null
                    || $accountDate === null
                ) {
                    $errors[] = [
                        'schedule_id' => $schedule->id,
                        'group_key' => $this->purchaseGroupKey($schedule),
                        'error' => sprintf(
                            '仕入キュー登録に必要な情報を解決できません（倉庫CD:%s / 仕入先CD:%s / 伝票番号:%s / 商品CD:%s / 連携日:%s / 入荷日:%s / 買掛日:%s）',
                            $warehouseCode !== '' ? $warehouseCode : '-',
                            $supplierCode !== '' ? $supplierCode : '-',
                            $slipNumber !== '' ? $slipNumber : '-',
                            $itemCode !== '' ? $itemCode : '-',
                            $processDate ?? '-',
                            $deliveredDate ?? '-',
                            $accountDate ?? '-',
                        ),
                    ];

                    return false;
                }

                return true;
            })
            ->values();

        return [$validSchedules, $errors];
    }

    /**
     * 仕入キューを作らない入荷完了データを処理済みにする
     */
    private function markSchedulesAsTransmittedWithoutPurchaseQueue(Collection $schedules): int
    {
        foreach ($schedules as $schedule) {
            $schedule->update([
                'status' => IncomingScheduleStatus::TRANSMITTED,
            ]);
        }

        if ($schedules->isNotEmpty()) {
            Log::info('Incoming schedules marked transmitted without purchase queue', [
                'schedule_count' => $schedules->count(),
            ]);
        }

        return $schedules->count();
    }

    /**
     * 仕入先コードを取得
     */
    private function getSupplierCode(WmsOrderIncomingSchedule $schedule): string
    {
        $cachedCode = $schedule->getAttribute('purchase_transmission_supplier_code');

        if (is_string($cachedCode)) {
            return $cachedCode;
        }

        $supplierId = $this->resolvePurchaseTransmissionSupplierId($schedule);
        $supplierCode = $supplierId ? $this->getSupplierCodeById($supplierId) : null;
        $supplierCode = trim((string) $supplierCode);

        $schedule->setAttribute('purchase_transmission_supplier_code', $supplierCode);

        return $supplierCode;
    }

    private function resolvePurchaseTransmissionSupplierId(WmsOrderIncomingSchedule $schedule): ?int
    {
        $contractorSupplierId = $this->resolveContractorSupplierId($schedule);

        if ($contractorSupplierId && $this->getSupplierCodeById($contractorSupplierId)) {
            return $contractorSupplierId;
        }

        if ($schedule->supplier_id && $this->getSupplierCodeById((int) $schedule->supplier_id)) {
            return (int) $schedule->supplier_id;
        }

        if ($this->requiresConfirmedSupplier($schedule)) {
            return null;
        }

        $supplierId = $this->resolveItemContractorSupplierId($schedule);

        if (! $supplierId || ! $this->getSupplierCodeById($supplierId)) {
            return null;
        }

        return $supplierId;
    }

    private function requiresConfirmedSupplier(WmsOrderIncomingSchedule $schedule): bool
    {
        return $schedule->is_receive_matched
            || $schedule->order_source === OrderSource::RECEIVED;
    }

    private function resolveItemContractorSupplierId(WmsOrderIncomingSchedule $schedule): ?int
    {
        $cacheKey = implode(':', [
            (int) $schedule->warehouse_id,
            (int) $schedule->item_id,
            (int) $schedule->contractor_id,
        ]);

        if (array_key_exists($cacheKey, $this->itemContractorSupplierIdCache)) {
            return $this->itemContractorSupplierIdCache[$cacheKey];
        }

        $query = DB::connection('sakemaru')
            ->table('item_contractors')
            ->where('warehouse_id', $schedule->warehouse_id)
            ->where('item_id', $schedule->item_id)
            ->whereNotNull('supplier_id');

        if ($schedule->contractor_id) {
            $query->where('contractor_id', $schedule->contractor_id);
        }

        $supplierId = $query->orderBy('id')->value('supplier_id');

        return $this->itemContractorSupplierIdCache[$cacheKey] = $supplierId ? (int) $supplierId : null;
    }

    private function resolveContractorSupplierId(WmsOrderIncomingSchedule $schedule): ?int
    {
        if (! $schedule->contractor_id) {
            return null;
        }

        $loadedSupplierId = $schedule->relationLoaded('contractor')
            ? $schedule->contractor?->supplier_id
            : null;

        if ($loadedSupplierId) {
            return (int) $loadedSupplierId;
        }

        $supplierId = DB::connection('sakemaru')
            ->table('contractors')
            ->where('id', $schedule->contractor_id)
            ->value('supplier_id');

        return $supplierId ? (int) $supplierId : null;
    }

    private function getSupplierCodeById(int $supplierId): ?string
    {
        if ($supplierId <= 0) {
            return null;
        }

        if (array_key_exists($supplierId, $this->supplierCodeCache)) {
            return $this->supplierCodeCache[$supplierId];
        }

        $supplierCode = DB::connection('sakemaru')
            ->table('suppliers as s')
            ->join('partners as p', 's.partner_id', '=', 'p.id')
            ->where('s.id', $supplierId)
            ->value('p.code');

        return $this->supplierCodeCache[$supplierId] = filled($supplierCode) ? trim((string) $supplierCode) : null;
    }

    private function getWarehouseCode(WmsOrderIncomingSchedule $schedule): string
    {
        return trim((string) ($schedule->warehouse?->code ?? ''));
    }

    private function getItemCode(WmsOrderIncomingSchedule $schedule): string
    {
        return trim((string) ($schedule->item?->code ?? $schedule->item_code ?? ''));
    }

    private function getSlipNumber(WmsOrderIncomingSchedule $schedule): string
    {
        return trim((string) $schedule->slip_number);
    }

    private function getProcessDate(WmsOrderIncomingSchedule $schedule): ?string
    {
        return $this->getDeliveredDate($schedule);
    }

    private function getDeliveredDate(WmsOrderIncomingSchedule $schedule): ?string
    {
        return $schedule->actual_arrival_date?->format('Y-m-d')
            ?? $schedule->expected_arrival_date?->format('Y-m-d')
            ?? $schedule->confirmed_at?->format('Y-m-d');
    }

    private function getAccountDate(WmsOrderIncomingSchedule $schedule): ?string
    {
        return $this->getDeliveredDate($schedule);
    }

    private function getPurchaseSplitKey(WmsOrderIncomingSchedule $schedule): string
    {
        return trim((string) ($schedule->purchase_split_key ?? ''));
    }

    private function purchaseGroupKey(WmsOrderIncomingSchedule $schedule): string
    {
        return implode('_', [
            $this->getWarehouseCode($schedule) ?: 'UNKNOWN_WAREHOUSE',
            $this->getSupplierCode($schedule) ?: 'UNKNOWN_SUPPLIER',
            $this->getSlipNumber($schedule) ?: 'UNKNOWN_SLIP_NUMBER',
            $this->getProcessDate($schedule) ?? 'UNKNOWN_PROCESS_DATE',
            $this->getDeliveredDate($schedule) ?? 'UNKNOWN_DELIVERED_DATE',
            $this->getAccountDate($schedule) ?? 'UNKNOWN_ACCOUNT_DATE',
            $this->getPurchaseSplitKey($schedule) ?: 'PRIMARY',
        ]);
    }

    /**
     * purchase_create_queue にレコードを作成
     *
     * @param  Collection  $schedules  同一グループの入庫データ
     */
    private function createPurchaseQueueRecord(Collection $schedules): int
    {
        $first = $schedules->first();

        // マスタ情報を取得
        $supplierCode = $this->getSupplierCode($first);
        $slipNumber = $this->getSlipNumber($first);
        $processDate = $this->getProcessDate($first);
        $deliveredDate = $this->getDeliveredDate($first);
        $accountDate = $this->getAccountDate($first);

        if ($slipNumber === '' || $processDate === null || $deliveredDate === null || $accountDate === null) {
            throw new \RuntimeException('仕入キュー登録に必要な伝票番号または日付を解決できません。');
        }

        // 明細を構築
        $details = $schedules->map(function ($schedule) {
            $detail = [
                'item_code' => $this->getItemCode($schedule),
                'quantity' => $schedule->received_quantity,
                'quantity_type' => $schedule->quantity_type?->value ?? 'PIECE',
                'shortage_quantity' => $schedule->shortage_quantity ?? 0,
            ];

            // 賞味期限がある場合のみ追加（仕様書: 指定がない場合は基幹側で自動計算）
            if ($schedule->expiration_date) {
                $detail['expiration_date'] = $schedule->expiration_date->format('Y-m-d');
            }

            return $detail;
        })->toArray();

        // 仕入データを構築
        $purchaseData = [
            'process_date' => $processDate,
            'delivered_date' => $deliveredDate,
            'account_date' => $accountDate,
            'supplier_code' => $supplierCode,
            'warehouse_code' => $this->getWarehouseCode($first),
            'slip_number' => $slipNumber,
            'note' => $this->buildPurchaseNote($first, $schedules),
            'details' => $details,
        ];

        // キューに挿入
        $queueId = DB::connection('sakemaru')->table('purchase_create_queue')->insertGetId([
            'request_uuid' => Str::uuid()->toString(),
            'slip_number' => $slipNumber,
            'delivered_date' => $deliveredDate,
            'items' => json_encode($purchaseData, JSON_UNESCAPED_UNICODE),
            'status' => 'BEFORE',
            'retry_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $queueId;
    }

    /**
     * 仕入れ伝票の備考を構築
     */
    private function buildPurchaseNote(WmsOrderIncomingSchedule $schedule, ?Collection $schedules = null): string
    {
        $parts = [];

        if ($schedule->order_source->value === 'AUTO') {
            $parts[] = '自動発注';
            if ($schedule->order_candidate_id) {
                $parts[] = "候補ID:{$schedule->order_candidate_id}";
            }
        } elseif ($schedule->order_source->value === 'RECEIVED') {
            $parts[] = '受信データ';
        } else {
            $parts[] = '手動発注';
            if ($schedule->manual_order_number) {
                $parts[] = "発注番号:{$schedule->manual_order_number}";
            }
        }

        // 欠品数がある場合は備考に追記
        if ($schedule->shortage_quantity > 0) {
            $parts[] = "欠品数:{$schedule->shortage_quantity}";
        }

        $orderDates = ($schedules ?? collect([$schedule]))
            ->map(fn (WmsOrderIncomingSchedule $schedule): ?string => $schedule->order_date?->format('Y-m-d'))
            ->filter()
            ->unique()
            ->sort()
            ->values();

        if ($orderDates->isNotEmpty()) {
            $parts[] = '元発注日:'.$orderDates->implode(',');
        }

        return implode(' / ', $parts);
    }
}
