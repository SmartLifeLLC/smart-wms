<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\AutoOrder\OrderSource;
use App\Enums\QuantityType;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Item;
use App\Models\WmsOrderCandidate;
use App\Models\WmsOrderIncomingSchedule;
use App\Models\WmsOrderSlipNumberAssignment;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 発注確定サービス
 *
 * 発注候補を確定（CONFIRMED）し、入庫予定を作成する
 * demand_breakdownがある場合は、各倉庫ごとに入庫予定を作成
 * 送信時にEXECUTEDに変更する
 */
class OrderExecutionService
{
    private array $supplierPartnerIdCache = [];

    public function __construct(
        private readonly OrderAuditService $auditService,
        private readonly PurchasePriceService $purchasePriceService = new PurchasePriceService,
    ) {}

    /**
     * 発注候補を確定し、入庫予定を作成（何回でも実行可能）
     *
     * 既存の入庫予定があれば削除して再作成する
     * ステータスはCONFIRMEDに変更（APPROVEDまたはCONFIRMEDから）
     *
     * @param  WmsOrderCandidate  $candidate  発注候補
     * @param  int  $confirmedBy  確定者ID
     * @return Collection<WmsOrderIncomingSchedule> 作成された入庫予定のコレクション
     */
    public function confirmCandidate(WmsOrderCandidate $candidate, int $confirmedBy): Collection
    {
        if (! $candidate->status->canConfirm()) {
            throw new \RuntimeException(
                "Candidate {$candidate->id} cannot be confirmed. Current status: {$candidate->status->value}"
            );
        }

        return DB::connection('sakemaru')->transaction(function () use ($candidate, $confirmedBy) {
            if ($candidate->status === CandidateStatus::CONFIRMED && $this->hasJxSlipNumberAssignment($candidate)) {
                $existingSchedules = WmsOrderIncomingSchedule::query()
                    ->where('order_candidate_id', $candidate->id)
                    ->get();

                if ($existingSchedules->isNotEmpty()) {
                    Log::info('Skip reconfirming JX generated order candidate', [
                        'candidate_id' => $candidate->id,
                        'wms_order_jx_document_id' => $candidate->wms_order_jx_document_id,
                    ]);

                    return $existingSchedules;
                }
            }

            if ($candidate->status === CandidateStatus::APPROVED && (int) $candidate->order_quantity <= 0) {
                $candidate->delete();

                return collect();
            }

            // 1. 既存の入庫予定を削除（PENDING状態のもののみ）
            $deletedCount = WmsOrderIncomingSchedule::where('order_candidate_id', $candidate->id)
                ->where('status', IncomingScheduleStatus::PENDING)
                ->delete();

            // Existing incoming schedules deleted if any ($deletedCount)

            // 2. 発注候補のステータスを更新
            $candidate->update([
                'status' => CandidateStatus::CONFIRMED,
                'modified_by' => $confirmedBy,
                'modified_at' => now(),
            ]);

            // 3. 監査ログ
            $this->auditService->logConfirmation($candidate);

            // 4. 入庫予定を作成（demand_breakdownの有無で分岐）
            $incomingSchedules = $this->createIncomingSchedulesFromCandidate($candidate);

            return $incomingSchedules;
        });
    }

