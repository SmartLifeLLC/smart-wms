<?php

namespace App\Services\WarehouseTransfer;

use App\Enums\WarehouseTransferCandidateStatus;
use App\Models\Sakemaru\Location;
use App\Models\WmsWarehouseTransferCandidate;
use App\Models\WmsWarehouseTransferCandidateItem;
use App\Models\WmsWarehouseTransferCandidateItemSource;
use App\Models\WmsWarehouseTransferCandidateUpload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * HANDYから送信された倉庫移動データを候補として受信・集約する
 *
 * - upload_uuid（送信バッチ）単位で冪等
 * - request_uuid（行）単位で冪等
 * - 同一 from/to/process_date/delivered_date の未確定候補へ集約
 * - 実在庫は更新しない
 */
class WarehouseTransferCandidateReceiveService
{
    public function __construct(
        private readonly WarehouseTransferStockListService $stockListService,
    ) {}

    /**
     * @param  array{
     *   upload_uuid:string, device_id?:string|null, from_warehouse_id:int, to_warehouse_id:int,
     *   process_date:string, delivered_date:string, items:array<int, array<string, mixed>>
     * }  $payload
     * @return array{candidate: array<string, mixed>, accepted_count: int, missing_item_ids: array<int, int>, duplicated: bool}
     */
    public function receive(array $payload, ?int $pickerId = null): array
    {
        $uploadUuid = (string) $payload['upload_uuid'];

        // 1. 送信バッチ単位の冪等: 既存なら同じレスポンスを返す
        $existingUpload = WmsWarehouseTransferCandidateUpload::where('upload_uuid', $uploadUuid)->first();
        if ($existingUpload) {
            $candidate = WmsWarehouseTransferCandidate::find($existingUpload->candidate_id);

            return [
                'candidate' => $candidate ? $this->candidateSummary($candidate) : ($existingUpload->response_payload['candidate'] ?? null),
                'accepted_count' => (int) $existingUpload->accepted_count,
                'missing_item_ids' => $existingUpload->missing_item_ids ?? [],
                'duplicated' => true,
            ];
        }

        $fromWarehouse = $this->warehouse((int) $payload['from_warehouse_id']);
        $toWarehouse = $this->warehouse((int) $payload['to_warehouse_id']);

        if (! $fromWarehouse) {
            throw ValidationException::withMessages(['from_warehouse_id' => ['移動元倉庫が見つかりません']]);
        }
        if (! $toWarehouse) {
            throw ValidationException::withMessages(['to_warehouse_id' => ['移動先倉庫が見つかりません']]);
        }
        if ((int) $fromWarehouse->id === (int) $toWarehouse->id) {
            throw ValidationException::withMessages(['to_warehouse_id' => ['移動元と移動先は同一にできません']]);
        }

        return DB::connection('sakemaru')->transaction(function () use ($payload, $uploadUuid, $fromWarehouse, $toWarehouse, $pickerId) {
            $candidate = $this->findOrCreateOpenCandidate($payload, $fromWarehouse, $toWarehouse, $pickerId);

            $upload = WmsWarehouseTransferCandidateUpload::create([
                'candidate_id' => $candidate->id,
                'upload_uuid' => $uploadUuid,
                'device_id' => $payload['device_id'] ?? null,
                'picker_id' => $pickerId,
                'item_count' => count($payload['items']),
                'accepted_count' => 0,
                'payload_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE)),
            ]);

            $accepted = 0;
            $missingItemIds = [];

            foreach ($payload['items'] as $index => $row) {
                $result = $this->applyRow($candidate, $upload, $row, $index);

                if ($result === 'missing') {
                    $missingItemIds[] = (int) $row['item_id'];
                } elseif ($result === 'accepted') {
                    $accepted++;
                }
            }

            $candidate->refresh();

            $summary = $this->candidateSummary($candidate);

            $upload->update([
                'accepted_count' => $accepted,
                'missing_item_ids' => array_values(array_unique($missingItemIds)),
                'response_payload' => ['candidate' => $summary],
            ]);

            Log::info('Warehouse transfer candidate received from handy', [
                'candidate_id' => $candidate->id,
                'candidate_no' => $candidate->candidate_no,
                'upload_uuid' => $uploadUuid,
                'picker_id' => $pickerId,
                'device_id' => $payload['device_id'] ?? null,
                'requested' => count($payload['items']),
                'accepted' => $accepted,
                'missing_item_ids' => $missingItemIds,
            ]);

            return [
                'candidate' => $summary,
                'accepted_count' => $accepted,
                'missing_item_ids' => array_values(array_unique($missingItemIds)),
                'duplicated' => false,
            ];
        });
    }

