<?php

namespace App\Filament\Resources\WmsLocations\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WmsLocationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('location.warehouse.name')
                    ->label('倉庫')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('location.joinedLocation')
                    ->label('ロケーションコード')
                    ->searchable(['code1', 'code2', 'code3'])
                    ->sortable(),

                TextColumn::make('location.name')
                    ->label('ロケーション名')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('pickingArea.name')
                    ->label('ピッキングエリア')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        '常温エリア' => 'success',
                        '冷蔵エリア' => 'info',
                        '冷凍エリア' => 'primary',
                        'ポークリプトエリア' => 'warning',
                        default => 'gray',
                    })
                    ->default('未設定')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('picking_unit_type')
                    ->label('引当単位')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'CASE' => 'ケース',
                        'PIECE' => 'バラ',
                        'BOTH' => '両方',
                        default => $state,
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'CASE' => 'info',
                        'PIECE' => 'success',
                        'BOTH' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('walking_order')
                    ->label('動線順序')
                    ->numeric()
                    ->sortable()
                    ->default('-'),

                TextColumn::make('location_display')
                    ->label('倉庫構造')
                    ->badge()
                    ->color('gray'),

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
                SelectFilter::make('wms_picking_area_id')
                    ->label('ピッキングエリア')
                    ->relationship('pickingArea', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('picking_unit_type')
                    ->label('引当単位')
                    ->options([
                        'CASE' => 'ケース',
                        'PIECE' => 'バラ',
                        'BOTH' => '両方',
                    ]),
            ])
            ->defaultSort('walking_order', 'asc')
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
