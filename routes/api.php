<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\IncomingController;
use App\Http\Controllers\Api\IncomingV2Controller;
use App\Http\Controllers\Api\InventoryCountController;
use App\Http\Controllers\Api\MasterDataController;
use App\Http\Controllers\Api\OutboundInspectionController;
use App\Http\Controllers\Api\PickingRouteController;
use App\Http\Controllers\Api\PickingTaskController;
use App\Http\Controllers\Api\ProxyShipmentController;
use App\Http\Controllers\Api\StockDisposalController;
use App\Http\Controllers\Api\WarehouseTransferController;
use Illuminate\Support\Facades\Route;

// Internal admin helper routes
Route::middleware(['web', 'auth:web', 'sakemaru-permission:wms.picking-route.view'])->group(function () {
    Route::get('/picking-routes', [PickingRouteController::class, 'getPickingRoute']);
    Route::get('/walkable-areas', [PickingRouteController::class, 'getWalkableAreas']);
});

// All API routes require API key authentication
Route::middleware('api.key')->group(function () {
    // Public routes (no user authentication required, only API key)
    Route::post('/auth/login', [AuthController::class, 'login']);

    // Protected routes (require both API key and user authentication)
    Route::middleware('auth:sanctum')->group(function () {
        // Authentication
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);

        // Master data endpoints
        Route::get('/master/warehouses', [MasterDataController::class, 'warehouses']);
        Route::get('/master/item-locations', [MasterDataController::class, 'itemLocations']);

        // Stock adjustment (在庫調節) endpoints
        Route::get('/stock-disposals/items/search', [StockDisposalController::class, 'searchItems']);
        Route::post('/stock-disposals', [StockDisposalController::class, 'store']);

        // Picking task endpoints
        Route::get('/picking/tasks', [PickingTaskController::class, 'index']);
        Route::get('/picking/tasks/{id}', [PickingTaskController::class, 'show']);
        Route::get('/picking/items/{id}', [PickingTaskController::class, 'showItem']);
        Route::post('/picking/tasks/{id}/start', [PickingTaskController::class, 'start']);
        Route::post('/picking/tasks/{itemResultId}/update', [PickingTaskController::class, 'updateItemResult']);
        Route::post('/picking/tasks/{itemResultId}/cancel', [PickingTaskController::class, 'cancelItemResult']);
        Route::post('/picking/tasks/{id}/complete', [PickingTaskController::class, 'complete']);
        Route::get('/outbound-inspections/snapshot', [OutboundInspectionController::class, 'snapshot']);

        // Incoming (入荷) endpoints
        Route::get('/incoming/schedules', [IncomingController::class, 'index']);
        Route::get('/incoming/schedules/{id}', [IncomingController::class, 'show']);
        Route::get('/incoming/work-items', [IncomingController::class, 'workItems']);
        Route::post('/incoming/work-items', [IncomingController::class, 'startWork']);
        Route::put('/incoming/work-items/{id}', [IncomingController::class, 'updateWork']);
        Route::post('/incoming/work-items/{id}/complete', [IncomingController::class, 'completeWork']);
        Route::delete('/incoming/work-items/{id}', [IncomingController::class, 'cancelWork']);
        Route::get('/incoming/locations', [IncomingController::class, 'searchLocations']);

        // Incoming v2 endpoints for offline app inspection and EOS-safe history recording
        Route::get('/v2/incoming/snapshot', [IncomingV2Controller::class, 'snapshot']);
        Route::get('/v2/incoming/item-master', [IncomingV2Controller::class, 'itemMaster']);
        Route::post('/v2/incoming/inspection-batches/sync', [IncomingV2Controller::class, 'sync']);

        // Proxy shipment (横持ち出荷) endpoints
        Route::get('/proxy-shipments', [ProxyShipmentController::class, 'index']);
        Route::get('/proxy-shipments/{id}', [ProxyShipmentController::class, 'show']);
        Route::post('/proxy-shipments/{id}/start', [ProxyShipmentController::class, 'start']);
        Route::post('/proxy-shipments/{id}/update', [ProxyShipmentController::class, 'update']);
        Route::post('/proxy-shipments/{id}/complete', [ProxyShipmentController::class, 'complete']);

        // Inventory count (棚卸し) endpoints
        Route::get('/wms/inventory-counts', [InventoryCountController::class, 'index']);
        Route::get('/wms/inventory-counts/active', [InventoryCountController::class, 'active']);
        Route::get('/wms/inventory-counts/{id}', [InventoryCountController::class, 'show']);
        Route::get('/wms/inventory-counts/{id}/items', [InventoryCountController::class, 'items']);
        Route::get('/wms/inventory-counts/{id}/jan-codes', [InventoryCountController::class, 'janCodes']);
        Route::post('/wms/inventory-counts/{id}/scan', [InventoryCountController::class, 'scan']);
        Route::post('/wms/inventory-counts/{id}/counts/bulk', [InventoryCountController::class, 'bulkCount']);
        Route::post('/wms/inventory-counts/rescue', [InventoryCountController::class, 'rescue']);
        Route::post('/wms/inventory-count-items/{itemId}/count', [InventoryCountController::class, 'count']);
        Route::get('/wms/inventory-count-items/{itemId}/logs', [InventoryCountController::class, 'logs']);

        // Warehouse transfer candidates (倉庫移動候補 / HANDY) endpoints
        Route::get('/wms/warehouse-transfer/stock-items', [WarehouseTransferController::class, 'stockItems']);
        Route::get('/wms/warehouse-transfer/jan-codes', [WarehouseTransferController::class, 'janCodes']);
        Route::get('/wms/warehouse-transfer/warehouses', [WarehouseTransferController::class, 'warehouses']);
        Route::post('/wms/warehouse-transfer-candidates', [WarehouseTransferController::class, 'store']);
        Route::get('/wms/warehouse-transfer-candidates/{id}', [WarehouseTransferController::class, 'show']);
    });
});
