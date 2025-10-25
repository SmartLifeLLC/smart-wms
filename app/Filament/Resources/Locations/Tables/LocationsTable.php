<?php

namespace App\Filament\Resources\Locations\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use App\Models\Sakemaru\Warehouse;

class LocationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('warehouse.name')
                    ->label('倉庫')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('code1')
                    ->label('コード1')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('code2')
                    ->label('コード2')
                    ->searchable()
                    ->sortable()
                    ->default('-'),

                TextColumn::make('code3')
                    ->label('コード3')
                    ->searchable()
                    ->sortable()
                    ->default('-'),

                TextColumn::make('joinedLocation')
                    ->label('統合コード')
                    ->badge()
                    ->color('gray')
                    ->searchable(['code1', 'code2', 'code3']),

                TextColumn::make('name')
                    ->label('ロケーション名')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

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
