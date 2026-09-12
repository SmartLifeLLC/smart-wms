<?php

namespace App\Filament\Resources\RealStocks\Pages;

use App\Filament\Resources\RealStocks\RealStockResource;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\RealStockLot;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;

class ViewRealStock extends ViewRecord
{
    protected static string $resource = RealStockResource::class;

    protected string $view = 'filament.resources.real-stocks.pages.view-real-stock';

    protected string $systemDate;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // システム日付を取得（client_settingsの最初のレコード）
        $this->systemDate = ClientSetting::cachedFirst()?->system_date ?? now()->toDateString();

        // Load additional relationships
        $this->record->load([
            'warehouse',
            'item.manufacturer',
            'item.item_category1',
            'item.item_category2',
            'item.item_category3',
            'activeLots.floor',
            'activeLots.location',
            'activeLots.purchase',
            'activeLots.buyerRestrictions.buyer',
        ]);
    }

    public function getTitle(): string
    {
        return '在庫詳細';
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function getCoreStockUrl(): string
    {
        return rtrim((string) config('app.core_url'), '/')."/stocks/form/{$this->record->getKey()}";
    }

    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            'wms-real-stock-iframe-page',
        ];
    }

    /**
     * 本日の入荷数を取得
     * 基幹システムと同じロジック（trades, purchases, stock_transfers テーブル使用）
     */
    public function getTodayIncoming(): array
    {
        $item = $this->record->item;
        $capacityCase = $item?->capacity_case ?? 1;
        $capacityCarton = $item?->capacity_carton ?? 1;

        // 仕入入荷（直送除く）
        $purchaseIncoming = DB::connection('sakemaru')
            ->table('trades')
            ->select(['trade_items.quantity_type'])
            ->selectRaw('SUM(trade_items.quantity) as quantity')
            ->where('trades.trade_category', 'PURCHASE')
            ->where('trade_items.item_id', $this->record->item_id)
            ->where('trades.is_active', true)
            ->leftJoin('purchases', 'trades.id', '=', 'purchases.trade_id')
            ->where('purchases.is_direct_delivery', false)
            ->where('purchases.warehouse_id', $this->record->warehouse_id)
            ->whereDate('purchases.delivered_date', $this->systemDate)
            ->leftJoin('trade_items', 'trades.id', '=', 'trade_items.trade_id')
            ->groupBy('trade_items.quantity_type')
            ->get();

        // 直送
        $directIncoming = DB::connection('sakemaru')
            ->table('trades')
            ->select(['trade_items.quantity_type'])
            ->selectRaw('SUM(trade_items.quantity) as quantity')
            ->where('trades.trade_category', 'PURCHASE')
            ->where('trade_items.item_id', $this->record->item_id)
            ->leftJoin('purchases', 'trades.id', '=', 'purchases.trade_id')
            ->where('purchases.is_direct_delivery', true)
            ->whereDate('purchases.delivered_date', $this->systemDate)
            ->leftJoin('trade_items', 'trades.id', '=', 'trade_items.trade_id')
            ->groupBy('trade_items.quantity_type')
            ->get();

        // 移動入荷
        $transferIncoming = DB::connection('sakemaru')
            ->table('stock_transfers')
            ->select(['trade_items.quantity_type'])
            ->selectRaw('SUM(trade_items.quantity) as quantity')
            ->where('to_warehouse_id', $this->record->warehouse_id)
            ->where('trade_items.item_id', $this->record->item_id)
            ->whereDate('delivered_date', $this->systemDate)
            ->leftJoin('trades', 'stock_transfers.trade_id', '=', 'trades.id')
            ->leftJoin('trade_items', 'trade_items.trade_id', '=', 'trades.id')
            ->groupBy('trade_items.quantity_type')
            ->get();

        return [
            'purchase_incoming' => $this->calculatePieceQuantity($purchaseIncoming, $capacityCase, $capacityCarton),
            'direct_incoming' => $this->calculatePieceQuantity($directIncoming, $capacityCase, $capacityCarton),
            'transfer_incoming' => $this->calculatePieceQuantity($transferIncoming, $capacityCase, $capacityCarton),
        ];
    }

    /**
     * 本日の出荷予定を取得
     * 基幹システムと同じロジック（trades, earnings, stock_transfers テーブル使用）
     */
    public function getTodayOutgoing(): array
    {
        $item = $this->record->item;
        $capacityCase = $item?->capacity_case ?? 1;
        $capacityCarton = $item?->capacity_carton ?? 1;

        // 出荷予定（is_delivered = false）
        $earningsNotDelivered = DB::connection('sakemaru')
            ->table('trades')
            ->select(['trade_items.quantity_type'])
            ->selectRaw('SUM(trade_items.quantity) as quantity')
            ->where('trades.trade_category', 'EARNING')
            ->where('trade_items.item_id', $this->record->item_id)
            ->where('trades.is_active', true)
            ->whereDate('earnings.delivered_date', $this->systemDate)
            ->where('earnings.warehouse_id', $this->record->warehouse_id)
            ->where('earnings.is_delivered', false)
            ->leftJoin('trade_items', 'trades.id', '=', 'trade_items.trade_id')
            ->leftJoin('earnings', 'trades.id', '=', 'earnings.trade_id')
            ->groupBy('trade_items.quantity_type')
            ->get();

        // 出荷済（is_delivered = true）
        $earningsDelivered = DB::connection('sakemaru')
            ->table('trades')
            ->select(['trade_items.quantity_type'])
            ->selectRaw('SUM(trade_items.quantity) as quantity')
            ->where('trades.trade_category', 'EARNING')
            ->where('trade_items.item_id', $this->record->item_id)
            ->where('trades.is_active', true)
            ->whereDate('earnings.delivered_date', $this->systemDate)
            ->where('earnings.warehouse_id', $this->record->warehouse_id)
            ->where('earnings.is_delivered', true)
            ->leftJoin('trade_items', 'trades.id', '=', 'trade_items.trade_id')
            ->leftJoin('earnings', 'trades.id', '=', 'earnings.trade_id')
            ->groupBy('trade_items.quantity_type')
            ->get();

        // 移動出荷
        $transferOutgoing = DB::connection('sakemaru')
            ->table('stock_transfers')
            ->select(['trade_items.quantity_type'])
            ->selectRaw('SUM(trade_items.quantity) as quantity')
            ->where('from_warehouse_id', $this->record->warehouse_id)
            ->where('trade_items.item_id', $this->record->item_id)
            ->whereDate('delivered_date', $this->systemDate)
            ->leftJoin('trades', 'stock_transfers.trade_id', '=', 'trades.id')
            ->leftJoin('trade_items', 'trade_items.trade_id', '=', 'trades.id')
            ->groupBy('trade_items.quantity_type')
            ->get();

        $notDeliveredQty = $this->calculatePieceQuantity($earningsNotDelivered, $capacityCase, $capacityCarton);
        $deliveredQty = $this->calculatePieceQuantity($earningsDelivered, $capacityCase, $capacityCarton);

        return [
            'total_reserved' => $notDeliveredQty + $deliveredQty,
            'total_shipped' => $deliveredQty,
            'transfer_outgoing' => $this->calculatePieceQuantity($transferOutgoing, $capacityCase, $capacityCarton),
        ];
    }

    /**
     * 数量をバラ換算する
     */
    private function calculatePieceQuantity($items, int $capacityCase, int $capacityCarton): int
    {
        $sum = 0;
        foreach ($items as $item) {
            if ($item->quantity_type === 'PIECE') {
                $sum += $item->quantity;
            } elseif ($item->quantity_type === 'CASE') {
                $sum += $item->quantity * $capacityCase;
            } elseif ($item->quantity_type === 'CARTON') {
                $sum += $item->quantity * $capacityCarton;
            }
        }

        return $sum;
    }

    /**
     * ロット一覧を取得（アクティブ + 使い切りの最新5件）
     */
    public function getLots(): array
    {
        $activeLots = $this->record->activeLots
            ->sortBy([
                ['expiration_date', 'asc'],
                ['created_at', 'asc'],
            ])
            ->values();

        $depletedLots = $this->record->lots()
            ->where('status', RealStockLot::STATUS_DEPLETED)
            ->latest()
            ->limit(5)
            ->get();

        return [
            'active' => $activeLots,
            'depleted' => $depletedLots,
        ];
    }

    /**
     * 在庫サマリーを取得
     */
    public function getStockSummary(): array
    {
        return [
            'current_quantity' => $this->record->current_quantity,
            'available_quantity' => $this->record->available_quantity,
            'reserved_quantity' => $this->record->reserved_quantity,
        ];
    }
}
