<?php

namespace App\Filament\Resources\WmsPickers\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class WmsPickersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('コード')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label('名前')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('defaultWarehouse.name')
                    ->label('デフォルト倉庫')
                    ->sortable()
                    ->default('-'),

                IconColumn::make('is_active')
                    ->label('有効')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('登録日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('有効/無効')
                    ->placeholder('すべて')
                    ->trueLabel('有効のみ')
                    ->falseLabel('無効のみ'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('code', 'asc');
    }
}
