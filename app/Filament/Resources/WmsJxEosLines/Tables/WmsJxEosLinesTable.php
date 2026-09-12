<?php

namespace App\Filament\Resources\WmsJxEosLines\Tables;

use App\Enums\PaginationOptions;
use App\Models\WmsJxEosImportBatch;
use App\Models\WmsJxEosSlip;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WmsJxEosLinesTable
{
    public static function configure(Table $table): Table
    {
        $batchId = request()->integer('batch_id') ?: null;

        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->extraAttributes(['class' => 'wms-jx-eos-lines-table sticky-actions'])
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with([
                    'importBatch.detectedContractor',
                    'importBatch.transmissionLog',
                    'document',
                    'slip',
                ])
                ->when($batchId, fn (Builder $query): Builder => $query->where('import_batch_id', $batchId))
                ->orderByDesc('id'))
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->alignCenter(),
                TextColumn::make('importBatch.imported_at')
                    ->label('取込日時')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('-'),
                TextColumn::make('importBatch.import_version')
                    ->label('版')
                    ->badge()
                    ->color(fn ($record): string => $record->importBatch?->is_current ? 'success' : 'gray')
                    ->formatStateUsing(fn ($state, $record): string => 'v'.($state ?: '-').($record->importBatch?->is_current ? ' 現行' : '')),
                TextColumn::make('importBatch.finet_code')
                    ->label('FINET')
                    ->badge()
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('importBatch.detected_contractor_code')
                    ->label('JX送信先')
                    ->placeholder('-')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('slip.contractor_code')
                    ->label('取引先CD')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('slip.slip_number')
                    ->label('伝票番号')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('slip.order_type_label')
                    ->label('発注区分')
                    ->badge()
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('slip.order_number')
                    ->label('発注番号')
                    ->placeholder('-')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('slip.slip_type_label')
                    ->label('出荷/返品')
                    ->badge()
                    ->color(fn ($state): string => $state === '返品' ? 'danger' : 'success')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('slip.warehouse_code')
                    ->label('倉庫')
                    ->state(fn ($record): string => trim(($record->slip?->warehouse_code ?? '-').' '.($record->slip?->warehouse_name ?? '')))
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('slip.delivery_date')
                    ->label('納品日')
                    ->date('Y-m-d')
                    ->placeholder('-'),
                TextColumn::make('line_number')
                    ->label('行')
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('jan_code')
                    ->label('JAN')
                    ->searchable()
                    ->copyable()
                    ->placeholder('-'),
                TextColumn::make('item_code')
                    ->label('商品CD')
                    ->searchable()
                    ->copyable()
                    ->placeholder('-'),
                TextColumn::make('product_name')
                    ->label('商品名')
                    ->searchable()
                    ->limit(28)
                    ->tooltip(fn ($state) => $state),
                TextColumn::make('pack_quantity')
                    ->label('入数')
                    ->numeric()
                    ->alignRight(),
                TextColumn::make('case_quantity')
                    ->label('ケース')
                    ->numeric()
                    ->alignRight(),
                TextColumn::make('piece_quantity')
                    ->label('バラ')
                    ->numeric()
                    ->alignRight(),
                TextColumn::make('total_quantity')
                    ->label('総バラ')
                    ->numeric()
                    ->alignRight(),
                TextColumn::make('unit_price')
                    ->label('単価')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2))
                    ->alignRight(),
                TextColumn::make('amount')
                    ->label('金額')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2))
                    ->alignRight()
                    ->toggleable(),
                IconColumn::make('is_shortage')
                    ->label('欠品')
                    ->boolean()
                    ->alignCenter()
                    ->toggleable(),
                TextColumn::make('source_record_no')
                    ->label('元行')
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('wms_jx_transmission_log_id')
                    ->label('JXログID')
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('current_import')
                    ->label('現行取込のみ')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'importBatch',
                        fn (Builder $batchQuery): Builder => $batchQuery->where('is_current', true)
                    )),
                SelectFilter::make('finet_code')
                    ->label('FINET')
                    ->options(fn () => WmsJxEosImportBatch::query()
                        ->whereNotNull('finet_code')
                        ->distinct()
                        ->orderBy('finet_code')
                        ->pluck('finet_code', 'finet_code')
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $value): Builder => $query->whereHas(
                            'importBatch',
                            fn (Builder $batchQuery): Builder => $batchQuery->where('finet_code', $value)
                        )
                    )),
                SelectFilter::make('contractor_code')
                    ->label('取引先CD')
                    ->options(fn () => WmsJxEosSlip::query()
                        ->whereNotNull('contractor_code')
                        ->distinct()
                        ->orderBy('contractor_code')
                        ->pluck('contractor_code', 'contractor_code')
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $value): Builder => $query->whereHas(
                            'slip',
                            fn (Builder $slipQuery): Builder => $slipQuery->where('contractor_code', $value)
                        )
                    )),
                Filter::make('delivery_date')
                    ->form([
                        DatePicker::make('from')
                            ->label('納品日 From'),
                        DatePicker::make('until')
                            ->label('納品日 To'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->whereHas('slip', function (Builder $slipQuery) use ($data): Builder {
                            return $slipQuery
                                ->when($data['from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('delivery_date', '>=', $date))
                                ->when($data['until'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('delivery_date', '<=', $date));
                        });
                    }),
                Filter::make('shortage')
                    ->label('欠品のみ')
                    ->query(fn (Builder $query): Builder => $query->where('is_shortage', true)),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('詳細'),
            ]);
    }
}
