<?php

namespace App\Filament\Resources\WmsShortageAllocations\Tables;

use App\Enums\PaginationOptions;
use App\Filament\Concerns\HasExportAction;
use App\Models\WmsShortageAllocation;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WmsShortageAllocationsFinishedTable
{
    use HasExportAction;

    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->extraAttributes(['class' => 'sticky-actions'])
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->alignment('center'),

                TextColumn::make('shipment_date')
                    ->label('出荷日')
                    ->date('Y-m-d')
                    ->sortable()
                    ->alignment('center'),

                TextColumn::make('shortage.warehouse.name')
                    ->label('元倉庫')
                    ->searchable()
                    ->alignment('center'),

                TextColumn::make('targetWarehouse.name')
                    ->label('横持ち出荷倉庫')
                    ->sortable()
                    ->searchable()
                    ->alignment('center'),

                TextColumn::make('shortage.item.code')
                    ->label('商品コード')
                    ->searchable()
                    ->alignment('center'),

                TextColumn::make('shortage.item.name')
                    ->label('商品名')
                    ->searchable()
                    ->alignment('center'),

                TextColumn::make('shortage.item.volume')
                    ->label('容量')
                    ->alignment('center')
                    ->formatStateUsing(fn ($record) => $record->shortage?->item?->volume && $record->shortage?->item?->volume_unit
                            ? $record->shortage->item->volume.\App\Enums\EVolumeUnit::tryFrom($record->shortage->item->volume_unit)?->name()
                            : ''
                    ),

                TextColumn::make('shortage.item.case_size')
                    ->label('入り数')
                    ->alignment('center')
                    ->formatStateUsing(fn ($state) => $state ? $state : '-'),

                TextColumn::make('shortage.trade.partner.name')
                    ->label('得意先')
                    ->searchable()
                    ->limit(20)
                    ->alignment('center'),

                TextColumn::make('deliveryCourse.name')
                    ->label('配送コース')
                    ->searchable()
                    ->sortable()
                    ->alignment('center')
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('assign_qty_type')
                    ->label('単位')
                    ->formatStateUsing(fn (string $state): string => \App\Enums\QuantityType::tryFrom($state)?->name() ?? $state)
                    ->alignment('center'),

                TextColumn::make('assign_qty')
                    ->label('予定数')
                    ->alignment('center'),

                TextColumn::make('picked_qty')
                    ->label('ピック数')
                    ->alignment('center'),

                TextColumn::make('remaining_qty')
                    ->label('欠品数')
                    ->getStateUsing(fn (WmsShortageAllocation $record): int => $record->remaining_qty)
                    ->color(fn (WmsShortageAllocation $record): string => $record->remaining_qty > 0 ? 'warning' : 'success')
                    ->weight('bold')
                    ->alignment('center'),

                TextColumn::make('status')
                    ->label('ステータス')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'PENDING' => 'gray',
                        'RESERVED' => 'info',
                        'PICKING' => 'warning',
                        'FULFILLED' => 'success',
                        'SHORTAGE' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'PENDING' => '承認待ち',
                        'RESERVED' => '引当済み',
                        'PICKING' => 'ピッキング中',
                        'FULFILLED' => '完了',
                        'SHORTAGE' => '代理側欠品',
                        default => $state,
                    })
                    ->alignment('center'),

                TextColumn::make('is_synced')
                    ->label('同期')
                    ->badge()
                    ->getStateUsing(function (WmsShortageAllocation $record): string {
                        if ($record->status !== 'SHORTAGE') {
                            return '不要';
                        }

                        return $record->is_synced ? '同期済み' : '未同期';
                    })
                    ->color(function (WmsShortageAllocation $record): string {
                        if ($record->status !== 'SHORTAGE') {
                            return 'gray';
                        }

                        return $record->is_synced ? 'success' : 'warning';
                    })
                    ->alignment('center'),

                TextColumn::make('finished_at')
                    ->label('完了日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->alignment('center'),

                TextColumn::make('finishedUser.name')
                    ->label('完了者')
                    ->searchable()
                    ->alignment('center')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('作成日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->alignment('center')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('ステータス')
                    ->options([
                        'FULFILLED' => '完了',
                        'SHORTAGE' => '代理側欠品',
                    ]),

                SelectFilter::make('shortage.warehouse_id')
                    ->label('元倉庫')
                    ->relationship('shortage.warehouse', 'name')
                    ->searchable(),

                SelectFilter::make('target_warehouse_id')
                    ->label('横持ち出荷倉庫')
                    ->relationship('targetWarehouse', 'name')
                    ->searchable(),
            ])
            ->recordActionsColumnLabel('操作')
            ->recordActions([])
            ->bulkActions([])
            ->toolbarActions([
                static::getExportAction(),
            ])
            ->defaultSort('finished_at', 'desc');
    }
}