    /**
     * Web手入力で候補ヘッダを新規作成
     */
    public function createFromWeb(array $data, ?int $userId = null): WmsWarehouseTransferCandidate
    {
        $fromWarehouse = $this->warehouse((int) $data['from_warehouse_id']);
        $toWarehouse = $this->warehouse((int) $data['to_warehouse_id']);

        if (! $fromWarehouse || ! $toWarehouse) {
            throw new RuntimeException('倉庫が見つかりません');
        }
        if ((int) $fromWarehouse->id === (int) $toWarehouse->id) {
            throw new RuntimeException('移動元と移動先は同一にできません');
        }

        return DB::connection('sakemaru')->transaction(function () use ($data, $fromWarehouse, $toWarehouse) {
            return WmsWarehouseTransferCandidate::create([
                'candidate_no' => $this->generateCandidateNo(),
                'client_id' => (int) config('app.client_id'),
                'source_type' => WmsWarehouseTransferCandidate::SOURCE_WEB,
                'from_warehouse_id' => $fromWarehouse->id,
                'from_warehouse_code' => $fromWarehouse->code,
                'from_warehouse_name' => $fromWarehouse->name,
                'to_warehouse_id' => $toWarehouse->id,
                'to_warehouse_code' => $toWarehouse->code,
                'to_warehouse_name' => $toWarehouse->name,
                'delivery_course_id' => $data['delivery_course_id'] ?? null,
                'process_date' => $data['process_date'],
                'delivered_date' => $data['delivered_date'],
                'status' => WarehouseTransferCandidateStatus::PENDING,
                'submitted_at' => now(),
                'memo' => $data['memo'] ?? null,
            ]);
        });
    }

    /**
     * Web画面から明細を追加/加算
     */
    public function addItemFromWeb(WmsWarehouseTransferCandidate $candidate, int $itemId, float $caseQuantity, float $pieceQuantity, ?int $packageQuantity = null, string $stockAllocationCode = '1', ?string $lineNote = null): WmsWarehouseTransferCandidateItem
    {
        if (! $candidate->isEditable()) {
            throw new RuntimeException('確定済みの候補には明細を追加できません');
        }

        $master = $this->stockListService->itemMaster($itemId);
        if (! $master) {
            throw new RuntimeException('商品が見つかりません');
        }

        $packageQuantity = max((int) ($packageQuantity ?? $master->capacity_case ?? 1), 1);
        $transferQuantity = WmsWarehouseTransferCandidateItem::calculateTransferQuantity($caseQuantity, $packageQuantity, $pieceQuantity);

        if ($transferQuantity <= 0) {
            throw new RuntimeException('数量を入力してください');
        }

        return DB::connection('sakemaru')->transaction(function () use ($candidate, $itemId, $master, $packageQuantity, $transferQuantity, $stockAllocationCode, $lineNote) {
            $item = $this->mergeItem($candidate, [
                'item_id' => $itemId,
                'item_code' => $master->code,
                'item_name' => $master->name,
                'barcode' => null,
                'real_stock_id' => null,
                'location_id' => null,
                'location_no' => null,
                'stock_allocation_code' => $stockAllocationCode,
                'package_quantity' => $packageQuantity,
                'transfer_quantity' => $transferQuantity,
                'available_quantity_at_sync' => null,
                'scanned_code' => null,
            ]);

            if ($lineNote !== null && $lineNote !== '') {
                $item->update(['line_note' => $lineNote]);
            }

            return $item;
        });
    }

    // ------------------------------------------------------------
    // Internal
    // ------------------------------------------------------------

    private function findOrCreateOpenCandidate(array $payload, object $fromWarehouse, object $toWarehouse, ?int $pickerId): WmsWarehouseTransferCandidate
    {
        $candidate = WmsWarehouseTransferCandidate::query()
            ->where('from_warehouse_id', $fromWarehouse->id)
            ->where('to_warehouse_id', $toWarehouse->id)
            ->whereDate('process_date', $payload['process_date'])
            ->whereDate('delivered_date', $payload['delivered_date'])
            ->where('status', WarehouseTransferCandidateStatus::PENDING->value)
            ->where('source_type', WmsWarehouseTransferCandidate::SOURCE_HANDY)
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if ($candidate) {
            return $candidate;
        }

        return WmsWarehouseTransferCandidate::create([
            'candidate_no' => $this->generateCandidateNo(),
            'client_id' => (int) config('app.client_id'),
            'source_type' => WmsWarehouseTransferCandidate::SOURCE_HANDY,
            'from_warehouse_id' => $fromWarehouse->id,
            'from_warehouse_code' => $fromWarehouse->code,
            'from_warehouse_name' => $fromWarehouse->name,
            'to_warehouse_id' => $toWarehouse->id,
            'to_warehouse_code' => $toWarehouse->code,
            'to_warehouse_name' => $toWarehouse->name,
            'delivery_course_id' => DB::connection('sakemaru')
                ->table('warehouse_stock_transfer_delivery_courses')
                ->where('from_warehouse_id', $fromWarehouse->id)
                ->where('to_warehouse_id', $toWarehouse->id)
                ->value('delivery_course_id'),
            'process_date' => $payload['process_date'],
            'delivered_date' => $payload['delivered_date'],
            'status' => WarehouseTransferCandidateStatus::PENDING,
            'submitted_by_picker_id' => $pickerId,
            'submitted_device_id' => $payload['device_id'] ?? null,
            'submitted_at' => now(),
        ]);
    }

