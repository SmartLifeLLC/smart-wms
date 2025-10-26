<?php

namespace App\Filament\Resources\Waves\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WavesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('wave_no')
                    ->label('Wave No')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('waveSetting.warehouse_id')
                    ->label('Warehouse')
                    ->sortable(),

                TextColumn::make('waveSetting.delivery_course_id')
                    ->label('Delivery Course')
                    ->sortable(),

                TextColumn::make('shipping_date')
                    ->label('Shipping Date')
                    ->date()
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'PENDING' => 'gray',
                        'PICKING' => 'info',
                        'SHORTAGE' => 'warning',
                        'COMPLETED' => 'success',
                        'CLOSED' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'PENDING' => 'Pending',
                        'PICKING' => 'Picking',
                        'SHORTAGE' => 'Shortage',
                        'COMPLETED' => 'Completed',
                        'CLOSED' => 'Closed',
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
            ->defaultSort('created_at', 'desc');
    }
}
