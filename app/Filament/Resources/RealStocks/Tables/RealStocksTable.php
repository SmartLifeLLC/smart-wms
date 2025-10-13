<?php

namespace App\Filament\Resources\RealStocks\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RealStocksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('warehouse.name')
                    ->label('倉庫')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('location.code')
                    ->label('ロケーション')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('item.name')
                    ->label('商品名')
                    ->sortable()
                    ->searchable()
                    ->limit(50),

                TextColumn::make('item.code')
                    ->label('商品コード')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('lot_no')
                    ->label('ロット番号')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('expiration_date')
                    ->label('賞味期限')
                    ->date('Y-m-d')
                    ->sortable(),

                TextColumn::make('current_quantity')
                    ->label('現在庫数')
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('available_quantity')
                    ->label('利用可能数')
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('wms_reserved_qty')
                    ->label('WMS引当')
                    ->numeric()
                    ->sortable()
                    ->alignEnd()
                    ->color(fn ($state) => $state > 0 ? 'warning' : null),

                TextColumn::make('wms_picking_qty')
                    ->label('WMSピッキング')
                    ->numeric()
                    ->sortable()
                    ->alignEnd()
                    ->color(fn ($state) => $state > 0 ? 'info' : null),

                TextColumn::make('received_at')
                    ->label('入庫日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('price')
                    ->label('単価')
                    ->money('JPY')
                    ->sortable()
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
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('received_at', 'desc');
    }
}
