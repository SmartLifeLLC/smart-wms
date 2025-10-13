<?php

namespace App\Filament\Resources\WmsReceiptInspections\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WmsReceiptInspectionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('inspection_no')
                    ->label('検品番号')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('purchase.id')
                    ->label('入荷予定ID')
                    ->sortable(),

                TextColumn::make('warehouse.name')
                    ->label('倉庫')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('status')
                    ->label('ステータス')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'PENDING' => 'gray',
                        'IN_PROGRESS' => 'warning',
                        'COMPLETED' => 'success',
                        'CANCELLED' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'PENDING' => '未着手',
                        'IN_PROGRESS' => '進行中',
                        'COMPLETED' => '完了',
                        'CANCELLED' => 'キャンセル',
                        default => $state,
                    })
                    ->sortable(),

                TextColumn::make('inspector.name')
                    ->label('作業者')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('inspected_at')
                    ->label('検品日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('作成日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('ステータス')
                    ->options([
                        'PENDING' => '未着手',
                        'IN_PROGRESS' => '進行中',
                        'COMPLETED' => '完了',
                        'CANCELLED' => 'キャンセル',
                    ]),

                SelectFilter::make('warehouse_id')
                    ->label('倉庫')
                    ->relationship('warehouse', 'name'),
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
            ->defaultSort('created_at', 'desc');
    }
}
