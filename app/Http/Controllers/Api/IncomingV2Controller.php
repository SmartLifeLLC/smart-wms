<?php

namespace App\Http\Controllers\Api;

use App\Services\Incoming\IncomingInspectionSnapshotService;
use App\Services\Incoming\IncomingInspectionSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * 入荷API v2 コントローラ
 *
 * @OA\Tag(
 *     name="Incoming v2",
 *     description="アプリ入荷検品・EOS自動連携対応API"
 * )
 */
class IncomingV2Controller extends ApiController
{
    /**
     * GET /api/v2/incoming/snapshot
     *
     * 倉庫単位の入荷検品スナップショット取得
     *
     * @OA\Get(
     *     path="/api/v2/incoming/snapshot",
     *     tags={"Incoming v2"},
     *     summary="入荷検品スナップショット取得",
     *     description="アプリのオフライン入荷検品用に、未確定入荷予定、EOS確定済み照合用データ、ロケーションを倉庫単位で取得する。商品マスタ全件は含めず、/api/v2/incoming/item-master を1日1回取得して端末に保存する。実倉庫指定時は同一実倉庫配下の仮想倉庫分も入荷予定・EOS照合対象に含める。EOS対象はアプリ側で入荷確定せず履歴のみ保存するため、inspection_policyで処理方針を返す。",
     *     security={{"apiKey":{}, "sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="warehouse_id",
     *         in="query",
     *         required=true,
     *         description="作業倉庫ID。実倉庫指定時は仮想倉庫分も照合対象に含める",
     *
     *         @OA\Schema(type="integer", example=91)
     *     ),
     *
     *     @OA\Parameter(
     *         name="inspection_date",
     *         in="query",
     *         required=false,
     *         description="検品日。未指定時はシステム日付",
     *
     *         @OA\Schema(type="string", format="date", example="2026-08-08")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="成功",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="is_success", type="boolean", example=true),
     *             @OA\Property(property="code", type="string", example="SUCCESS"),
     *             @OA\Property(
     *                 property="result",
     *                 type="object",
     *                 @OA\Property(
     *                     property="data",
     *                     type="object",
     *                     @OA\Property(property="version", type="string", example="v2"),
     *                     @OA\Property(property="generated_at", type="string", format="date-time"),
     *                     @OA\Property(property="inspection_date", type="string", format="date"),
     *                     @OA\Property(
     *                         property="warehouse",
     *                         type="object",
     *                         nullable=true,
     *                         @OA\Property(property="id", type="integer"),
     *                         @OA\Property(property="code", type="string"),
     *                         @OA\Property(property="name", type="string"),
     *                         @OA\Property(property="kana_name", type="string", nullable=true)
     *                     ),
     *                     @OA\Property(
     *                         property="rules",
     *                         type="object",
     *                         @OA\Property(property="eos_inspection_policy", type="string", example="HISTORY_ONLY"),
     *                         @OA\Property(property="eos_confirmed_index_days", type="integer", example=3),
     *                         @OA\Property(property="unplanned_order_source", type="string", example="APP_UNPLANNED"),
     *                         @OA\Property(property="quantity_input", type="string", example="CASE_AND_PIECE"),
     *                         @OA\Property(property="item_master_sync", type="string", example="DAILY_CACHE"),
     *                         @OA\Property(property="matching_warehouse_ids", type="array", @OA\Items(type="integer"), example={91, 92, 93})
     *                     ),
     *                     @OA\Property(
     *                         property="schedules",
     *                         type="array",
     *                         description="未確定入荷予定",
     *
     *                         @OA\Items(
     *                             type="object",
     *                             @OA\Property(property="id", type="integer"),
     *                             @OA\Property(property="warehouse_id", type="integer"),
     *                             @OA\Property(property="warehouse", type="object", nullable=true),
     *                             @OA\Property(property="slip_number", type="string", nullable=true),
     *                             @OA\Property(property="order_source", type="string", nullable=true, example="AUTO"),
     *                             @OA\Property(property="order_source_label", type="string", example="発注"),
     *                             @OA\Property(property="inspection_policy", type="string", enum={"APP_CONFIRM_ALLOWED", "EOS_HISTORY_ONLY", "TRANSFER_WEB_ONLY", "PURCHASE_TRANSMITTED_LOCKED"}),
     *                             @OA\Property(property="is_eos_sent", type="boolean"),
     *                             @OA\Property(property="status", type="string", example="PENDING"),
     *                             @OA\Property(property="order_date", type="string", format="date", nullable=true),
     *                             @OA\Property(property="expected_arrival_date", type="string", format="date", nullable=true),
     *                             @OA\Property(property="actual_arrival_date", type="string", format="date", nullable=true),
     *                             @OA\Property(property="contractor", type="object", nullable=true),
     *                             @OA\Property(property="item", type="object", nullable=true),
     *                             @OA\Property(property="location", type="object", nullable=true),
     *                             @OA\Property(
     *                                 property="quantity",
     *                                 type="object",
     *                                 @OA\Property(property="quantity_type", type="string", nullable=true),
     *                                 @OA\Property(property="expected_quantity", type="integer"),
     *                                 @OA\Property(property="received_quantity", type="integer"),
     *                                 @OA\Property(property="remaining_quantity", type="integer"),
     *                                 @OA\Property(property="expected_piece_quantity", type="integer"),
     *                                 @OA\Property(property="received_piece_quantity", type="integer"),
     *                                 @OA\Property(property="remaining_piece_quantity", type="integer"),
     *                                 @OA\Property(property="capacity_case", type="integer")
     *                             )
     *                         )
     *                     ),
     *                     @OA\Property(
     *                         property="confirmed_eos_index",
     *                         type="array",
     *                         description="検品日を含む過去3日分のEOS確定済み照合用データ",
     *
     *                         @OA\Items(type="object")
     *                     ),
     *                     @OA\Property(
     *                         property="items",
     *                         type="array",
     *                         description="互換用フィールド。商品マスタ全件は /api/v2/incoming/item-master で取得するため通常は空配列",
     *
     *                         @OA\Items(type="object")
     *                     ),
     *                     @OA\Property(
     *                         property="locations",
     *                         type="array",
     *                         description="倉庫ロケーション",
     *
     *                         @OA\Items(type="object")
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=422, description="バリデーションエラー")
     * )
     */
    public function snapshot(Request $request, IncomingInspectionSnapshotService $service): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'warehouse_id' => 'required|integer',
            'inspection_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        return $this->success($service->build(
            (int) $request->input('warehouse_id'),
            $request->input('inspection_date')
        ));
    }

    /**
     * GET /api/v2/incoming/item-master
     *
     * 倉庫単位の商品マスタ取得
     *
     * @OA\Get(
     *     path="/api/v2/incoming/item-master",
     *     tags={"Incoming v2"},
     *     summary="入荷検品用商品マスタ取得",
     *     description="HANDYアプリが倉庫ごとに1日1回取得して端末へ保存する商品マスタ。入荷予定スナップショットには全商品マスタを含めないため、予定なし入荷の商品検索・JAN照合にはこのAPIの結果を利用する。",
     *     security={{"apiKey":{}, "sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="warehouse_id",
     *         in="query",
     *         required=true,
     *         description="作業倉庫ID。商品取扱判定は同一実倉庫配下も含め、デフォルトロケは作業倉庫のものを返す",
     *
     *         @OA\Schema(type="integer", example=91)
     *     ),
     *
     *     @OA\Response(response=200, description="成功"),
     *     @OA\Response(response=422, description="バリデーションエラー")
     * )
     */
    public function itemMaster(Request $request, IncomingInspectionSnapshotService $service): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'warehouse_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        return $this->success($service->buildItemMaster((int) $request->input('warehouse_id')));
    }

    /**
     * POST /api/v2/incoming/inspection-batches/sync
     *
     * アプリ入荷検品結果同期
     *
     * @OA\Post(
     *     path="/api/v2/incoming/inspection-batches/sync",
     *     tags={"Incoming v2"},
     *     summary="入荷検品結果同期",
     *     description="アプリで検品した明細を同期する。同じclient_batch_uuidとclient_line_uuidは冪等に処理する。入荷予定・EOS確定済みの照合は同一実倉庫配下の仮想倉庫分も対象にするが、検品履歴と予定なし入荷の作業倉庫はリクエストwarehouse_idのまま保存する。EOS対象またはEOS確定済みは入荷予定を更新せず履歴のみ保存し、非EOSは必要に応じて入荷確定または予定なし入荷を作成する。",
     *     security={{"apiKey":{}, "sanctum":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"client_batch_uuid", "warehouse_id", "details"},
     *
     *             @OA\Property(property="client_batch_uuid", type="string", maxLength=80, example="app-batch-20260808-0001"),
     *             @OA\Property(property="warehouse_id", type="integer", example=91, description="作業倉庫ID。照合対象は同一実倉庫配下に広げる"),
     *             @OA\Property(property="inspection_date", type="string", format="date", nullable=true, example="2026-08-08"),
     *             @OA\Property(property="inspected_at", type="string", format="date-time", nullable=true),
     *             @OA\Property(property="picker_id", type="integer", nullable=true),
     *             @OA\Property(property="device_id", type="string", maxLength=80, nullable=true),
     *             @OA\Property(property="app_version", type="string", maxLength=40, nullable=true),
     *             @OA\Property(
     *                 property="details",
     *                 type="array",
     *                 minItems=1,
     *                 maxItems=1000,
     *
     *                 @OA\Items(
     *                     type="object",
     *                     required={"client_line_uuid"},
     *                     @OA\Property(property="client_line_uuid", type="string", maxLength=80, example="line-0001"),
     *                     @OA\Property(property="incoming_schedule_id", type="integer", nullable=true),
     *                     @OA\Property(property="item_id", type="integer", nullable=true),
     *                     @OA\Property(property="item_code", type="string", maxLength=32, nullable=true),
     *                     @OA\Property(property="item_name", type="string", maxLength=255, nullable=true),
     *                     @OA\Property(property="scanned_code", type="string", maxLength=64, nullable=true, description="JANまたは検索CD"),
     *                     @OA\Property(property="slip_number", type="string", maxLength=32, nullable=true),
     *                     @OA\Property(property="contractor_id", type="integer", nullable=true),
     *                     @OA\Property(property="location_id", type="integer", nullable=true),
     *                     @OA\Property(property="case_quantity", type="integer", minimum=0, nullable=true, example=1),
     *                     @OA\Property(property="piece_quantity", type="integer", minimum=0, nullable=true, example=6),
     *                     @OA\Property(property="total_piece_quantity", type="integer", minimum=0, nullable=true, description="指定時はケース/バラより優先する総バラ数"),
     *                     @OA\Property(property="capacity_case", type="integer", minimum=1, nullable=true, description="ケース入数"),
     *                     @OA\Property(property="expiration_date", type="string", format="date", nullable=true),
     *                     @OA\Property(property="inspected_at", type="string", format="date-time", nullable=true)
     *                 )
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="成功",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="is_success", type="boolean", example=true),
     *             @OA\Property(property="code", type="string", example="SUCCESS"),
     *             @OA\Property(
     *                 property="result",
     *                 type="object",
     *                 @OA\Property(
     *                     property="data",
     *                     type="object",
     *                     @OA\Property(
     *                         property="batch",
     *                         type="object",
     *                         @OA\Property(property="id", type="integer"),
     *                         @OA\Property(property="client_batch_uuid", type="string"),
     *                         @OA\Property(property="status", type="string", enum={"RECEIVED", "COMPLETED", "PARTIAL_FAILED"}),
     *                         @OA\Property(property="total_detail_count", type="integer"),
     *                         @OA\Property(property="success_count", type="integer"),
     *                         @OA\Property(property="history_only_count", type="integer"),
     *                         @OA\Property(property="review_count", type="integer"),
     *                         @OA\Property(property="error_count", type="integer")
     *                     ),
     *                     @OA\Property(
     *                         property="details",
     *                         type="array",
     *
     *                         @OA\Items(
     *                             type="object",
     *                             @OA\Property(property="id", type="integer"),
     *                             @OA\Property(property="client_line_uuid", type="string"),
     *                             @OA\Property(property="incoming_schedule_id", type="integer", nullable=true),
     *                             @OA\Property(property="linked_confirmed_schedule_id", type="integer", nullable=true),
     *                             @OA\Property(property="created_schedule_id", type="integer", nullable=true),
     *                             @OA\Property(property="item_id", type="integer", nullable=true),
     *                             @OA\Property(property="item_code", type="string", nullable=true),
     *                             @OA\Property(property="item_name", type="string", nullable=true),
     *                             @OA\Property(property="inspection_policy", type="string", enum={"APP_CONFIRM_ALLOWED", "EOS_HISTORY_ONLY", "EOS_ALREADY_CONFIRMED", "TRANSFER_WEB_ONLY", "PURCHASE_TRANSMITTED_LOCKED", "NEEDS_REVIEW"}),
     *                             @OA\Property(property="result_status", type="string", enum={"HISTORY_ONLY", "CONFIRMED", "APP_UNPLANNED_CREATED", "EOS_ALREADY_CONFIRMED", "NEEDS_REVIEW", "ERROR"}),
     *                             @OA\Property(property="review_reason", type="string", nullable=true),
     *                             @OA\Property(property="inspected_total_piece_quantity", type="integer"),
     *                             @OA\Property(property="applied_piece_quantity", type="integer"),
     *                             @OA\Property(property="shortage_piece_quantity", type="integer")
     *                         )
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=422, description="バリデーションエラー")
     * )
     */
    public function sync(Request $request, IncomingInspectionSyncService $service): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'client_batch_uuid' => 'required|string|max:80',
            'warehouse_id' => 'required|integer',
            'inspection_date' => 'nullable|date',
            'inspected_at' => 'nullable|date',
            'picker_id' => 'nullable|integer',
            'device_id' => 'nullable|string|max:80',
            'app_version' => 'nullable|string|max:40',
            'details' => 'required|array|min:1|max:1000',
            'details.*.client_line_uuid' => 'required|string|max:80',
            'details.*.incoming_schedule_id' => 'nullable|integer',
            'details.*.item_id' => 'nullable|integer',
            'details.*.item_code' => 'nullable|string|max:32',
            'details.*.item_name' => 'nullable|string|max:255',
            'details.*.scanned_code' => 'nullable|string|max:64',
            'details.*.slip_number' => 'nullable|string|max:32',
            'details.*.contractor_id' => 'nullable|integer',
            'details.*.location_id' => 'nullable|integer',
            'details.*.case_quantity' => 'nullable|integer|min:0',
            'details.*.piece_quantity' => 'nullable|integer|min:0',
            'details.*.total_piece_quantity' => 'nullable|integer|min:0',
            'details.*.capacity_case' => 'nullable|integer|min:1',
            'details.*.expiration_date' => 'nullable|date',
            'details.*.inspected_at' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        return $this->success($service->sync($validator->validated(), (int) $request->user()->id));
    }
}
