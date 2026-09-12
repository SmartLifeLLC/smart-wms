<?php

namespace App\Filament\Resources\WmsIncomingTransmitted\Tables;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\AutoOrder\OrderSource;
use App\Enums\PaginationOptions;
use App\Filament\Concerns\HasExportAction;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsOrderIncomingSchedule;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WmsIncomingTransmittedTable
{
    use HasExportAction;

    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->width('60px'),

                TextColumn::make('status')
                    ->label('ステータス')
                    ->badge()
                    ->formatStateUsing(fn (IncomingScheduleStatus $state): string => $state->label())
                    ->color(fn (IncomingScheduleStatus $state): string => $state->color())
                    ->sortable()
                    ->width('90px'),

                TextColumn::make('order_source')
                    ->label('入荷区分')
                    ->badge()
                    ->formatStateUsing(fn (OrderSource $state, WmsOrderIncomingSchedule $record): string => $record->isUnassignedJxReceived() ? '不明' : match ($state) {
                        OrderSource::AUTO => '発注',
                        OrderSource::MANUAL => '手動',
                        OrderSource::TRANSFER => '移動',
                        OrderSource::RECEIVED => '受信',
                        OrderSource::APP_UNPLANNED => '予定なし',
                    })
                    ->color(fn (OrderSource $state, WmsOrderIncomingSchedule $record): string => $record->isUnassignedJxReceived() ? 'warning' : match ($state) {
                        OrderSource::AUTO => 'info',
                        OrderSource::MANUAL => 'gray',
                        OrderSource::TRANSFER => 'warning',
                        OrderSource::RECEIVED => 'success',
                        OrderSource::APP_UNPLANNED => 'warning',
                    })
                    ->width('60px'),

                TextColumn::make('warehouse.name')
                    ->label('倉庫')
                    ->state(fn ($record) => $record->warehouse ? "[{$record->warehouse->code}]{$record->warehouse->name}" : '-')
                    ->searchable()
                    ->sortable()
                    ->width('150px'),

                TextColumn::make('item.code')
                    ->label('商品コード')
                    ->searchable()
                    ->sortable()
                    ->alignCenter()
                    ->width('100px'),

                TextColumn::make('item.name')
                    ->label('商品名')
                    ->searchable()
                    ->sortable()
                    ->grow(),

                TextColumn::make('contractor.name')
                    ->label('発注先')
                    ->state(fn ($record) => $record->contractor ? "[{$record->contractor->code}]{$record->contractor->name}" : '-')
                    ->searchable()
                    ->sortable()
                    ->toggleable()
                    ->width('120px'),

                TextColumn::make('received_quantity')
                    ->label('入荷数')
                    ->numeric()
                    ->alignEnd()
                    ->width('70px'),

                TextColumn::make('quantity_type')
                    ->label('単位')
                    ->formatStateUsing(fn ($state) => match ($state?->value ?? $state) {
                        'PIECE' => 'バラ',
                        'CASE' => 'ケース',
                        'CARTON' => 'ボール',
                        default => '-',
                    })
                    ->alignCenter()
                    ->width('60px'),

                TextColumn::make('expected_arrival_date')
                    ->label('入荷予定日')
                    ->date('m/d')
                    ->sortable()
                    ->alignCenter()
                    ->width('80px'),

                TextColumn::make('actual_arrival_date')
                    ->label('入荷日')
                    ->date('m/d')
                    ->sortable()
                    ->alignCenter()
                    ->width('70px'),

                TextColumn::make('purchase_queue_id')
                    ->label('キューID')
                    ->sortable()
                    ->alignCenter()
                    ->placeholder('-')
                    ->width('80px'),

                TextColumn::make('purchase_slip_number')
                    ->label('仕入伝票')
                    ->placeholder('-')
                    ->toggleable()
                    ->width('100px'),

                TextColumn::make('updated_at')
                    ->label('連携日時')
                    ->dateTime('m/d H:i')
                    ->sortable()
                    ->alignCenter()
                    ->width('90px'),

                TextColumn::make('note')
                    ->label('備考')
                    ->limit(30)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('confirmedByUser.name')
                    ->label('入荷担当者')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('100px'),

                TextColumn::make('confirmedByPicker.name')
                    ->label('入荷ピッカー')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('100px'),
            ])
            ->filters([
                Filter::make('actual_arrival_date')
                    ->label('入荷日')
                    ->form([
                        DatePicker::make('actual_arrival_date')
                            ->label('入荷日')
                            ->default(ClientSetting::systemDateYMD()),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['actual_arrival_date'], fn (Builder $q, $date) => $q->where('actual_arrival_date', $date))
                    )
                    ->indicateUsing(function (array $data): ?string {
                        if (! $data['actual_arrival_date']) {
                            return null;
                        }

                        return '入荷日: '.\Carbon\Carbon::parse($data['actual_arrival_date'])->format('Y年m月d日');
                    }),

                SelectFilter::make('order_source')
                    ->label('入荷区分')
                    ->options([
                        'AUTO' => '発注',
                        'MANUAL' => '手動',
                        'TRANSFER' => '移動',
                        'RECEIVED' => '受信',
                        'APP_UNPLANNED' => '予定なし',
                    ]),

                SelectFilter::make('warehouse_id')
                    ->label('倉庫')
                    ->searchable()
                    ->getSearchResultsUsing(function (string $search): array {
                        $search = mb_convert_kana($search, 'as');

                        return Warehouse::query()
                            ->where('is_active', true)
                            ->where(function ($query) use ($search) {
                                $query->where('code', 'like', "%{$search}%")
                                    ->orWhere('name', 'like', "%{$search}%");
                            })
                            ->orderBy('code')
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}]{$w->name}"])
                            ->toArray();
                    }),

                SelectFilter::make('contractor_id')
                    ->label('発注先')
                    ->multiple()
                    ->searchable()
                    ->getSearchResultsUsing(function (string $search): array {
                        $search = mb_convert_kana($search, 'as');

                        return Contractor::query()
                            ->where(function ($query) use ($search) {
                                $query->where('code', 'like', "%{$search}%")
                                    ->orWhere('name', 'like', "%{$search}%");
                            })
                            ->orderBy('code')
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn ($c) => [$c->id => "[{$c->code}]{$c->name}"])
                            ->toArray();
                    }),
            ])
            ->recordActions([
                Action::make('viewDetail')
                    ->label('詳細')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading('仕入連携済み詳細')
                    ->modalWidth('lg')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('閉じる')
                    ->schema(function ($record) {
                        return [
                            \Filament\Schemas\Components\Section::make('基本情報')
                                ->schema([
                                    \Filament\Infolists\Components\TextEntry::make('warehouse')
                                        ->label('倉庫')
                                        ->state(fn () => $record->warehouse ? "[{$record->warehouse->code}]{$record->warehouse->name}" : '-'),
                                    \Filament\Infolists\Components\TextEntry::make('item')
                                        ->label('商品')
                                        ->state(fn () => $record->item ? "[{$record->item->code}]{$record->item->name}" : '-'),
                                    \Filament\Infolists\Components\TextEntry::make('contractor')
                                        ->label('発注先')
                                        ->state(fn () => $record->contractor ? "[{$record->contractor->code}]{$record->contractor->name}" : '-'),
                                ]),
                            \Filament\Schemas\Components\Section::make('入荷・連携情報')
                                ->schema([
                                    \Filament\Infolists\Components\TextEntry::make('received_quantity')
                                        ->label('入荷数量')
                                        ->state(fn () => $record->received_quantity),
                                    \Filament\Infolists\Components\TextEntry::make('expected_arrival_date')
                                        ->label('入荷予定日')
                                        ->state(fn () => $record->expected_arrival_date?->format('Y/m/d') ?? '-'),
                                    \Filament\Infolists\Components\TextEntry::make('actual_arrival_date')
                                        ->label('入荷日')
                                        ->state(fn () => $record->actual_arrival_date?->format('Y/m/d') ?? '-'),
                                    \Filament\Infolists\Components\TextEntry::make('purchase_queue_id')
                                        ->label('仕入キューID')
                                        ->state(fn () => $record->purchase_queue_id ?? '-'),
                                    \Filament\Infolists\Components\TextEntry::make('purchase_slip_number')
                                        ->label('仕入伝票番号')
                                        ->state(fn () => $record->purchase_slip_number ?? '-'),
                                ]),
                        ];
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('閉じる'),
            ])
            ->toolbarActions([
                static::getExportAction(),
            ])
            ->defaultSort('updated_at', 'desc');
    }
}