    /**
     * @return 'accepted'|'duplicate'|'missing'
     */
    private function applyRow(WmsWarehouseTransferCandidate $candidate, WmsWarehouseTransferCandidateUpload $upload, array $row, int $index): string
    {
        $requestUuid = (string) $row['request_uuid'];

        // 行単位の冪等
        if (WmsWarehouseTransferCandidateItemSource::where('source_request_uuid', $requestUuid)->exists()) {
            return 'duplicate';
        }

        $itemId = (int) $row['item_id'];
        $master = $this->stockListService->itemMaster($itemId);

        if (! $master || ! (int) ($master->is_active ?? 1)) {
            return 'missing';
        }

        $stockAllocationCode = (string) (($row['stock_allocation_code'] ?? null) ?: '1');
        $packageQuantity = max((int) ($row['package_quantity'] ?? $master->capacity_case ?? 1), 1);
        $caseQuantity = (float) ($row['case_quantity'] ?? 0);
        $pieceQuantity = (float) ($row['piece_quantity'] ?? 0);
        $transferQuantity = array_key_exists('quantity', $row) && $row['quantity'] !== null
            ? (float) $row['quantity']
            : WmsWarehouseTransferCandidateItem::calculateTransferQuantity($caseQuantity, $packageQuantity, $pieceQuantity);

        if ($transferQuantity <= 0) {
            return 'missing';
        }

        $stock = $this->realStockInfo($candidate->from_warehouse_id, $itemId, isset($row['real_stock_id']) ? (int) $row['real_stock_id'] : null);

        $item = $this->mergeItem($candidate, [
            'item_id' => $itemId,
            'item_code' => (string) ($row['item_code'] ?? $master->code),
            'item_name' => $master->name,
            'barcode' => $stock?->barcode,
            'real_stock_id' => $stock?->real_stock_id,
            'location_id' => $stock?->location_id,
            'location_no' => $stock ? (Location::formatCode($stock->location_code1, $stock->location_code2, $stock->location_code3) ?: null) : null,
            'stock_allocation_code' => $stockAllocationCode,
            'package_quantity' => $packageQuantity,
            'transfer_quantity' => $transferQuantity,
            'available_quantity_at_sync' => $stock?->available_quantity,
            'scanned_code' => $row['search_code'] ?? null,
        ], $index);

        WmsWarehouseTransferCandidateItemSource::create([
            'candidate_id' => $candidate->id,
            'candidate_item_id' => $item->id,
            'upload_id' => $upload->id,
            'source_request_uuid' => $requestUuid,
            'real_stock_id' => $stock?->real_stock_id,
            'case_quantity' => $caseQuantity,
            'piece_quantity' => $pieceQuantity,
            'package_quantity' => $packageQuantity,
            'transfer_quantity' => $transferQuantity,
            'scanned_code' => $row['search_code'] ?? null,
        ]);

        return 'accepted';
    }

    /**
     * 同一候補内の同一 item_id + stock_allocation_code へ加算
     */
    private function mergeItem(WmsWarehouseTransferCandidate $candidate, array $data, int $index = 0): WmsWarehouseTransferCandidateItem
    {
        $item = WmsWarehouseTransferCandidateItem::query()
            ->where('candidate_id', $candidate->id)
            ->where('item_id', $data['item_id'])
            ->where('stock_allocation_code', $data['stock_allocation_code'])
            ->lockForUpdate()
            ->first();

        if ($item) {
            $newTotal = (float) $item->transfer_quantity + (float) $data['transfer_quantity'];
            $split = WmsWarehouseTransferCandidateItem::splitTransferQuantity($newTotal, (int) $item->package_quantity);

            $item->fill([
                'transfer_quantity' => $newTotal,
                'case_quantity' => $split['case_quantity'],
                'piece_quantity' => $split['piece_quantity'],
                'source_line_count' => (int) $item->source_line_count + 1,
                'real_stock_id' => $item->real_stock_id ?? $data['real_stock_id'],
                'location_id' => $item->location_id ?? $data['location_id'],
                'location_no' => $item->location_no ?? $data['location_no'],
                'barcode' => $item->barcode ?? $data['barcode'],
                'scanned_code' => $item->scanned_code ?? $data['scanned_code'],
                'available_quantity_at_sync' => $item->available_quantity_at_sync ?? $data['available_quantity_at_sync'],
            ])->save();

            return $item;
        }

        $split = WmsWarehouseTransferCandidateItem::splitTransferQuantity((float) $data['transfer_quantity'], (int) $data['package_quantity']);
        $maxSort = (int) WmsWarehouseTransferCandidateItem::where('candidate_id', $candidate->id)->max('sort_order');

        return WmsWarehouseTransferCandidateItem::create(array_merge($data, [
            'candidate_id' => $candidate->id,
            'case_quantity' => $split['case_quantity'],
            'piece_quantity' => $split['piece_quantity'],
            'source_line_count' => 1,
            'sort_order' => $maxSort + 1,
        ]));
    }

