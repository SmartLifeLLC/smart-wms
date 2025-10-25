<?php

namespace App\Filament\Resources\WarehouseContractors\Tables;

use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Warehouse;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WarehouseContractorsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('warehouse.name')
                    ->label('倉庫')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('contractor.name')
                    ->label('仕入先')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('lot_condition_case')
                    ->label('ケースロット')
                    ->numeric()
                    ->sortable()
                    ->default('-'),

                TextColumn::make('lot_condition_piece')
                    ->label('バラロット')
                    ->numeric()
                    ->sortable()
                    ->default('-'),

                TextColumn::make('lot_condition_price')
                    ->label('価格ロット')
                    ->numeric()
                    ->money('JPY')
                    ->sortable()
                    ->default('-'),

                IconColumn::make('prints_recommendation_sheet')
                    ->label('推奨書')
                    ->boolean()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('disable_automatic_order_conversion')
                    ->label('自動変換無効')
                    ->boolean()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_active')
                    ->label('有効')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('作成日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('更新日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('warehouse_id')
                    ->label('倉庫')
                    ->options(function () {
                        return Warehouse::query()
                            ->orderBy('name')
                            ->pluck('name', 'id');
                    }),

                SelectFilter::make('contractor_id')
                    ->label('仕入先')
                    ->options(function () {
                        return Contractor::query()
                            ->orderBy('name')
                            ->pluck('name', 'id');
                    }),

                SelectFilter::make('is_active')
                    ->label('有効状態')
                    ->options([
                        1 => '有効',
                        0 => '無効',
                    ]),
            ])
            ->defaultSort('warehouse_id', 'asc')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
