<?php

namespace App\Services\Shortage;

use App\Models\WmsShortageAllocation;
use App\Services\WarehouseResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 横持ち出荷完了時に倉庫移動伝票キューを作成するサービス
 */
class StockTransferQueueService
{
    /**
     * 横持ち出荷完了時に倉庫移動伝票キューを作成
     *
     * @return int|null 作成されたキューID
     *
     * @throws \Exception
     */
    public function createStockTransferQueue(WmsShortageAllocation $allocation): ?int
    {
        // ピック数が0の場合は何もしない
        if ($allocation->picked_qty <= 0) {
            Log::info('No stock transfer queue created (picked_qty = 0)', [
                'allocation_id' => $allocation->id,
            ]);

            return null;
        }

        // shortage経由でtradeを取得
        $shortage = $allocation->shortage;
        if (! $shortage) {
            throw new \Exception("Shortage not found for allocation ID {$allocation->id}");
        }

        $trade = $shortage->trade;
        if (! $trade) {
            throw new \Exception("Trade not found for shortage ID {$shortage->id}");
        }

        // target_warehouse (横持ち出荷倉庫) と source_warehouse (元倉庫) のコードを取得
        $targetWarehouse = $allocation->targetWarehouse;
        $sourceWarehouse = $allocation->sourceWarehouse;

        if (! $targetWarehouse || ! $sourceWarehouse) {
            throw new \Exception("Warehouse not found: target={$allocation->target_warehouse_id}, source={$allocation->source_warehouse_id}");
        }

        // itemの取得
        $item = $shortage->item;
        if (! $item) {
            throw new \Exception("Item not found for shortage ID {$shortage->id}");
        }

        // 最終配送先を実倉庫ベースで判定
        $toWarehouseCode = $this->determineToWarehouse($shortage, $sourceWarehouse);

        if ((string) $targetWarehouse->code === (string) $toWarehouseCode) {
            Log::info('No stock transfer queue created (same from/to warehouse)', [
                'allocation_id' => $allocation->id,
                'warehouse_code' => $targetWarehouse->code,
                'picked_qty' => $allocation->picked_qty,
            ]);

            return null;
        }

        // items配列を作成
        $items = [
            [
                'item_code' => $item->code,
                'quantity' => $allocation->picked_qty,
                'quantity_type' => $allocation->assign_qty_type,
                'stock_allocation_code' => '1', // デフォルトの在庫区分コード（通常在庫）
                'purchase_price' => (float) $allocation->purchase_price,
                'note' => "横持ち出荷ID: {$allocation->id}",
            ],
        ];

        // request_idは "proxy-shipment-{allocation_id}" で重複防止
        $requestId = "proxy-shipment-{$allocation->id}";

        // べき等性: 同一request_idの既存queueが存在する場合はそのIDを返す
        $existingQueueId = DB::connection('sakemaru')
            ->table('stock_transfer_queue')
            ->where('request_id', $requestId)
            ->value('id');

        if ($existingQueueId) {
            Log::info('Stock transfer queue already exists (idempotent)', [
                'queue_id' => $existingQueueId,
                'allocation_id' => $allocation->id,
                'request_id' => $requestId,
            ]);

            return (int) $existingQueueId;
        }

        return DB::connection('sakemaru')->transaction(function () use (
            $allocation,
            $trade,
            $targetWarehouse,
            $toWarehouseCode,
            $items,
            $requestId
        ) {
            // stock_transfer_queueレコード作成
            $queueId = DB::connection('sakemaru')->table('stock_transfer_queue')->insertGetId([
                'client_id' => config('app.client_id'),
                'slip_number' => $trade->serial_id, // trades.serial_idを使用
                'process_date' => $allocation->shipment_date,
                'delivered_date' => $allocation->shipment_date,
                'note' => '横持ち出荷分',
                'items' => json_encode($items, JSON_UNESCAPED_UNICODE),
                'from_warehouse_code' => $targetWarehouse->code, // 横持ち出荷倉庫から
                'to_warehouse_code' => $toWarehouseCode,         // 実倉庫ベースで判定
                'request_id' => $requestId,
                'status' => 'BEFORE',
                'action_type' => 'CREATE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Log::info('Stock transfer queue created for horizontal shipment', [
                'queue_id' => $queueId,
                'allocation_id' => $allocation->id,
                'slip_number' => $trade->serial_id,
                'from_warehouse' => $targetWarehouse->code,
                'to_warehouse' => $toWarehouseCode,
                'picked_qty' => $allocation->picked_qty,
                'quantity_type' => $allocation->assign_qty_type,
            ]);

            return $queueId;
        });
    }

    /**
     * 横持ち出荷の最終配送先倉庫コードを実倉庫ベースで判定
     *
     * - shortage.earning_id → earnings.warehouse_id → 実倉庫解決（販売倉庫）
     * - shortage.warehouse_id → 実倉庫解決（欠品検出倉庫）
     * - 異なる実倉庫 → 販売倉庫の実倉庫コード（直接配送）
     * - 同一実倉庫 → sourceWarehouse.code（既存動作維持）
     */
    protected function determineToWarehouse(object $shortage, object $sourceWarehouse): string
    {
        // 販売倉庫（earningの倉庫）を取得
        $earningWarehouseId = null;
        if ($shortage->earning_id) {
            $earningWarehouseId = DB::connection('sakemaru')
                ->table('earnings')
                ->where('id', $shortage->earning_id)
                ->value('warehouse_id');
        }

        // 販売倉庫が取得できない場合は既存動作
        if (! $earningWarehouseId) {
            return $sourceWarehouse->code;
        }

        // 欠品検出倉庫（shortageのwarehouse_id）
        $shortageWarehouseId = $shortage->warehouse_id;

        // 実倉庫が同じかチェック
        if (WarehouseResolver::isSameRealWarehouse($earningWarehouseId, $shortageWarehouseId)) {
            // 同一実倉庫 → 既存動作維持
            return $sourceWarehouse->code;
        }

        // 異なる実倉庫 → 販売倉庫の実倉庫コードへ直接配送
        $realWarehouseCode = WarehouseResolver::getRealWarehouseCode($earningWarehouseId);

        return $realWarehouseCode ?? $sourceWarehouse->code;
    }
}
