<?php

namespace App\Filament\Resources\WmsPickingAreas\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class WmsPickingAreasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('warehouse_id')
                    ->label('倉庫')
                    ->formatStateUsing(function ($state) {
                        $warehouse = DB::connection('sakemaru')
                            ->table('warehouses')
                            ->where('id', $state)
                            ->first();
                        return $warehouse ? $warehouse->name : $state;
                    })
                    ->searchable()
                    ->sortable(),
                TextColumn::make('code')
                    ->label('エリアコード')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('エリア名')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('display_order')
                    ->label('表示順序')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('有効')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('wms_locations_count')
                    ->label('ロケーション数')
                    ->counts('wmsLocations')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('作成日時')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('更新日時')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('warehouse_id')
                    ->label('倉庫')
                    ->options(function () {
                        return DB::connection('sakemaru')
                            ->table('warehouses')
                            ->pluck('name', 'id');
                    }),
                SelectFilter::make('is_active')
                    ->label('有効状態')
                    ->options([
                        1 => '有効',
                        0 => '無効',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('display_order', 'asc');
    }
}
