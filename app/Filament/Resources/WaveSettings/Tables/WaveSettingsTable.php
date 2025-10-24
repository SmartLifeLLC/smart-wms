<?php

namespace App\Filament\Resources\WaveSettings\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class WaveSettingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('warehouse_id')
                    ->label('Warehouse')
                    ->formatStateUsing(fn ($state) => DB::connection('sakemaru')
                        ->table('warehouses')
                        ->where('id', $state)
                        ->value('name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('delivery_course_id')
                    ->label('Delivery Course')
                    ->formatStateUsing(fn ($state) => DB::connection('sakemaru')
                        ->table('delivery_courses')
                        ->where('id', $state)
                        ->value('name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('picking_start_time')
                    ->label('Start Time')
                    ->time('H:i')
                    ->sortable(),

                TextColumn::make('picking_deadline_time')
                    ->label('Deadline Time')
                    ->time('H:i')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
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