    private function realStockInfo(int $warehouseId, int $itemId, ?int $preferredRealStockId): ?object
    {
        $lotRanked = DB::raw(
            '(SELECT rsl.real_stock_id, rsl.location_id, rsl.floor_id, ROW_NUMBER() OVER (PARTITION BY rsl.real_stock_id ORDER BY rsl.updated_at DESC, rsl.id DESC) AS rn FROM real_stock_lots rsl WHERE rsl.status = \'ACTIVE\') as lot'
        );

        $query = DB::connection('sakemaru')
            ->table('real_stocks as rs')
            ->leftJoin($lotRanked, function ($join) {
                $join->on('lot.real_stock_id', '=', 'rs.id')->where('lot.rn', '=', 1);
            })
            ->leftJoin('locations as l', 'l.id', '=', 'lot.location_id')
            ->where('rs.warehouse_id', $warehouseId)
            ->where('rs.item_id', $itemId)
            ->select([
                'rs.id as real_stock_id',
                'l.id as location_id',
                'l.code1 as location_code1',
                'l.code2 as location_code2',
                'l.code3 as location_code3',
                DB::raw('(rs.current_quantity - COALESCE(rs.reserved_quantity, 0)) as available_quantity'),
                DB::raw("(SELECT isi.search_string FROM item_search_information isi WHERE isi.item_id = rs.item_id AND isi.code_type = 'JAN' AND isi.quantity_type = 'PIECE' AND isi.is_active = 1 ORDER BY isi.priority IS NULL, isi.priority, isi.id LIMIT 1) as barcode"),
            ]);

        if ($preferredRealStockId) {
            $query->orderByRaw('rs.id = ? DESC', [$preferredRealStockId]);
        }

        return $query->orderBy('rs.id')->first();
    }

    private function warehouse(int $warehouseId): ?object
    {
        return DB::connection('sakemaru')
            ->table('warehouses')
            ->where('id', $warehouseId)
            ->first(['id', 'code', 'name']);
    }

    /**
     * 候補番号: WT{YYYYMMDD}{4桁連番}
     */
    public function generateCandidateNo(): string
    {
        $prefix = 'WT'.now()->format('Ymd');

        $last = WmsWarehouseTransferCandidate::query()
            ->where('candidate_no', 'like', "{$prefix}%")
            ->lockForUpdate()
            ->orderByDesc('candidate_no')
            ->value('candidate_no');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public function candidateSummary(WmsWarehouseTransferCandidate $candidate): array
    {
        $stats = WmsWarehouseTransferCandidateItem::query()
            ->where('candidate_id', $candidate->id)
            ->selectRaw('COUNT(*) as item_count, COALESCE(SUM(transfer_quantity), 0) as total_quantity')
            ->first();

        return [
            'id' => $candidate->id,
            'candidate_no' => $candidate->candidate_no,
            'status' => $candidate->status?->value,
            'status_label' => $candidate->status?->label(),
            'from_warehouse_id' => (int) $candidate->from_warehouse_id,
            'from_warehouse_code' => $candidate->from_warehouse_code,
            'from_warehouse_name' => $candidate->from_warehouse_name,
            'to_warehouse_id' => (int) $candidate->to_warehouse_id,
            'to_warehouse_code' => $candidate->to_warehouse_code,
            'to_warehouse_name' => $candidate->to_warehouse_name,
            'process_date' => $candidate->process_date?->format('Y-m-d'),
            'delivered_date' => $candidate->delivered_date?->format('Y-m-d'),
            'item_count' => (int) ($stats->item_count ?? 0),
            'total_quantity' => (float) ($stats->total_quantity ?? 0),
        ];
    }
}
