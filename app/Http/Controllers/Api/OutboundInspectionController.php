<?php

namespace App\Http\Controllers\Api;

use App\Models\Sakemaru\ClientSetting;
use App\Services\OutboundInspection\OutboundInspectionSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OutboundInspectionController extends ApiController
{
    public function snapshot(Request $request, OutboundInspectionSnapshotService $service): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|integer|exists:sakemaru.warehouses,id',
            'period' => 'required|string|in:morning,afternoon',
            'working_date' => 'nullable|date_format:Y-m-d',
            'business_date' => 'nullable|date_format:Y-m-d',
        ]);

        $workingDate = $validated['working_date']
            ?? $validated['business_date']
            ?? ClientSetting::freshSystemDate(true, 'outbound_inspection:snapshot')->toDateString();

        $snapshot = $service->buildSnapshot(
            warehouseId: (int) $validated['warehouse_id'],
            period: $validated['period'],
            workingDate: $workingDate,
        );

        if ($snapshot === null) {
            return $this->notFound('対象の出庫検品データが見つかりません');
        }

        return $this->success($snapshot);
    }
}
