<?php

namespace App\Filament\Resources\RealStocks\Tables;

use App\Enums\PaginationOptions;
use App\Filament\Concerns\HasExportAction;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\RealStock;
use App\Models\Sakemaru\RealStockLot;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class RealStocksTable
{
    use HasExportAction;

    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->extraAttributes(['class' => 'sticky-actions'])
            ->modifyQueryUsing(fn (Builder $query) => $query->addSelect([
                'default_location_code' => self::defaultLocationCodeSubquery(),
            ]))
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->columns([
                TextColumn::make('warehouse.name')
                    ->label('倉庫')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('default_location_code')
                    ->label('ロケーション')
                    ->placeholder('-'),

                TextColumn::make('item.name')
                    ->label('商品名')
                    ->sortable()
                    ->searchable()
                    ->limit(50),

                TextColumn::make('item.code')
                    ->label('商品コード')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('activeLots.expiration_date')
                    ->label('賞味期限')
                    ->date('Y-m-d')
                    ->listWithLineBreaks()
                    ->limitList(3),

                TextColumn::make('current_quantity')
                    ->label('現在庫数')
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('reserved_quantity')
                    ->label('引当済')
                    ->numeric()
                    ->sortable()
                    ->alignEnd()
                    ->color(fn ($state) => $state > 0 ? 'warning' : null),

                TextColumn::make('available_quantity')
                    ->label('利用可能数')
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('created_at')
                    ->label('登録日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('activeLots.price')
                    ->label('単価')
                    ->money('JPY')
                    ->listWithLineBreaks()
                    ->limitList(3)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('warehouse_id')
                    ->label('倉庫')
                    ->relationship('warehouse', 'name')
                    ->preload(),

                SelectFilter::make('item_id')
                    ->label('商品')
                    ->relationship('item', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActionsColumnLabel('操作')
            ->recordAction('view')
            ->recordActions([
                Action::make('view')
                    ->label('詳細')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (RealStock $record) => $record->item?->name ?? '在庫詳細')
                    ->modalWidth('screen')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->modalContent(fn (RealStock $record): View => view(
                        'filament.resources.real-stocks.modal.stock-form-iframe',
                        [
                            'record' => $record,
                            'coreStockUrl' => self::getCoreStockUrl($record),
                        ]
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('閉じる'),
            ])
            ->toolbarActions([
                static::getExportAction(),
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    private static function defaultLocationCodeSubquery()
    {
        return DB::connection('sakemaru')
            ->table('item_incoming_default_locations as idl')
            ->join('locations as l', 'idl.location_id', '=', 'l.id')
            ->selectRaw("TRIM(CONCAT_WS(' ', NULLIF(l.code1, ''), NULLIF(l.code2, ''), NULLIF(l.code3, '')))")
            ->whereColumn('idl.warehouse_id', 'real_stocks.warehouse_id')
            ->whereColumn('idl.item_id', 'real_stocks.item_id')
            ->limit(1);
    }

    private static function getCoreStockUrl(RealStock $record): string
    {
        return rtrim((string) config('app.core_url'), '/')."/stocks/form/{$record->getKey()}";
    }

    /**
     * モーダル表示用のデータを取得
     */
    private static function getModalData(RealStock $record): array
    {
        // リレーションをロード
        $record->load([
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

        // システム日付を取得
        $systemDate = ClientSetting::cachedFirst()?->system_date ?? now()->toDateString();

        $item = $record->item;
        $capacityCase = $item?->capacity_case ?? 1;
        $capacityCarton = $item?->capacity_carton ?? 1;

        // 本日入荷
        $incoming = self::getTodayIncoming($record, $systemDate, $capacityCase, $capacityCarton);

        // 本日出荷
        $outgoing = self::getTodayOutgoing($record, $systemDate, $capacityCase, $capacityCarton);

        // ロット一覧
        $activeLots = $record->activeLots
            ->sortBy([
                ['expiration_date', 'asc'],
                ['created_at', 'asc'],
            ])
            ->values();

        $depletedLots = $record->lots()
            ->where('status', RealStockLot::STATUS_DEPLETED)
            ->latest()
            ->limit(5)
            ->get();

        return [
            'record' => $record,
            'item' => $item,
            'warehouse' => $record->warehouse,
            'incoming' => $incoming,
            'outgoing' => $outgoing,
            'summary' => [
                'current_quantity' => $record->current_quantity,
                'available_quantity' => $record->available_quantity,
                'reserved_quantity' => $record->reserved_quantity,
            ],
            'lots' => [
                'active' => $activeLots,
                'depleted' => $depletedLots,
            ],
        ];
    }

    private static function getTodayIncoming(RealStock $record, string $systemDate, int $capacityCase, int $capacityCarton): array
    {
        // 仕入入荷（直送除く）
        $purchaseIncoming = DB::connection('sakemaru')
            ->table('trades')
            ->select(['trade_items.quantity_type'])
            ->selectRaw('SUM(trade_items.quantity) as quantity')
            ->where('trades.trade_category', 'PURCHASE')
            ->where('trade_items.item_id', $record->item_id)
            ->where('trades.is_active', true)
            ->leftJoin('purchases', 'trades.id', '=', 'purchases.trade_id')
            ->where('purchases.is_direct_delivery', false)
            ->where('purchases.warehouse_id', $record->warehouse_id)
            ->whereDate('purchases.delivered_date', $systemDate)
            ->leftJoin('trade_items', 'trades.id', '=', 'trade_items.trade_id')
            ->groupBy('trade_items.quantity_type')
            ->get();

        // 直送
        $directIncoming = DB::connection('sakemaru')
            ->table('trades')
            ->select(['trade_items.quantity_type'])
            ->selectRaw('SUM(trade_items.quantity) as quantity')
            ->where('trades.trade_category', 'PURCHASE')
            ->where('trade_items.item_id', $record->item_id)
            ->leftJoin('purchases', 'trades.id', '=', 'purchases.trade_id')
            ->where('purchases.is_direct_delivery', true)
            ->whereDate('purchases.delivered_date', $systemDate)
            ->leftJoin('trade_items', 'trades.id', '=', 'trade_items.trade_id')
            ->groupBy('trade_items.quantity_type')
            ->get();

        // 移動入荷
        $transferIncoming = DB::connection('sakemaru')
            ->table('stock_transfers')
            ->select(['trade_items.quantity_type'])
            ->selectRaw('SUM(trade_items.quantity) as quantity')
            ->where('to_warehouse_id', $record->warehouse_id)
            ->where('trade_items.item_id', $record->item_id)
            ->whereDate('delivered_date', $systemDate)
            ->leftJoin('trades', 'stock_transfers.trade_id', '=', 'trades.id')
            ->leftJoin('trade_items', 'trade_items.trade_id', '=', 'trades.id')
            ->groupBy('trade_items.quantity_type')
            ->get();

        return [
            'purchase_incoming' => self::calculatePieceQuantity($purchaseIncoming, $capacityCase, $capacityCarton),
            'direct_incoming' => self::calculatePieceQuantity($directIncoming, $capacityCase, $capacityCarton),
            'transfer_incoming' => self::calculatePieceQuantity($transferIncoming, $capacityCase, $capacityCarton),
        ];
    }

    private static function getTodayOutgoing(RealStock $record, string $systemDate, int $capacityCase, int $capacityCarton): array
    {
        // 出荷予定（is_delivered = false）
        $earningsNotDelivered = DB::connection('sakemaru')
            ->table('trades')
            ->select(['trade_items.quantity_type'])
            ->selectRaw('SUM(trade_items.quantity) as quantity')
            ->where('trades.trade_category', 'EARNING')
            ->where('trade_items.item_id', $record->item_id)
            ->where('trades.is_active', true)
            ->whereDate('earnings.delivered_date', $systemDate)
            ->where('earnings.warehouse_id', $record->warehouse_id)
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
            ->where('trade_items.item_id', $record->item_id)
            ->where('trades.is_active', true)
            ->whereDate('earnings.delivered_date', $systemDate)
            ->where('earnings.warehouse_id', $record->warehouse_id)
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
            ->where('from_warehouse_id', $record->warehouse_id)
            ->where('trade_items.item_id', $record->item_id)
            ->whereDate('delivered_date', $systemDate)
            ->leftJoin('trades', 'stock_transfers.trade_id', '=', 'trades.id')
            ->leftJoin('trade_items', 'trade_items.trade_id', '=', 'trades.id')
            ->groupBy('trade_items.quantity_type')
            ->get();

        $notDeliveredQty = self::calculatePieceQuantity($earningsNotDelivered, $capacityCase, $capacityCarton);
        $deliveredQty = self::calculatePieceQuantity($earningsDelivered, $capacityCase, $capacityCarton);

        return [
            'total_reserved' => $notDeliveredQty + $deliveredQty,
            'total_shipped' => $deliveredQty,
            'transfer_outgoing' => self::calculatePieceQuantity($transferOutgoing, $capacityCase, $capacityCarton),
        ];
    }

    private static function calculatePieceQuantity($items, int $capacityCase, int $capacityCarton): int
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
}
