<?php

namespace App\Filament\Resources\WmsWarehouseTransferCandidates\Tables;

use App\Enums\PaginationOptions;
use App\Enums\WarehouseTransferCandidateStatus;
use App\Filament\Resources\WmsWarehouseTransferCandidates\Support\WarehouseTransferCandidateActions;
use App\Filament\Resources\WmsWarehouseTransferCandidates\WmsWarehouseTransferCandidateResource;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsWarehouseTransferCandidate;
use App\Services\WarehouseTransfer\WarehouseTransferStatusSyncService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WmsWarehouseTransferCandidatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('status')
                    ->label('状態')
                    ->badge()
                    ->formatStateUsing(fn (WarehouseTransferCandidateStatus $state) => $state->label())
                    ->color(fn (WarehouseTransferCandidateStatus $state) => $state->color())
                    ->sortable(),

                TextColumn::make('candidate_no')
                    ->label('候補番号')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('source_type')
                    ->label('起点')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('submitted_at')
                    ->label('受信日時')
                    ->dateTime('m/d H:i')
                    ->placeholder('-')
                    ->sortable(),

                TextColumn::make('from_warehouse_code')
                    ->label('移動元倉庫CD')
                    ->sortable(),

                TextColumn::make('from_warehouse_name')
                    ->label('移動元倉庫名'),

                TextColumn::make('to_warehouse_code')
                    ->label('移動先倉庫CD')
                    ->sortable(),

                TextColumn::make('to_warehouse_name')
                    ->label('移動先倉庫名'),

                TextColumn::make('process_date')
                    ->label('処理日')
                    ->date('m/d')
                    ->sortable(),

                TextColumn::make('delivered_date')
                    ->label('納品日')
                    ->date('m/d')
                    ->sortable(),

                TextColumn::make('items_count')
                    ->label('明細数')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('items_sum_transfer_quantity')
                    ->label('総バラ')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => number_format((float) $state))
                    ->sortable(),

                TextColumn::make('submitter')
                    ->label('送信者')
                    ->state(fn (WmsWarehouseTransferCandidate $record) => trim(implode(' / ', array_filter([
                        $record->submittedByPicker?->name,
                        $record->submitted_device_id,
                    ]))) ?: '-'),

                TextColumn::make('queue_status')
                    ->label('queue')
                    ->badge()
                    ->state(fn (WmsWarehouseTransferCandidate $record) => static::queueLabel($record))
                    ->color(fn (string $state) => match ($state) {
                        '伝票作成済' => 'success',
                        '伝票作成失敗' => 'danger',
                        '処理中', 'queue待ち' => 'info',
                        default => 'gray',
                    }),

                TextColumn::make('stock_transfer_id')
                    ->label('移動ID')
                    ->placeholder('-'),

                TextColumn::make('confirmed_at')
                    ->label('確定日時')
                    ->dateTime('m/d H:i')
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('状態')
                    ->multiple()
                    ->options(WarehouseTransferCandidateStatus::options())
                    ->default([WarehouseTransferCandidateStatus::PENDING->value]),

                static::warehouseFilter('from_warehouse_id', '移動元倉庫'),
                static::warehouseFilter('to_warehouse_id', '移動先倉庫'),

                Filter::make('submitted_at')
                    ->label('受信日')
                    ->schema([
                        DatePicker::make('from')->label('受信日 From'),
                        DatePicker::make('until')->label('受信日 To'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('submitted_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('submitted_at', '<=', $d))),

                Filter::make('confirmed_at')
                    ->label('確定日')
                    ->schema([
                        DatePicker::make('from')->label('確定日 From'),
                        DatePicker::make('until')->label('確定日 To'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('confirmed_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('confirmed_at', '<=', $d))),

                Filter::make('item')
                    ->label('商品')
                    ->schema([
                        TextInput::make('keyword')->label('商品CD / 商品名'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $keyword = trim((string) ($data['keyword'] ?? ''));
                        if ($keyword === '') {
                            return $query;
                        }
                        $keyword = mb_convert_kana($keyword, 'as');

                        return $query->whereHas('items', fn ($q) => $q
                            ->where('item_code', 'like', "%{$keyword}%")
                            ->orWhere('item_name', 'like', "%{$keyword}%"));
                    }),

                Filter::make('submitter')
                    ->label('端末 / 送信者')
                    ->schema([
                        TextInput::make('keyword')->label('端末ID / 送信者名'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $keyword = trim((string) ($data['keyword'] ?? ''));
                        if ($keyword === '') {
                            return $query;
                        }

                        return $query->where(fn ($q) => $q
                            ->where('submitted_device_id', 'like', "%{$keyword}%")
                            ->orWhereHas('submittedByPicker', fn ($p) => $p->where('name', 'like', "%{$keyword}%")));
                    }),
            ])
            ->recordActionsColumnLabel('操作')
            ->recordActions([
                Action::make('view')
                    ->label('詳細')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn (WmsWarehouseTransferCandidate $record) => WmsWarehouseTransferCandidateResource::getUrl('view', ['record' => $record])),

                WarehouseTransferCandidateActions::confirm(),
                WarehouseTransferCandidateActions::cancel(),
                WarehouseTransferCandidateActions::retry(),
            ], position: RecordActionsPosition::AfterColumns)
            ->extraAttributes(['class' => 'sticky-actions']);
    }

    protected static function warehouseFilter(string $column, string $label): SelectFilter
    {
        return SelectFilter::make($column)
            ->label($label)
            ->searchable()
            ->getSearchResultsUsing(function (string $search): array {
                $search = mb_convert_kana($search, 'as');

                return Warehouse::query()
                    ->where('is_active', true)
                    ->where(fn ($q) => $q
                        ->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%"))
                    ->orderBy('code')
                    ->limit(50)
                    ->get()
                    ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}]{$w->name}"])
                    ->toArray();
            })
            ->getOptionLabelUsing(function ($value): ?string {
                $w = Warehouse::find($value);

                return $w ? "[{$w->code}]{$w->name}" : null;
            });
    }

    public static function queueLabel(WmsWarehouseTransferCandidate $record): string
    {
        static $cache = [];

        if (! $record->queue_request_id) {
            return '未投入';
        }

        if (! array_key_exists($record->queue_request_id, $cache)) {
            $queues = app(WarehouseTransferStatusSyncService::class)->queuesByRequestId([$record->queue_request_id]);
            $cache[$record->queue_request_id] = $queues[$record->queue_request_id] ?? null;
        }

        return $record->queueStatusLabel($cache[$record->queue_request_id]);
    }
}
