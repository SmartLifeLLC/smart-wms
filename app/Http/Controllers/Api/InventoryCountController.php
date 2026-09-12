<?php

namespace App\Http\Controllers\Api;

use App\Enums\EVolumeUnit;
use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use App\Models\WmsInventoryCountItemLog;
use App\Models\WmsInventoryCountRescueData;
use App\Services\InventoryCount\InventoryCountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class InventoryCountController extends ApiController
{
    public function __construct(
        private readonly InventoryCountService $inventoryCountService
    ) {}

    /**
     * GET /api/wms/inventory-counts
     *
     * List active inventory count instructions for Handy.
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'warehouse_id' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $counts = WmsInventoryCount::where('warehouse_id', (int) $request->input('warehouse_id'))
            ->where('handy_reception', true)
            ->whereIn('status', [
                WmsInventoryCount::STATUS_DRAFT,
                WmsInventoryCount::STATUS_COUNTING,
            ])
            ->orderBy('count_date', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        return $this->success([
            'inventory_counts' => $counts->map(fn (WmsInventoryCount $count) => [
                'id' => $count->id,
                'count_no' => $count->count_no,
                'warehouse_id' => $count->warehouse_id,
                'warehouse_code' => $count->warehouse_code,
                'warehouse_name' => $count->warehouse_name,
                'count_date' => $count->count_date?->format('Y-m-d'),
                'status' => $count->status,
                'status_label' => $count->status_label,
                'started_at' => $count->started_at?->toIso8601String(),
                'snapshot_taken_at' => $count->snapshot_taken_at?->toIso8601String(),
                'ending_stock_taken_at' => $count->ending_stock_taken_at?->toIso8601String(),
                'memo' => $count->memo,
                'handy_reception' => true,
                'current_round' => $this->currentRound($count),
                'total_items' => $count->items()->withoutOwnedSetItems()->count(),
                'counted_items' => $count->items()->withoutOwnedSetItems()->whereNotNull('first_count_quantity')->count(),
                'final_counted_items' => $count->items()->withoutOwnedSetItems()->whereNotNull('final_count_quantity')->count(),
            ])->values()->all(),
        ]);
    }

    /**
     * GET /api/wms/inventory-counts/{id}
     *
     * Show inventory count header info
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $count = WmsInventoryCount::find($id);

        if (! $count) {
            return $this->notFound('棚卸データが見つかりません');
        }

        $itemStats = $this->inventoryCountItemsQuery((int) $count->id)
            ->selectRaw('COUNT(*) as total_items')
            ->selectRaw('COUNT(first_count_quantity) as counted_items')
            ->selectRaw('COUNT(*) - COUNT(first_count_quantity) as uncounted_items')
            ->first();

        return $this->success([
            'inventory_count' => [
                'id' => $count->id,
                'count_no' => $count->count_no,
                'warehouse_id' => $count->warehouse_id,
                'warehouse_code' => $count->warehouse_code,
                'warehouse_name' => $count->warehouse_name,
                'count_date' => $count->count_date?->format('Y-m-d'),
                'status' => $count->status,
                'status_label' => $count->status_label,
                'started_at' => $count->started_at?->toIso8601String(),
                'snapshot_taken_at' => $count->snapshot_taken_at?->toIso8601String(),
                'ending_stock_taken_at' => $count->ending_stock_taken_at?->toIso8601String(),
                'memo' => $count->memo,
                'handy_reception' => (bool) $count->handy_reception,
                'total_items' => (int) $itemStats->total_items,
                'counted_items' => (int) $itemStats->counted_items,
                'uncounted_items' => (int) $itemStats->uncounted_items,
            ],
        ]);
    }

    /**
     * GET /api/wms/inventory-counts/{id}/items
     *
     * List inventory count items with filters
     */
    public function items(Request $request, int $id): JsonResponse
    {
        $count = WmsInventoryCount::find($id);

        if (! $count) {
            return $this->notFound('棚卸データが見つかりません');
        }

        if (! $this->isHandyCountable($count)) {
            return $this->error('この棚卸はHandyでカウントできる状態ではありません', 422, 'INVALID_STATUS');
        }

        $this->startDraftForHandy($count);

        $query = $this->inventoryCountItemsQuery((int) $count->id);

        // Filter by floor_name
        if ($request->filled('floor_name')) {
            $query->where('floor_name', $request->input('floor_name'));
        }

        // Filter by location_code1
        if ($request->filled('location_code1')) {
            $query->where('location_code1', $request->input('location_code1'));
        }

        // Filter uncounted items (first_count_quantity is null)
        if ($request->boolean('uncounted')) {
            $query->whereNull('first_count_quantity');
        }

        // Filter items with difference (first_count_quantity != system_quantity)
        if ($request->boolean('has_difference')) {
            $query->whereNotNull('first_count_quantity')
                ->whereColumn('first_count_quantity', '!=', 'system_quantity');
        }

        $query->orderBy('floor_name')
            ->orderBy('location_code1')
            ->orderBy('location_code2')
            ->orderBy('location_code3')
            ->orderBy('item_code');

        $perPage = min((int) $request->input('per_page', 500), 500);
        $paginator = $query->paginate($perPage, ['*'], 'page', $request->input('page', 1));

        return $this->success([
            'items' => collect($paginator->items())
                ->map(fn (WmsInventoryCountItem $item) => $this->itemPayload($item, $request->boolean('compact')))
                ->values()
                ->all(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/wms/inventory-counts/{id}/jan-codes
     *
     * JAN code dictionary keyed by code for barcode scanning lookup.
     */
    public function janCodes(Request $request, int $id): JsonResponse
    {
        $count = WmsInventoryCount::find($id);

        if (! $count) {
            return $this->notFound('棚卸データが見つかりません');
        }

        $itemIds = $this->inventoryCountItemsQuery((int) $count->id)
            ->pluck('item_id');

        $rows = DB::connection('sakemaru')
            ->table('item_search_information as isi')
            ->leftJoin('item_quantity_information as iqi', 'isi.item_quantity_information_id', '=', 'iqi.id')
            ->leftJoin('items as i', 'i.id', '=', 'isi.item_id')
            ->whereIn('isi.item_id', $itemIds)
            ->where('isi.is_active', 1)
            ->whereNotNull('isi.search_string')
            ->where('isi.search_string', '!=', '')
            ->orderBy('isi.item_id')
            ->orderByRaw("CASE isi.quantity_type WHEN 'PIECE' THEN 0 WHEN 'CASE' THEN 1 WHEN 'CARTON' THEN 2 ELSE 9 END")
            ->orderBy('iqi.quantity')
            ->get([
                'isi.item_id',
                'isi.search_string',
                'isi.code_type',
                'isi.quantity_type',
                'iqi.quantity as package_quantity',
                'i.capacity_case as item_capacity_case',
            ]);

        $dict = [];
        foreach ($rows as $row) {
            $dict[$row->search_string][] = [
                'i' => (int) $row->item_id,
                'ct' => $row->code_type,
                't' => $this->quantityTypeCode($row->quantity_type),
                'q' => $this->packageQuantity($row),
            ];
        }

        return $this->success([
            'jan_codes' => $dict,
        ]);
    }

    /**
     * POST /api/wms/inventory-counts/{id}/scan
     *
     * Search items within an inventory count by barcode or item_code
     */
    public function scan(Request $request, int $id): JsonResponse
    {
        $count = WmsInventoryCount::find($id);

        if (! $count) {
            return $this->notFound('棚卸データが見つかりません');
        }

        $validator = Validator::make($request->all(), [
            'keyword' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $keyword = trim((string) $request->input('keyword'));
        $normalizedKeyword = function_exists('mb_convert_kana')
            ? mb_convert_kana($keyword, 'as')
            : $keyword;

        $items = $this->inventoryCountItemsQuery((int) $count->id)
            ->where(function ($query) use ($normalizedKeyword) {
                $like = "%{$normalizedKeyword}%";
                $query->where('id', $normalizedKeyword)
                    ->orWhere('item_code', $normalizedKeyword)
                    ->orWhere('item_code', 'like', $like)
                    ->orWhere('item_name', 'like', $like)
                    ->orWhere('barcode', $normalizedKeyword)
                    ->orWhereRaw('LPAD(barcode, 13, "0") = ?', [$normalizedKeyword])
                    ->orWhereExists(function ($sub) use ($normalizedKeyword) {
                        $sub->selectRaw('1')
                            ->from('item_search_information as isi')
                            ->whereColumn('isi.item_id', 'wms_inventory_count_items.item_id')
                            ->where('isi.is_active', 1)
                            ->where(function ($q) use ($normalizedKeyword) {
                                $q->where('isi.search_string', $normalizedKeyword)
                                    ->orWhereRaw('LPAD(isi.search_string, 13, "0") = ?', [$normalizedKeyword]);
                            });
                    })
                    ->orWhereExists(function ($sub) use ($normalizedKeyword, $like) {
                        $sub->selectRaw('1')
                            ->from('item_quantity_information as iqi')
                            ->whereColumn('iqi.item_id', 'wms_inventory_count_items.item_id')
                            ->where(function ($q) use ($normalizedKeyword, $like) {
                                $q->where('iqi.product_code', $normalizedKeyword)
                                    ->orWhere('iqi.own_code', $normalizedKeyword)
                                    ->orWhere('iqi.product_code', 'like', $like)
                                    ->orWhere('iqi.own_code', 'like', $like)
                                    ->orWhereRaw('LPAD(iqi.product_code, 13, "0") = ?', [$normalizedKeyword])
                                    ->orWhereRaw('LPAD(iqi.own_code, 13, "0") = ?', [$normalizedKeyword]);
                            });
                    });
            })
            ->orderBy('floor_name')
            ->orderBy('location_code1')
            ->orderBy('location_code2')
            ->orderBy('location_code3')
            ->limit(50)
            ->get();

        return $this->success([
            'items' => $items->map(fn (WmsInventoryCountItem $item) => $this->itemPayload($item))->values()->all(),
        ]);
    }

    /**
     * POST /api/wms/inventory-count-items/{itemId}/count
     *
     * Register actual count for an inventory count item
     */
    public function count(Request $request, int $itemId): JsonResponse
    {
        $countItem = WmsInventoryCountItem::query()
            ->withoutOwnedSetItems()
            ->find($itemId);

        if (! $countItem) {
            return $this->notFound('棚卸明細が見つかりません');
        }

        // Verify parent inventory count is in counting status
        $inventoryCount = WmsInventoryCount::find($countItem->inventory_count_id);
        if (! $inventoryCount || ! $this->isHandyCountable($inventoryCount)) {
            return $this->error('この棚卸はカウント中ではありません', 422, 'INVALID_STATUS');
        }

        $this->startDraftForHandy($inventoryCount);

        $validator = Validator::make($request->all(), [
            'quantity' => ['nullable', 'numeric'],
            'case_quantity' => ['nullable', 'integer'],
            'piece_quantity' => ['nullable', 'integer'],
            'search_code' => ['nullable', 'string', 'max:255'],
            'jan_code' => ['nullable', 'string', 'max:255'],
            'scanned_code' => ['nullable', 'string', 'max:255'],
            'count_round' => ['required', 'integer', 'in:1,2,3'],
            'device_id' => ['nullable', 'string', 'max:100'],
            'request_uuid' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $picker = $request->user();
        $userId = $picker ? $picker->id : null;

        $quantity = $request->filled('quantity')
            ? (float) $request->input('quantity')
            : $this->calculateTotalPieces(
                $countItem,
                (int) $request->input('case_quantity', 0),
                (int) $request->input('piece_quantity', 0),
                $this->inputSearchCode($request->all()),
            );

        $countItem = $this->inventoryCountService->registerCount(
            countItem: $countItem,
            quantity: $quantity,
            round: (int) $request->input('count_round'),
            deviceId: $request->input('device_id'),
            userId: $userId,
            requestUuid: $request->input('request_uuid'),
            accumulate: true,
        );

        return $this->success([
            'item' => $this->itemPayload($countItem->refresh()),
        ]);
    }

    public function bulkCount(Request $request, int $id): JsonResponse
    {
        $count = WmsInventoryCount::find($id);

        if (! $count) {
            return $this->notFound('棚卸データが見つかりません');
        }

        if (! $this->isHandyCountable($count)) {
            return $this->error('この棚卸はカウント中ではありません', 422, 'INVALID_STATUS');
        }

        $this->startDraftForHandy($count);

        $validator = Validator::make($request->all(), [
            'count_round' => ['required', 'integer', 'in:1,2,3'],
            'device_id' => ['nullable', 'string', 'max:100'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer'],
            'items.*.case_quantity' => ['nullable', 'integer'],
            'items.*.piece_quantity' => ['nullable', 'integer'],
            'items.*.quantity' => ['nullable', 'numeric'],
            'items.*.search_code' => ['nullable', 'string', 'max:255'],
            'items.*.jan_code' => ['nullable', 'string', 'max:255'],
            'items.*.scanned_code' => ['nullable', 'string', 'max:255'],
            'items.*.request_uuid' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $round = (int) $request->input('count_round');
        $picker = $request->user();
        $userId = $picker ? $picker->id : null;
        $updated = [];
        $missingItemIds = [];
        $rows = $request->input('items', []);

        foreach ($rows as $row) {
            $countItem = $this->inventoryCountItemsQuery((int) $count->id)
                ->where('id', (int) $row['item_id'])
                ->first();

            if (! $countItem) {
                $missingItemIds[] = (int) $row['item_id'];

                continue;
            }

            $quantity = array_key_exists('quantity', $row) && $row['quantity'] !== null
                ? (float) $row['quantity']
                : $this->calculateTotalPieces(
                    $countItem,
                    (int) ($row['case_quantity'] ?? 0),
                    (int) ($row['piece_quantity'] ?? 0),
                    $this->inputSearchCode($row),
                );

            $updatedItem = $this->inventoryCountService->registerCount(
                countItem: $countItem,
                quantity: $quantity,
                round: $round,
                deviceId: $request->input('device_id'),
                userId: $userId,
                requestUuid: (string) $row['request_uuid'],
                accumulate: true,
            );

            $updated[] = $this->itemPayload($updatedItem->refresh(), true);
        }

        Log::info('Inventory bulk count received', [
            'inventory_count_id' => $count->id,
            'round' => $round,
            'requested_count' => count($rows),
            'updated_count' => count($updated),
            'missing_item_ids' => $missingItemIds,
            'picker_id' => $userId,
            'device_id' => $request->input('device_id'),
        ]);

        return $this->success([
            'updated_count' => count($updated),
            'missing_item_ids' => $missingItemIds,
            'items' => $updated,
        ]);
    }

    /**
     * GET /api/wms/inventory-count-items/{itemId}/logs
     *
     * Get input history for an inventory count item
     */
    public function logs(Request $request, int $itemId): JsonResponse
    {
        $countItem = WmsInventoryCountItem::query()
            ->withoutOwnedSetItems()
            ->find($itemId);

        if (! $countItem) {
            return $this->notFound('棚卸明細が見つかりません');
        }

        $picker = $request->user();
        if (! $picker) {
            return $this->unauthorized();
        }

        $logs = WmsInventoryCountItemLog::where('inventory_count_item_id', $countItem->id)
            ->where('user_id', $picker->id)
            ->where(function ($query) {
                $query->whereNull('device_id')
                    ->orWhere('device_id', '!=', 'WEB');
            })
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        return $this->success([
            'logs' => $logs->map(fn (WmsInventoryCountItemLog $log) => [
                'id' => $log->id,
                'device_id' => $log->device_id,
                'user_id' => $log->user_id,
                'count_round' => $log->count_round,
                'old_quantity' => $log->old_quantity !== null ? (float) $log->old_quantity : null,
                'new_quantity' => (float) $log->new_quantity,
                'request_uuid' => $log->request_uuid,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    /**
     * POST /api/wms/inventory-counts/rescue
     *
     * Accept inventory count data from Handy devices when the original count
     * is no longer in a countable state. Stores raw data for later processing.
     */
    public function rescue(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'upload_uuid' => ['nullable', 'string', 'max:255'],
            'original_count_id' => ['required', 'integer'],
            'original_count_no' => ['required', 'string', 'max:100'],
            'count_round' => ['required', 'integer', 'in:1,2,3'],
            'device_id' => ['nullable', 'string', 'max:100'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer'],
            'items.*.item_code' => ['required', 'string', 'max:255'],
            'items.*.item_name' => ['required', 'string', 'max:500'],
            'items.*.location_no' => ['nullable', 'string', 'max:255'],
            'items.*.case_quantity' => ['required', 'integer'],
            'items.*.piece_quantity' => ['required', 'integer'],
            'items.*.total_pieces' => ['required', 'integer'],
            'items.*.search_code' => ['nullable', 'string', 'max:255'],
            'items.*.package_quantity' => ['nullable', 'integer'],
            'items.*.request_uuid' => ['required', 'string', 'max:255'],
            'items.*.input_at' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $picker = $request->user();
        $items = $request->input('items', []);
        $uploadUuid = $request->filled('upload_uuid') ? (string) $request->input('upload_uuid') : null;

        // 冪等性: 同じ upload_uuid の再送は既存の rescue を返し、二重登録しない
        if ($uploadUuid !== null) {
            $existing = WmsInventoryCountRescueData::where('upload_uuid', $uploadUuid)->first();
            if ($existing) {
                return $this->success([
                    'rescue_id' => $existing->id,
                    'received_count' => (int) $existing->item_count,
                    'duplicated' => true,
                ], '送信済みのデータです');
            }
        }

        try {
            $rescue = $this->createRescueData($request, $uploadUuid, $picker?->id, $items);
        } catch (\Illuminate\Database\QueryException $e) {
            // 同時再送で unique 制約に当たった場合は既存行を返す
            $existing = $uploadUuid !== null
                ? WmsInventoryCountRescueData::where('upload_uuid', $uploadUuid)->first()
                : null;

            if (! $existing) {
                throw $e;
            }

            return $this->success([
                'rescue_id' => $existing->id,
                'received_count' => (int) $existing->item_count,
                'duplicated' => true,
            ], '送信済みのデータです');
        }

        Log::info('Inventory rescue data received', [
            'rescue_id' => $rescue->id,
            'upload_uuid' => $uploadUuid,
            'original_count_id' => $rescue->original_count_id,
            'original_count_no' => $rescue->original_count_no,
            'item_count' => $rescue->item_count,
            'user_id' => $rescue->user_id,
            'warehouse_id' => $rescue->warehouse_id,
            'device_id' => $rescue->device_id,
        ]);

        return $this->success([
            'rescue_id' => $rescue->id,
            'received_count' => $rescue->item_count,
            'duplicated' => false,
        ]);
    }

    private function createRescueData(Request $request, ?string $uploadUuid, ?int $userId, array $items): WmsInventoryCountRescueData
    {
        $picker = $request->user();

        return WmsInventoryCountRescueData::create([
            'upload_uuid' => $uploadUuid,
            'original_count_id' => (int) $request->input('original_count_id'),
            'original_count_no' => $request->input('original_count_no'),
            'count_round' => (int) $request->input('count_round'),
            'device_id' => $request->input('device_id'),
            'user_id' => $userId,
            'warehouse_id' => $picker?->default_warehouse_id,
            'items' => $items,
            'item_count' => count($items),
            'status' => WmsInventoryCountRescueData::STATUS_PENDING,
        ]);
    }

    private function itemPayload(WmsInventoryCountItem $item, bool $compact = false): array
    {
        $master = $this->itemMaster($item->item_id);
        $capacityCase = max((int) ($master?->capacity_case ?? 1), 1);
        $systemQuantity = (int) $item->system_quantity;
        $endingSystemQuantity = $item->ending_system_quantity !== null ? (int) $item->ending_system_quantity : null;
        $currentCount = $item->final_count_quantity ?? $item->second_count_quantity ?? $item->first_count_quantity;
        $searchCodes = $this->searchCodes($item->item_id);

        $payload = [
            'id' => $item->id,
            'paper_barcode' => "ICITEM-{$item->id}",
            'search_text' => trim(implode(' ', array_filter(array_unique([
                "ICITEM-{$item->id}",
                $item->item_code,
                $item->item_name,
                $item->barcode,
                $item->location_no,
                ...array_column($searchCodes, 'c'),
            ])))),
            'item_id' => $item->item_id,
            'item_code' => $item->item_code,
            'item_name' => $item->item_name,
            'barcode' => $item->barcode,
            'volume' => $master?->volume !== null ? (string) $master->volume : null,
            'volume_unit' => $master?->volume_unit,
            'volume_unit_label' => $this->volumeUnitLabel($master?->volume_unit),
            'capacity_case' => $capacityCase,
            'capacity_carton' => $master?->capacity_carton !== null ? (int) $master->capacity_carton : null,
            'location' => [
                'id' => $item->location_id,
                'floor_name' => $item->floor_name,
                'location_no' => $item->location_no,
                'code1' => $item->location_code1,
                'code2' => $item->location_code2,
                'code3' => $item->location_code3,
            ],
            'system_quantity' => $systemQuantity,
            'system_quantity_start' => $systemQuantity,
            'system_case_quantity' => intdiv($systemQuantity, $capacityCase),
            'system_piece_quantity' => $systemQuantity % $capacityCase,
            'system_total_piece_quantity' => $systemQuantity,
            'ending_system_quantity' => $endingSystemQuantity,
            'system_quantity_end' => $endingSystemQuantity,
            'ending_system_case_quantity' => $endingSystemQuantity !== null ? intdiv($endingSystemQuantity, $capacityCase) : null,
            'ending_system_piece_quantity' => $endingSystemQuantity !== null ? $endingSystemQuantity % $capacityCase : null,
            'ending_system_total_piece_quantity' => $endingSystemQuantity,
            'first_count_quantity' => $item->first_count_quantity !== null ? (float) $item->first_count_quantity : null,
            'first_count_actor_name' => $item->first_count_actor_name,
            'second_count_quantity' => $item->second_count_quantity !== null ? (float) $item->second_count_quantity : null,
            'second_count_actor_name' => $item->second_count_actor_name,
            'final_count_quantity' => $item->final_count_quantity !== null ? (float) $item->final_count_quantity : null,
            'final_count_actor_name' => $item->final_count_actor_name,
            'current_count_quantity' => $currentCount !== null ? (float) $currentCount : null,
            'difference_quantity' => $currentCount !== null ? (float) $currentCount - (float) $item->system_quantity : null,
            'input_count' => (int) ($item->input_count ?? 0),
            'last_counted_at' => $item->last_counted_at?->toIso8601String(),
        ];

        if (! $compact) {
            $payload['search_codes'] = $searchCodes;
        }

        return $payload;
    }

    private function calculateTotalPieces(WmsInventoryCountItem $item, int $caseQuantity, int $pieceQuantity, ?string $searchCode = null): float
    {
        $capacityCase = $this->packageQuantityForCode($item->item_id, $searchCode)
            ?? max((int) ($this->itemMaster($item->item_id)?->capacity_case ?? 1), 1);

        return ($caseQuantity * $capacityCase) + $pieceQuantity;
    }

    private function itemMaster(int $itemId): ?object
    {
        static $cache = [];

        if (! array_key_exists($itemId, $cache)) {
            $cache[$itemId] = DB::connection('sakemaru')
                ->table('items')
                ->where('id', $itemId)
                ->first(['id', 'volume', 'volume_unit', 'capacity_case', 'capacity_carton']);
        }

        return $cache[$itemId];
    }

    private function volumeUnitLabel(?string $volumeUnit): ?string
    {
        $volumeUnit = $volumeUnit !== null ? trim($volumeUnit) : null;

        if ($volumeUnit === null || $volumeUnit === '') {
            return null;
        }

        return EVolumeUnit::tryFrom($volumeUnit)?->name() ?? $volumeUnit;
    }

    private function searchCodes(int $itemId): array
    {
        static $cache = [];

        if (! array_key_exists($itemId, $cache)) {
            $cache[$itemId] = DB::connection('sakemaru')
                ->table('item_search_information as isi')
                ->leftJoin('item_quantity_information as iqi', 'isi.item_quantity_information_id', '=', 'iqi.id')
                ->leftJoin('items as i', 'i.id', '=', 'isi.item_id')
                ->where('isi.item_id', $itemId)
                ->where('isi.is_active', 1)
                ->orderByRaw("CASE isi.quantity_type WHEN 'PIECE' THEN 0 WHEN 'CASE' THEN 1 WHEN 'CARTON' THEN 2 ELSE 9 END")
                ->orderBy('iqi.quantity')
                ->get([
                    'isi.search_string',
                    'isi.code_type',
                    'isi.quantity_type',
                    'iqi.quantity as package_quantity',
                    'i.capacity_case as item_capacity_case',
                ])
                ->map(fn ($row) => [
                    'c' => $row->search_string,
                    'ct' => $row->code_type,
                    't' => $this->quantityTypeCode($row->quantity_type),
                    'q' => $this->packageQuantity($row),
                ])
                ->filter(fn ($row) => $row['c'] !== null && $row['c'] !== '')
                ->values()
                ->all();
        }

        return $cache[$itemId];
    }

    private function inputSearchCode(array $data): ?string
    {
        foreach (['search_code', 'jan_code', 'scanned_code'] as $key) {
            if (! empty($data[$key])) {
                return trim((string) $data[$key]);
            }
        }

        return null;
    }

    private function packageQuantityForCode(int $itemId, ?string $searchCode): ?int
    {
        if ($searchCode === null || $searchCode === '') {
            return null;
        }

        $normalizedCode = function_exists('mb_convert_kana')
            ? mb_convert_kana($searchCode, 'as')
            : $searchCode;

        $row = DB::connection('sakemaru')
            ->table('item_search_information as isi')
            ->leftJoin('item_quantity_information as iqi', 'isi.item_quantity_information_id', '=', 'iqi.id')
            ->leftJoin('items as i', 'i.id', '=', 'isi.item_id')
            ->where('isi.item_id', $itemId)
            ->where('isi.is_active', 1)
            ->where(function ($query) use ($normalizedCode) {
                $query->where('isi.search_string', $normalizedCode)
                    ->orWhereRaw('LPAD(isi.search_string, 13, "0") = ?', [$normalizedCode]);
            })
            ->first(['isi.quantity_type', 'iqi.quantity as package_quantity', 'i.capacity_case as item_capacity_case']);

        return $row ? $this->packageQuantity($row) : null;
    }

    private function packageQuantity(object $row): int
    {
        if (($row->quantity_type ?? null) === 'PIECE') {
            return max((int) ($row->item_capacity_case ?? $row->capacity_case ?? 1), 1);
        }

        return max((int) ($row->package_quantity ?? $row->quantity ?? 1), 1);
    }

    private function quantityTypeCode(?string $quantityType): string
    {
        return match ($quantityType) {
            'PIECE' => '0',
            'CASE' => '1',
            'CARTON' => '2',
            default => '9',
        };
    }

    private function currentRound(WmsInventoryCount $count): int
    {
        if ((int) ($count->current_count_round ?? 0) > 0) {
            return min(max((int) $count->current_count_round, 1), 3);
        }

        if ($count->items()->withoutOwnedSetItems()->whereNotNull('final_count_quantity')->exists()) {
            return 3;
        }

        if ($count->items()->withoutOwnedSetItems()->whereNotNull('second_count_quantity')->exists()) {
            return 2;
        }

        return 1;
    }

    public function active(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'warehouse_id' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $count = WmsInventoryCount::where('warehouse_id', (int) $request->input('warehouse_id'))
            ->where('handy_reception', true)
            ->whereIn('status', [
                WmsInventoryCount::STATUS_DRAFT,
                WmsInventoryCount::STATUS_COUNTING,
            ])
            ->first();

        if (! $count) {
            return $this->success(['inventory_count' => null]);
        }

        $itemStats = $this->inventoryCountItemsQuery((int) $count->id)
            ->selectRaw('COUNT(*) as total_items')
            ->selectRaw('COUNT(first_count_quantity) as counted_items')
            ->selectRaw('COUNT(*) - COUNT(first_count_quantity) as uncounted_items')
            ->first();

        return $this->success([
            'inventory_count' => [
                'id' => $count->id,
                'count_no' => $count->count_no,
                'warehouse_id' => $count->warehouse_id,
                'warehouse_code' => $count->warehouse_code,
                'warehouse_name' => $count->warehouse_name,
                'count_date' => $count->count_date?->format('Y-m-d'),
                'status' => $count->status,
                'status_label' => $count->status_label,
                'started_at' => $count->started_at?->toIso8601String(),
                'snapshot_taken_at' => $count->snapshot_taken_at?->toIso8601String(),
                'ending_stock_taken_at' => $count->ending_stock_taken_at?->toIso8601String(),
                'memo' => $count->memo,
                'handy_reception' => true,
                'total_items' => (int) $itemStats->total_items,
                'counted_items' => (int) $itemStats->counted_items,
                'uncounted_items' => (int) $itemStats->uncounted_items,
            ],
        ]);
    }

    private function isHandyCountable(WmsInventoryCount $count): bool
    {
        return $count->handy_reception
            && in_array($count->status, [
                WmsInventoryCount::STATUS_DRAFT,
                WmsInventoryCount::STATUS_COUNTING,
            ], true);
    }

    private function startDraftForHandy(WmsInventoryCount $count): void
    {
        if ($count->status !== WmsInventoryCount::STATUS_DRAFT) {
            return;
        }

        $count->forceFill([
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'started_at' => now(),
        ])->save();
    }

    private function inventoryCountItemsQuery(int $inventoryCountId): \Illuminate\Database\Eloquent\Builder
    {
        return WmsInventoryCountItem::where('inventory_count_id', $inventoryCountId)
            ->withoutOwnedSetItems();
    }
}
