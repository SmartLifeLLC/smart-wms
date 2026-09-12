<?php

namespace App\Filament\Resources\WmsInventoryCount\Tables;

use App\Enums\PaginationOptions;
use App\Models\WmsInventoryCountItem;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

class WmsInventoryCountItemTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->modifyQueryUsing(fn ($query) => $query->withoutOwnedSetItems())
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->columns([
                TextColumn::make('floor_name')
                    ->label('フロア')
                    ->sortable(),

                TextColumn::make('location_code1')
                    ->label('エリア')
                    ->sortable(),

                TextColumn::make('location_no')
                    ->label('ロケーション')
                    ->sortable(),

                TextColumn::make('item_code')
                    ->label('商品CD')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('item_name')
                    ->label('商品名')
                    ->grow()
                    ->searchable(),

                TextColumn::make('lot_no')
                    ->label('ロットNo')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('expiration_date')
                    ->label('賞味期限')
                    ->date('Y/m/d')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('system_quantity')
                    ->label('理論在庫(開始)')
                    ->numeric(0)
                    ->alignEnd(),

                TextColumn::make('ending_system_quantity')
                    ->label('理論在庫')
                    ->numeric(0)
                    ->alignEnd()
                    ->placeholder('-'),

                TextColumn::make('first_count_quantity')
                    ->label('1回目')
                    ->numeric(0)
                    ->alignEnd()
                    ->placeholder('-'),

                TextColumn::make('first_count_difference')
                    ->label('1回目差分')
                    ->state(fn (WmsInventoryCountItem $record) => $record->roundDifference(1))
                    ->numeric(0)
                    ->alignEnd()
                    ->placeholder('-')
                    ->color(fn ($state) => match (true) {
                        $state === null => null,
                        (float) $state > 0 => 'success',
                        (float) $state < 0 => 'danger',
                        default => null,
                    }),

                TextColumn::make('first_count_actor_name')
                    ->label('1回目入力者')
                    ->placeholder('-'),

                TextColumn::make('second_count_quantity')
                    ->label('2回目')
                    ->numeric(0)
                    ->alignEnd()
                    ->placeholder('-'),

                TextColumn::make('second_count_difference')
                    ->label('2回目差分')
                    ->state(fn (WmsInventoryCountItem $record) => $record->roundDifference(2))
                    ->numeric(0)
                    ->alignEnd()
                    ->placeholder('-')
                    ->color(fn ($state) => match (true) {
                        $state === null => null,
                        (float) $state > 0 => 'success',
                        (float) $state < 0 => 'danger',
                        default => null,
                    }),

                TextColumn::make('second_count_actor_name')
                    ->label('2回目入力者')
                    ->placeholder('-'),

                TextColumn::make('final_count_quantity')
                    ->label('3回目')
                    ->numeric(0)
                    ->alignEnd()
                    ->placeholder('-'),

                TextColumn::make('final_count_difference')
                    ->label('3回目差分')
                    ->state(fn (WmsInventoryCountItem $record) => $record->roundDifference(3))
                    ->numeric(0)
                    ->alignEnd()
                    ->placeholder('-')
                    ->color(fn ($state) => match (true) {
                        $state === null => null,
                        (float) $state > 0 => 'success',
                        (float) $state < 0 => 'danger',
                        default => null,
                    }),

                TextColumn::make('final_count_actor_name')
                    ->label('3回目入力者')
                    ->placeholder('-'),

                TextColumn::make('difference_quantity')
                    ->label('差異数量')
                    ->numeric(0)
                    ->alignEnd()
                    ->placeholder('-')
                    ->color(fn (?string $state) => match (true) {
                        $state === null => null,
                        (float) $state > 0 => 'success',
                        (float) $state < 0 => 'danger',
                        default => null,
                    }),

                TextColumn::make('difference_amount')
                    ->label('差異金額')
                    ->numeric(0)
                    ->alignEnd()
                    ->placeholder('-')
                    ->prefix('¥')
                    ->color(fn (?string $state) => match (true) {
                        $state === null => null,
                        (float) $state > 0 => 'success',
                        (float) $state < 0 => 'danger',
                        default => null,
                    }),
            ])
            ->filters([
                SelectFilter::make('floor_name')
                    ->label('フロア')
                    ->options(fn () => WmsInventoryCountItem::query()
                        ->withoutOwnedSetItems()
                        ->distinct()
                        ->whereNotNull('floor_name')
                        ->pluck('floor_name', 'floor_name')
                        ->toArray()),

                SelectFilter::make('location_code1')
                    ->label('エリア')
                    ->options(fn () => WmsInventoryCountItem::query()
                        ->withoutOwnedSetItems()
                        ->distinct()
                        ->whereNotNull('location_code1')
                        ->pluck('location_code1', 'location_code1')
                        ->toArray()),

                TernaryFilter::make('uncounted')
                    ->label('未入力のみ')
                    ->queries(
                        true: fn ($query) => $query->whereNull('first_count_quantity'),
                        false: fn ($query) => $query->whereNotNull('first_count_quantity'),
                    ),

                TernaryFilter::make('has_difference')
                    ->label('差異ありのみ')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('difference_quantity')->where('difference_quantity', '!=', 0),
                        false: fn ($query) => $query->where(fn ($q) => $q->whereNull('difference_quantity')->orWhere('difference_quantity', 0)),
                    ),
            ])
            ->groups([
                Group::make('floor_name')
                    ->label('フロア'),
            ])
            ->defaultSort('floor_name')
            ->defaultSort('location_code1')
            ->defaultSort('location_code2')
            ->defaultSort('location_code3')
            ->extraAttributes(['class' => 'sticky-actions']);
    }
}
