<?php

namespace App\Http\Controllers\Api;

use App\Models\WmsWarehouseTransferCandidate;
use App\Services\WarehouseTransfer\WarehouseTransferCandidateReceiveService;
use App\Services\WarehouseTransfer\WarehouseTransferStockListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * HANDY 倉庫移動候補 API
 *
 * - GET  /api/wms/warehouse-transfer/stock-items      移動元倉庫の在庫リスト
 * - GET  /api/wms/warehouse-transfer/jan-codes        JAN/検索CD辞書
 * - GET  /api/wms/warehouse-transfer/warehouses       移動先倉庫一覧
 * - POST /api/wms/warehouse-transfer-candidates       候補送信
 * - GET  /api/wms/warehouse-transfer-candidates/{id}  候補取得
 */
class WarehouseTransferController extends ApiController
{
    public function __construct(
        private readonly WarehouseTransferStockListService $stockListService,
        private readonly WarehouseTransferCandidateReceiveService $receiveService,
    ) {}

    /**
     * GET /api/wms/warehouse-transfer/stock-items
     */
    public function stockItems(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'warehouse_id' => ['required', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.WarehouseTransferStockListService::MAX_PER_PAGE],
            'compact' => ['nullable'],
            'include_zero' => ['nullable'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $paginator = $this->stockListService->paginateStockItems(
            warehouseId: (int) $request->input('warehouse_id'),
            page: (int) $request->input('page', 1),
            perPage: (int) $request->input('per_page', WarehouseTransferStockListService::MAX_PER_PAGE),
            includeZero: $request->boolean('include_zero'),
            compact: $request->boolean('compact'),
        );

        return $this->success([
            'items' => $paginator->items(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/wms/warehouse-transfer/jan-codes
     */
    public function janCodes(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'warehouse_id' => ['required', 'integer'],
            'include_zero' => ['nullable'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        return $this->success([
            'jan_codes' => $this->stockListService->janDictionary(
                (int) $request->input('warehouse_id'),
                $request->boolean('include_zero'),
            ),
        ]);
    }

    /**
     * GET /api/wms/warehouse-transfer/warehouses
     *
     * 移動先倉庫の選択用。exclude_warehouse_id（自店倉庫）は除外する。
     */
    public function warehouses(Request $request): JsonResponse
    {
        $query = DB::connection('sakemaru')
            ->table('warehouses')
            ->where('is_active', 1)
            ->select(['id', 'code', 'name', 'kana_name'])
            ->orderBy('code');

        if (config('app.client_id')) {
            $query->where('client_id', (int) config('app.client_id'));
        }

        if ($request->filled('exclude_warehouse_id')) {
            $query->where('id', '!=', (int) $request->input('exclude_warehouse_id'));
        }

        return $this->success([
            'warehouses' => $query->get()->map(fn ($row) => [
                'id' => (int) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'kana_name' => $row->kana_name,
            ])->values()->all(),
        ]);
    }

    /**
     * POST /api/wms/warehouse-transfer-candidates
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'upload_uuid' => ['required', 'string', 'max:255'],
            'device_id' => ['nullable', 'string', 'max:100'],
            'from_warehouse_id' => ['required', 'integer'],
            'to_warehouse_id' => ['required', 'integer', 'different:from_warehouse_id'],
            'process_date' => ['required', 'date'],
            'delivered_date' => ['required', 'date'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.item_id' => ['required', 'integer'],
            'items.*.item_code' => ['required', 'string', 'max:64'],
            'items.*.real_stock_id' => ['nullable', 'integer'],
            'items.*.stock_allocation_code' => ['nullable', 'string', 'max:32'],
            'items.*.case_quantity' => ['nullable', 'numeric'],
            'items.*.piece_quantity' => ['nullable', 'numeric'],
            'items.*.package_quantity' => ['nullable', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.search_code' => ['nullable', 'string', 'max:255'],
            'items.*.request_uuid' => ['required', 'string', 'max:255'],
        ], [
            'to_warehouse_id.different' => '移動元と移動先は同一にできません',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $picker = $request->user();

        try {
            $result = $this->receiveService->receive($validator->validated(), $picker?->id);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), $e->getMessage());
        } catch (Throwable $e) {
            Log::error('Warehouse transfer candidate receive failed', [
                'upload_uuid' => $request->input('upload_uuid'),
                'picker_id' => $picker?->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error('倉庫移動候補の登録に失敗しました', 500, 'RECEIVE_FAILED', $e->getMessage());
        }

        return $this->success(
            $result,
            $result['duplicated'] ? '送信済みのデータです' : null,
            $result['duplicated'] ? 200 : 201,
        );
    }

    /**
     * GET /api/wms/warehouse-transfer-candidates/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $candidate = WmsWarehouseTransferCandidate::find($id);

        if (! $candidate) {
            return $this->notFound('倉庫移動候補が見つかりません');
        }

        return $this->success([
            'candidate' => $this->receiveService->candidateSummary($candidate),
            'items' => $candidate->items()->get()->map(fn ($item) => [
                'id' => $item->id,
                'item_id' => (int) $item->item_id,
                'item_code' => $item->item_code,
                'item_name' => $item->item_name,
                'location_no' => $item->location_no,
                'stock_allocation_code' => $item->stock_allocation_code,
                'case_quantity' => (float) $item->case_quantity,
                'piece_quantity' => (float) $item->piece_quantity,
                'package_quantity' => (int) $item->package_quantity,
                'transfer_quantity' => (float) $item->transfer_quantity,
            ])->values()->all(),
        ]);
    }
}
