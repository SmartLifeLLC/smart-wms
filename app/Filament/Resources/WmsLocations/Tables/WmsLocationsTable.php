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

                TextColumn::make('zone_code')
                    ->label('ゾーン')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        '常温' => 'success',
                        '冷蔵' => 'info',
                        '冷凍' => 'primary',
                        '危険物' => 'danger',
                        default => 'gray',
                    })
                    ->default('未設定')
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
                SelectFilter::make('picking_unit_type')
                    ->label('引当単位')
                    ->options([
                        'CASE' => 'ケース',
                        'PIECE' => 'バラ',
                        'BOTH' => '両方',
                    ]),

                SelectFilter::make('zone_code')
                    ->label('ゾーン')
                    ->options([
                        '常温' => '常温',
                        '冷蔵' => '冷蔵',
                        '冷凍' => '冷凍',
                        '危険物' => '危険物',
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