    /**
     * バッチ単位で発注候補を確定（入庫予定作成）
     *
     * @param  string  $batchCode  バッチコード
     * @param  int  $confirmedBy  確定者ID
     * @return Collection<WmsOrderIncomingSchedule> 作成された入庫予定のコレクション
     */
    public function confirmBatch(string $batchCode, int $confirmedBy, ?int $warehouseId = null): Collection
    {
        $zeroQuantityQuery = WmsOrderCandidate::where('batch_code', $batchCode)
            ->where('status', CandidateStatus::APPROVED)
            ->where('order_quantity', '<=', 0);

        if ($warehouseId !== null) {
            $zeroQuantityQuery->where('warehouse_id', $warehouseId);
        }

        $zeroQuantityQuery->delete();

        $query = WmsOrderCandidate::where('batch_code', $batchCode)
            ->whereIn('status', [CandidateStatus::APPROVED, CandidateStatus::CONFIRMED])
            ->where('order_quantity', '>', 0);

        if ($warehouseId !== null) {
            $query->where('warehouse_id', $warehouseId);
        }

        $candidates = $query->get();

        if ($candidates->isEmpty()) {
            return collect();
        }

        $incomingSchedules = collect();
        $confirmedCount = 0;

        foreach ($candidates as $candidate) {
            try {
                $schedules = $this->confirmCandidate($candidate, $confirmedBy);
                $incomingSchedules = $incomingSchedules->merge($schedules);
                $confirmedCount++;
            } catch (\Exception $e) {
                Log::error('Failed to confirm candidate', [
                    'candidate_id' => $candidate->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $incomingSchedules;
    }

    /**
     * 発注候補を送信済みに変更（入庫予定を作成）
     *
     * @param  WmsOrderCandidate  $candidate  発注候補
     * @param  int  $executedBy  確定者ID
     * @return Collection<WmsOrderIncomingSchedule> 作成された入庫予定のコレクション
     *
     * @deprecated Use confirmCandidate() instead for confirmation, this is for transmission only
     */
    public function executeCandidate(WmsOrderCandidate $candidate, int $executedBy): Collection
    {
        if (! in_array($candidate->status, [CandidateStatus::APPROVED, CandidateStatus::CONFIRMED])) {
            throw new \RuntimeException(
                "Candidate {$candidate->id} must be APPROVED or CONFIRMED before execution. Current status: {$candidate->status->value}"
            );
        }

        return DB::connection('sakemaru')->transaction(function () use ($candidate, $executedBy) {
            // 1. 発注候補のステータスを更新
            $candidate->update([
                'status' => CandidateStatus::EXECUTED,
                'modified_by' => $executedBy,
                'modified_at' => now(),
            ]);

            // 2. 入庫予定を作成（demand_breakdownの有無で分岐）
            $incomingSchedules = $this->createIncomingSchedulesFromCandidate($candidate);

            return $incomingSchedules;
        });
    }

    /**
     * 発注候補から入庫予定を作成
     *
     * demand_breakdownがある場合は各倉庫ごとに作成
     * ない場合は従来通り発注元倉庫に一括作成
     *
     * @param  WmsOrderCandidate  $candidate  発注候補
     * @return Collection<WmsOrderIncomingSchedule>
     */
    private function createIncomingSchedulesFromCandidate(WmsOrderCandidate $candidate): Collection
    {
        $incomingSchedules = collect();
        $supplierId = $this->getSupplierIdFromCandidate($candidate);
        $supplierPartnerId = $this->getSupplierPartnerId($supplierId);
        $searchCode = $this->getSearchCodeForItem($candidate->item_id);
        $expirationDate = $this->calculateExpirationDate($candidate->item_id, $candidate->expected_arrival_date);
        $prices = $this->purchasePriceService->getPrice(
            $candidate->item_id,
            $supplierPartnerId,
            $candidate->warehouse_id,
            now()->toDateString()
        );

        // demand_breakdownがある場合は各倉庫ごとに入庫予定を作成
        // 注意: demand_breakdownのquantityは単位調整前の不足数なので、
        //       order_quantity（単位調整後）との比率で按分する
        [$incomingExpectedQuantity, $incomingQuantityType] = $this->resolveIncomingQuantity($candidate);

        if (! empty($candidate->demand_breakdown)) {
            $breakdowns = collect($candidate->demand_breakdown)->filter(fn ($b) => ($b['quantity'] ?? 0) > 0);
            $breakdownTotal = $breakdowns->sum('quantity');
            $orderQuantity = $incomingExpectedQuantity;

            // 按分して端数は最後の倉庫に寄せる
            $allocated = 0;
            $lastIndex = $breakdowns->count() - 1;

            foreach ($breakdowns->values() as $index => $breakdown) {
                $warehouseId = $breakdown['warehouse_id'];

                if ($index === $lastIndex) {
                    // 最後の倉庫に残りを割り当て
                    $quantity = $orderQuantity - $allocated;
                } else {
                    $quantity = $breakdownTotal > 0
                        ? (int) round($breakdown['quantity'] / $breakdownTotal * $orderQuantity)
                        : 0;
                    $allocated += $quantity;
                }

                if ($quantity <= 0) {
                    continue;
                }

                $orderDate = ClientSetting::freshSystemDateYMD('order_incoming_schedule:auto:demand_breakdown');
                $slipNumber = $this->resolveIncomingSlipNumber($candidate, $orderDate);
                $schedule = WmsOrderIncomingSchedule::create([
                    'warehouse_id' => $warehouseId,
                    'item_id' => $candidate->item_id,
                    'item_code' => $candidate->item_code,
                    'search_code' => $searchCode,
                    'contractor_id' => $candidate->contractor_id,
                    'supplier_id' => $supplierId,
                    'order_candidate_id' => $candidate->id,
                    'order_source' => OrderSource::AUTO,
                    'order_channel' => $candidate->order_channel,
                    'slip_number' => $slipNumber,
                    'expected_quantity' => $quantity,
                    'received_quantity' => 0,
                    'quantity_type' => $incomingQuantityType,
                    'price_type' => $incomingQuantityType === QuantityType::CASE ? 'CASE' : 'PIECE',
                    'order_date' => $orderDate,
                    'expected_arrival_date' => $candidate->expected_arrival_date,
                    'expiration_date' => $expirationDate,
                    'status' => IncomingScheduleStatus::PENDING,
                    'unit_price' => $prices['unit_price'],
                    'case_price' => $prices['case_price'],
                ]);

                $incomingSchedules->push($schedule);
            }
        } else {
            // demand_breakdownがない場合は従来通り発注元倉庫に入庫予定を作成
            $orderDate = ClientSetting::freshSystemDateYMD('order_incoming_schedule:auto');
            $slipNumber = $this->resolveIncomingSlipNumber($candidate, $orderDate);
            $schedule = WmsOrderIncomingSchedule::create([
                'warehouse_id' => $candidate->warehouse_id,
                'item_id' => $candidate->item_id,
                'item_code' => $candidate->item_code,
                'search_code' => $searchCode,
                'contractor_id' => $candidate->contractor_id,
                'supplier_id' => $supplierId,
                'order_candidate_id' => $candidate->id,
                'order_source' => OrderSource::AUTO,
                'order_channel' => $candidate->order_channel,
                'slip_number' => $slipNumber,
                'expected_quantity' => $incomingExpectedQuantity,
                'received_quantity' => 0,
                'quantity_type' => $incomingQuantityType,
                'price_type' => $incomingQuantityType === QuantityType::CASE ? 'CASE' : 'PIECE',
                'order_date' => $orderDate,
                'expected_arrival_date' => $candidate->expected_arrival_date,
                'expiration_date' => $expirationDate,
                'status' => IncomingScheduleStatus::PENDING,
                'unit_price' => $prices['unit_price'],
                'case_price' => $prices['case_price'],
            ]);

            $incomingSchedules->push($schedule);
        }

        return $incomingSchedules;
    }

    private function resolveIncomingSlipNumber(WmsOrderCandidate $candidate, string $orderDate): string
    {
        return $this->resolveAssignedSlipNumber($candidate)
            ?? WmsOrderIncomingSchedule::generateSlipNumber($orderDate);
    }

    private function hasJxSlipNumberAssignment(WmsOrderCandidate $candidate): bool
    {
        return filled($candidate->wms_order_jx_document_id)
            || $this->resolveAssignedSlipNumber($candidate) !== null;
    }

    private function resolveAssignedSlipNumber(WmsOrderCandidate $candidate): ?string
    {
        if (! $candidate->id) {
            return null;
        }

        $slipNumber = WmsOrderSlipNumberAssignment::query()
            ->whereIn('status', [
                WmsOrderSlipNumberAssignment::STATUS_ACTIVE,
                WmsOrderSlipNumberAssignment::STATUS_TRANSMITTED,
            ])
            ->whereJsonContains('order_candidate_ids', (int) $candidate->id)
            ->latest('id')
            ->value('slip_number');

        $slipNumber = trim((string) $slipNumber);

        return $slipNumber !== '' ? $slipNumber : null;
    }

    /**
     * 入荷予定は候補の数量・単位をそのまま保持する。
     *
     * @return array{0: int, 1: QuantityType}
     */
    private function resolveIncomingQuantity(WmsOrderCandidate $candidate): array
    {
        return [(int) $candidate->order_quantity, $candidate->quantity_type];
    }

    /**
     * バッチ単位で発注候補を確定
     *
     * @param  string  $batchCode  バッチコード
     * @param  int  $executedBy  確定者ID
     * @return Collection<WmsOrderIncomingSchedule> 作成された入庫予定のコレクション
     */
    public function executeBatch(string $batchCode, int $executedBy): Collection
    {
        $candidates = WmsOrderCandidate::where('batch_code', $batchCode)
            ->where('status', CandidateStatus::APPROVED)
            ->get();

        if ($candidates->isEmpty()) {
            return collect();
        }

        $incomingSchedules = collect();
        $executedCandidateCount = 0;

        foreach ($candidates as $candidate) {
            try {
                $schedules = $this->executeCandidate($candidate, $executedBy);
                $incomingSchedules = $incomingSchedules->merge($schedules);
                $executedCandidateCount++;
            } catch (\Exception $e) {
                Log::error('Failed to execute candidate', [
                    'candidate_id' => $candidate->id,
                    'error' => $e->getMessage(),
                ]);
                // 個別のエラーはスキップして続行
            }
        }

        return $incomingSchedules;
    }

    /**
     * 手動発注から入庫予定を作成
     *
     * @param  array  $data  発注データ
     * @param  int  $createdBy  作成者ID
     */
    public function createManualIncomingSchedule(array $data, int $createdBy): WmsOrderIncomingSchedule
    {
        $searchCode = $this->getSearchCodeForItem($data['item_id']);
        $expirationDate = $data['expiration_date']
            ?? $this->calculateExpirationDate($data['item_id'], $data['expected_arrival_date']);

        $orderDate = $data['order_date'] ?? now()->format('Y-m-d');
        $supplierId = $data['supplier_id'] ?? null;
        $supplierPartnerId = $this->getSupplierPartnerId($supplierId ? (int) $supplierId : null);
        $prices = $this->purchasePriceService->getPrice(
            $data['item_id'],
            $supplierPartnerId,
            $data['warehouse_id'],
            $orderDate
        );

        $itemCode = Item::where('id', $data['item_id'])->value('code');

        $incomingSchedule = WmsOrderIncomingSchedule::create([
            'warehouse_id' => $data['warehouse_id'],
            'item_id' => $data['item_id'],
            'item_code' => $itemCode,
            'search_code' => $searchCode,
            'contractor_id' => $data['contractor_id'],
            'supplier_id' => $supplierId,
            'manual_order_number' => $data['order_number'] ?? null,
            'order_source' => OrderSource::MANUAL,
            'slip_number' => WmsOrderIncomingSchedule::generateSlipNumber($orderDate),
            'expected_quantity' => $data['expected_quantity'],
            'received_quantity' => 0,
            'quantity_type' => $data['quantity_type'] ?? 'PIECE',
            'order_date' => $orderDate,
            'expected_arrival_date' => $data['expected_arrival_date'],
            'expiration_date' => $expirationDate,
            'status' => IncomingScheduleStatus::PENDING,
            'unit_price' => $prices['unit_price'],
            'case_price' => $prices['case_price'],
            'note' => $data['note'] ?? null,
        ]);

        return $incomingSchedule;
    }

    /**
     * 発注候補から仕入先IDを取得
     */
    private function getSupplierIdFromCandidate(WmsOrderCandidate $candidate): ?int
    {
        if ($candidate->supplier_id) {
            return (int) $candidate->supplier_id;
        }

        $itemContractor = DB::connection('sakemaru')
            ->table('item_contractors')
            ->where('warehouse_id', $candidate->warehouse_id)
            ->where('item_id', $candidate->item_id)
            ->where('contractor_id', $candidate->contractor_id)
            ->first();

        return $itemContractor?->supplier_id;
    }

    /**
     * PurchasePriceService は suppliers.id ではなく suppliers.partner_id を要求する。
     */
    private function getSupplierPartnerId(?int $supplierId): ?int
    {
        if (! $supplierId) {
            return null;
        }

        if (! array_key_exists($supplierId, $this->supplierPartnerIdCache)) {
            $partnerId = DB::connection('sakemaru')
                ->table('suppliers')
                ->where('id', $supplierId)
                ->value('partner_id');

            $this->supplierPartnerIdCache[$supplierId] = $partnerId ? (int) $partnerId : null;
        }

        return $this->supplierPartnerIdCache[$supplierId];
    }

    /**
     * 商品IDから検索コードを取得
     *
     * item_search_information.search_string をカンマ区切りで連結
     */
    private function getSearchCodeForItem(int $itemId): ?string
    {
        // 発注用コード（is_used_for_ordering=true）のみを取得
        return DB::connection('sakemaru')
            ->table('item_search_information')
            ->where('item_id', $itemId)
            ->where('is_used_for_ordering', true)
            ->where('is_active', true)
            ->value('search_string');
    }

    /**
     * 商品の賞味期限を計算
     *
     * 商品マスタの default_expiration_days から計算
     * 設定がない場合は null を返す
     *
     * @param  int  $itemId  商品ID
     * @param  string|Carbon  $baseDate  基準日（入荷予定日）
     * @return string|null 賞味期限（Y-m-d形式）
     */
    private function calculateExpirationDate(int $itemId, string|Carbon $baseDate): ?string
    {
        $item = Item::find($itemId);

        if (! $item || ! $item->default_expiration_days || $item->default_expiration_days <= 0) {
            return null;
        }

        return $this->calculateExpirationDateFromDays($baseDate, (int) $item->default_expiration_days);
    }

    private function calculateExpirationDateFromDays(string|Carbon $baseDate, int $days): string
    {
        $base = $baseDate instanceof Carbon ? $baseDate->copy() : Carbon::parse($baseDate);

        return $base->addDays($days)->format('Y-m-d');
    }
}
