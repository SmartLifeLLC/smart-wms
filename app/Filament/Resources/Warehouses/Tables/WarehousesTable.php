<?php

namespace App\Filament\Resources\Warehouses\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WarehousesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('コード')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('name')
                    ->label('倉庫名')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('kana_name')
                    ->label('カナ名')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('abbreviation')
                    ->label('略称')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('branch.name')
                    ->label('支店')
                    ->searchable()
                    ->sortable()
                    ->default('-'),

                TextColumn::make('out_of_stock_option')
                    ->label('在庫切れ動作')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'IGNORE_STOCK' => '在庫無視',
                        'UP_TO_STOCK' => '在庫制限',
                        default => $state,
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'IGNORE_STOCK' => 'danger',
                        'UP_TO_STOCK' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),

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
                SelectFilter::make('is_active')
                    ->label('有効状態')
                    ->options([
                        1 => '有効',
                        0 => '無効',
                    ]),

                SelectFilter::make('out_of_stock_option')
                    ->label('在庫切れ動作')
                    ->options([
                        'IGNORE_STOCK' => '在庫無視',
                        'UP_TO_STOCK' => '在庫制限',
                    ]),
            ])
            ->defaultSort('code', 'asc')
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
