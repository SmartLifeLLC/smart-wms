<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\CalculationType;
use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\JobProcessName;
use App\Enums\AutoOrder\LotStatus;
use App\Enums\AutoOrder\OrderChannel;
use App\Enums\AutoOrder\OrderDataFileChannel;
use App\Enums\AutoOrder\OrderEntrySource;
use App\Enums\AutoOrder\OriginType;
use App\Enums\AutoOrder\SettlementStatus;
use App\Enums\QuantityType;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\ItemContractor;
use App\Models\WmsAutoOrderJobControl;
use App\Models\WmsOrderCalculationLog;
use App\Models\WmsOrderCandidate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class OrderRegistrationService
{
    public function __construct(
        private readonly OrderExecutionService $orderExecutionService,
        private readonly OrderDataFileService $orderDataFileService,
        private readonly OrderRegistrationSearchService $searchService,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{batch_code: string, candidate_ids: array<int>, incoming_schedule_count: int, data_file_result: array<string, mixed>}
     */
    public function register(
        int $warehouseId,
        array $lines,
        int $userId,
        ?OrderChannel $fallbackChannel = null,
        ?string $communicationNotes = null
    ): array {
        if ($warehouseId < 1) {
            throw new \InvalidArgumentException('倉庫を選択してください。');
        }

        if ($lines === []) {
            throw new \InvalidArgumentException('登録する発注明細がありません。');
        }

        $entrySources = collect($lines)
            ->pluck('entry_source')
            ->filter()
            ->unique()
            ->values()
            ->all();
        $jobEntrySource = count($entrySources) === 1
            ? (string) $entrySources[0]
            : 'MIXED';
        $lineChannels = collect($lines)
            ->map(fn (array $line): string => $this->resolveLineChannel($line, $fallbackChannel)->value)
            ->unique()
            ->values()
            ->all();
        $jobOrderChannel = count($lineChannels) === 1
            ? (string) $lineChannels[0]
            : 'MIXED';
        $candidateIds = [];
        $candidateIdsByChannel = [];
        $incomingScheduleCount = 0;

        $batchCode = DB::connection('sakemaru')->transaction(function () use (
            $warehouseId,
            $fallbackChannel,
            $jobOrderChannel,
            $jobEntrySource,
            $lines,
            $userId,
            &$candidateIds,
            &$candidateIdsByChannel,
            &$incomingScheduleCount
        ): string {
            $job = WmsAutoOrderJobControl::startJob(
                processName: JobProcessName::ORDER_CALC,
                scope: [
                    'source' => 'new_external_order',
                    'order_channel' => $jobOrderChannel,
                    'entry_source' => $jobEntrySource,
                ],
                batchCode: WmsAutoOrderJobControl::generateBatchCode($warehouseId),
                settlementStatus: SettlementStatus::CONFIRMED,
                createdBy: $userId,
                warehouseId: $warehouseId,
            );

            foreach ($lines as $index => $line) {
                $lineWarehouseId = max(1, (int) ($line['warehouse_id'] ?? $warehouseId));
                $lineIncomingWarehouseId = $this->searchService->incomingWarehouseId($lineWarehouseId);
                $lineChannel = $this->resolveLineChannel($line, $fallbackChannel);
                $candidate = $this->createApprovedCandidate(
                    batchCode: $job->batch_code,
                    warehouseId: $lineWarehouseId,
                    incomingWarehouseId: $lineIncomingWarehouseId,
                    channel: $lineChannel,
                    entrySource: OrderEntrySource::tryFrom((string) ($line['entry_source'] ?? '')) ?? OrderEntrySource::SEARCH,
                    expectedArrivalDate: $line['expected_arrival_date'] ?? null,
                    line: $line,
                    userId: $userId,
                    lineNumber: $index + 1,
                );

                $schedules = $this->orderExecutionService->confirmCandidate($candidate, $userId);
                $candidateIds[] = (int) $candidate->id;
                $candidateIdsByChannel[$lineChannel->value][] = (int) $candidate->id;
                $incomingScheduleCount += $schedules->count();
            }

            $job->markAsSuccess(count($candidateIds), [
                'source' => 'new_external_order',
                'order_channel' => $jobOrderChannel,
                'entry_source' => $jobEntrySource,
                'candidate_ids' => $candidateIds,
                'incoming_schedule_count' => $incomingScheduleCount,
            ]);

            return $job->batch_code;
        }, 3);

        $dataFileResult = $this->generateDataFilesByChannel($candidateIdsByChannel, $communicationNotes);

        return [
            'batch_code' => $batchCode,
            'candidate_ids' => $candidateIds,
            'incoming_schedule_count' => $incomingScheduleCount,
            'data_file_result' => $dataFileResult,
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function createApprovedCandidate(
        string $batchCode,
        int $warehouseId,
        int $incomingWarehouseId,
        OrderChannel $channel,
        OrderEntrySource $entrySource,
        ?string $expectedArrivalDate,
        array $line,
        int $userId,
        int $lineNumber
    ): WmsOrderCandidate {
        $itemId = (int) ($line['item_id'] ?? 0);
        $contractorId = (int) ($line['contractor_id'] ?? 0);
        $supplierId = (int) ($line['supplier_id'] ?? 0);
        $orderQuantity = (int) ($line['order_quantity'] ?? 0);
        $quantityType = QuantityType::tryFrom((string) ($line['quantity_type'] ?? QuantityType::PIECE->value));

        if ($itemId < 1 || $contractorId < 1 || $orderQuantity <= 0 || ! $quantityType) {
            throw new \InvalidArgumentException("{$lineNumber}行目の発注明細が不正です。");
        }

        if ($channel === OrderChannel::EOS && ! $this->searchService->isJxContractor($contractorId)) {
            throw new \InvalidArgumentException("{$lineNumber}行目はEOS発注対象外の発注先です。");
        }

        if (blank($expectedArrivalDate)) {
            throw new \InvalidArgumentException("{$lineNumber}行目の入荷予定日を入力してください。");
        }

        $expectedArrivalDate = Carbon::parse($expectedArrivalDate)->startOfDay();
        if ($expectedArrivalDate->lt(Carbon::today())) {
            throw new \InvalidArgumentException("{$lineNumber}行目の入荷予定日は本日以降を選択してください。");
        }

        $item = Item::with('current_price')->find($itemId);
        if (! $item || $item->end_of_sale_type !== 'NORMAL' || (bool) $item->is_ended) {
            throw new \InvalidArgumentException("{$lineNumber}行目の商品は発注できません。");
        }

        $itemContractor = ItemContractor::query()
            ->where('warehouse_id', $incomingWarehouseId)
            ->where('item_id', $itemId)
            ->where('contractor_id', $contractorId)
            ->when($supplierId > 0, fn ($query) => $query->where('supplier_id', $supplierId))
            ->first();

        $settingsItemContractor = $itemContractor;
        $itemContractorWarehouseId = (int) ($line['item_contractor_warehouse_id'] ?? 0);
        if (! $itemContractor && $itemContractorWarehouseId > 0 && $itemContractorWarehouseId !== $incomingWarehouseId) {
            $itemContractor = ItemContractor::query()
                ->where('warehouse_id', $itemContractorWarehouseId)
                ->where('item_id', $itemId)
                ->where('contractor_id', $contractorId)
                ->when($supplierId > 0, fn ($query) => $query->where('supplier_id', $supplierId))
                ->first();

            $settingsItemContractor = $itemContractor;
        }

        if (! $settingsItemContractor && $supplierId > 0) {
            $settingsItemContractor = ItemContractor::query()
                ->where('warehouse_id', $incomingWarehouseId)
                ->where('item_id', $itemId)
                ->where('contractor_id', $contractorId)
                ->first();
        }
        if (! $settingsItemContractor && $supplierId > 0 && $itemContractorWarehouseId > 0 && $itemContractorWarehouseId !== $incomingWarehouseId) {
            $settingsItemContractor = ItemContractor::query()
                ->where('warehouse_id', $itemContractorWarehouseId)
                ->where('item_id', $itemId)
                ->where('contractor_id', $contractorId)
                ->first();
        }
        if (! $itemContractor && $settingsItemContractor) {
            $itemContractor = $settingsItemContractor;
        }

        if (! $itemContractor && $channel === OrderChannel::EOS) {
            throw new \InvalidArgumentException("{$lineNumber}行目の商品は選択した発注先に紐づいていないためEOS発注できません。FAX発注に変更してください。");
        }

        if (! $itemContractor && $supplierId <= 0) {
            throw new \InvalidArgumentException("{$lineNumber}行目の商品に発注先設定がありません。");
        }

        if (! $itemContractor && ! $this->supplierBelongsToContractor($supplierId, $contractorId)) {
            throw new \InvalidArgumentException("{$lineNumber}行目の発注先に有効な仕入先が設定されていません。発注先を変更してください。");
        }

        if ($itemContractor) {
            $supplierId = (int) ($itemContractor->supplier_id ?? 0) ?: $this->defaultSupplierIdForContractor($contractorId) ?: $supplierId;
        }

        $purchaseUnit = max(1, (int) ($line['purchase_unit'] ?? $settingsItemContractor?->purchase_unit ?? 1));
        $safetyStock = (int) ($settingsItemContractor?->safety_stock ?? 0);
        $expectedArrivalDate = $expectedArrivalDate->toDateString();
        $searchCode = $line['search_code'] ?? $this->searchService->searchCodeForItem($itemId);
        $orderingCode = $line['ordering_code'] ?? $this->searchService->orderingCodeForItem($itemId);
        $currentStock = $this->searchService->availableStock($warehouseId, $itemId);
        $incomingQuantity = $this->searchService->incomingQuantity($incomingWarehouseId, $itemId);
        $linePurchaseUnitPrice = $line['purchase_unit_price'] ?? null;
        $purchaseUnitPrice = is_numeric($linePurchaseUnitPrice)
            ? (float) $linePurchaseUnitPrice
            : ($quantityType === QuantityType::CASE
                ? $item->current_price?->purchase_case_price
                : $item->current_price?->purchase_unit_price);

        $candidate = WmsOrderCandidate::create([
            'batch_code' => $batchCode,
            'warehouse_id' => $warehouseId,
            'item_id' => $itemId,
            'item_code' => $item->code,
            'search_code' => $searchCode,
            'ordering_code' => $orderingCode,
            'contractor_id' => $contractorId,
            'supplier_id' => $supplierId > 0 ? $supplierId : null,
            'purchase_unit_price' => $purchaseUnitPrice,
            'current_effective_stock' => $currentStock,
            'incoming_quantity' => $incomingQuantity,
            'safety_stock' => $safetyStock,
            'self_shortage_qty' => (int) ($line['sales_qty'] ?? 0),
            'satellite_demand_qty' => 0,
            'suggested_quantity' => (int) ($line['suggested_quantity'] ?? $orderQuantity),
            'order_quantity' => $orderQuantity,
            'quantity_type' => $quantityType,
            'calculated_shortage_qty' => (int) ($line['calculated_shortage_qty'] ?? $orderQuantity),
            'purchase_unit' => $purchaseUnit,
            'expected_arrival_date' => $expectedArrivalDate,
            'original_arrival_date' => $expectedArrivalDate,
            'status' => CandidateStatus::APPROVED,
            'lot_status' => LotStatus::RAW,
            'origin_type' => $entrySource === OrderEntrySource::SALES_HISTORY
                ? OriginType::MANUAL_SALES_BASED
                : OriginType::USER,
            'order_channel' => $channel,
            'entry_source' => $entrySource,
            'is_manually_modified' => true,
            'modified_by' => $userId,
            'modified_at' => now(),
        ]);

        WmsOrderCalculationLog::create([
            'batch_code' => $batchCode,
            'warehouse_id' => $warehouseId,
            'item_id' => $itemId,
            'calculation_type' => CalculationType::EXTERNAL,
            'contractor_id' => $contractorId,
            'source_warehouse_id' => null,
            'current_effective_stock' => $currentStock,
            'incoming_quantity' => $incomingQuantity,
            'safety_stock_setting' => $safetyStock,
            'lead_time_days' => 0,
            'calculated_shortage_qty' => (int) ($line['calculated_shortage_qty'] ?? $orderQuantity),
            'calculated_order_quantity' => $orderQuantity,
            'calculation_details' => [
                'source' => 'new_external_order',
                'order_channel' => $channel->value,
                'entry_source' => $entrySource->value,
                'expected_arrival_date' => $expectedArrivalDate,
                'supplier_id' => $supplierId > 0 ? $supplierId : null,
                'supplier_partner_id' => isset($line['supplier_partner_id']) && (int) $line['supplier_partner_id'] > 0
                    ? (int) $line['supplier_partner_id']
                    : null,
                'purchase_unit_price_source' => $line['purchase_unit_price_source'] ?? null,
                'is_item_contractor_linked' => $itemContractor !== null,
                'sales_qty' => (int) ($line['sales_qty'] ?? 0),
                'created_by' => $userId,
                'created_at' => now()->toDateTimeString(),
            ],
        ]);

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function resolveLineChannel(array $line, ?OrderChannel $fallbackChannel): OrderChannel
    {
        return OrderChannel::tryFrom((string) ($line['order_channel'] ?? ''))
            ?? $fallbackChannel
            ?? OrderChannel::FAX;
    }

    private function supplierBelongsToContractor(int $supplierId, int $contractorId): bool
    {
        if ($supplierId < 1 || $contractorId < 1) {
            return false;
        }

        return DB::connection('sakemaru')
            ->table('wms_contractor_suppliers')
            ->where('supplier_id', $supplierId)
            ->where('contractor_id', $contractorId)
            ->exists()
            || DB::connection('sakemaru')
                ->table('contractors')
                ->where('id', $contractorId)
                ->where('supplier_id', $supplierId)
                ->exists();
    }

    private function defaultSupplierIdForContractor(int $contractorId): int
    {
        if ($contractorId < 1) {
            return 0;
        }

        return (int) (DB::connection('sakemaru')
            ->table('contractors')
            ->where('id', $contractorId)
            ->value('supplier_id') ?? 0);
    }

    /**
     * @param  array<string, array<int>>  $candidateIdsByChannel
     * @return array{success: bool, files: array, total_files: int, errors: array}
     */
    private function generateDataFilesByChannel(array $candidateIdsByChannel, ?string $communicationNotes = null): array
    {
        $files = [];
        $errors = [];

        foreach ($candidateIdsByChannel as $channelValue => $candidateIds) {
            $channel = OrderChannel::tryFrom((string) $channelValue);
            if (! $channel || $candidateIds === []) {
                continue;
            }

            $result = $this->orderDataFileService->generateFaxPdfFilesForCandidates(
                $candidateIds,
                $channel === OrderChannel::EOS ? OrderDataFileChannel::EOS : OrderDataFileChannel::FAX,
                splitByWarehouse: true,
                communicationNotes: $communicationNotes,
            );

            $files = array_merge($files, $result['files'] ?? []);
            $errors = array_merge($errors, $result['errors'] ?? []);
        }

        return [
            'success' => $errors === [],
            'files' => $files,
            'total_files' => count($files),
            'errors' => $errors,
        ];
    }
}
