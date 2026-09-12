<?php

namespace App\Filament\Resources\WmsIncomingCompleted\Tables;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\AutoOrder\OrderSource;
use App\Enums\AutoOrder\TransmissionType;
use App\Enums\PaginationOptions;
use App\Enums\QuantityType;
use App\Filament\Concerns\HasExportAction;
use App\Filament\Concerns\HasOptimizedFilters;
use App\Models\Sakemaru\ItemDefaultLocation;
use App\Models\Sakemaru\RealStock;
use App\Models\WmsOrderCalculationLog;
use App\Models\WmsOrderIncomingSchedule;
use App\Services\AutoOrder\IncomingTransmissionService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\View;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WmsIncomingCompletedTable
{
    use HasExportAction;
    use HasOptimizedFilters;

    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->extraAttributes(['class' => 'incoming-completed-table sticky-actions'])
            ->checkIfRecordIsSelectableUsing(fn (WmsOrderIncomingSchedule $record): bool => static::isPurchaseTransmissionSelectable($record)
                || static::canUpdateConfirmedIncomingSlipNumber($record)
                || static::canDeleteConfirmedIncoming($record))
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->alignCenter()
                    ->width('50px'),

                TextColumn::make('slip_number')
                    ->label('伝票番号')
                    ->searchable()
                    ->copyable()
                    ->placeholder('-')
                    ->toggleable()
                    ->width('130px'),

                TextColumn::make('order_source')
                    ->label('区分')
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
                    ->sortable()
                    ->alignCenter()
                    ->width('60px'),

                TextColumn::make('order_date')
                    ->label('発注日')
                    ->date('m/d')
                    ->sortable()
                    ->alignCenter()
                    ->width('70px'),

                TextColumn::make('expected_arrival_date')
                    ->label('予定日')
                    ->date('m/d')
                    ->sortable()
                    ->alignCenter()
                    ->width('70px'),

                TextColumn::make('confirmed_at')
                    ->label('データ連携時刻')
                    ->dateTime('m/d H:i')
                    ->sortable()
                    ->alignCenter()
                    ->width('85px'),

                TextColumn::make('warehouse.name')
                    ->label('入荷倉庫')
                    ->searchable()
                    ->toggleable()
                    ->width('120px'),

                TextColumn::make('contractor.code')
                    ->label('発注先CD')
                    ->searchable()
                    ->alignCenter()
                    ->toggleable()
                    ->width('50px'),

                TextColumn::make('contractor.name')
                    ->label('発注先名')
                    ->searchable()
                    ->toggleable()
                    ->width('100px'),

                TextColumn::make('item_code')
                    ->label('商品CD')
                    ->searchable()
                    ->sortable()
                    ->alignCenter()
                    ->width('70px'),

                TextColumn::make('search_code')
                    ->label('検索CD')
                    ->searchable()
                    ->limit(20)
                    ->placeholder('-')
                    ->width('120px'),

                TextColumn::make('item.name')
                    ->label('商品名')
                    ->searchable()
                    ->sortable()
                    ->grow(),

                TextColumn::make('capacity_case')
                    ->label('入り数')
                    ->state(fn ($record) => $record->item?->capacity_case)
                    ->numeric()
                    ->placeholder('-')
                    ->alignEnd()
                    ->width('60px'),

                TextColumn::make('expected_quantity')
                    ->label('ケース')
                    ->state(function ($record) {
                        $qty = $record->expected_quantity ?? 0;
                        $capacity = $record->item?->capacity_case;
                        if ($record->quantity_type === QuantityType::CASE) {
                            return $qty;
                        }
                        if (! $capacity || $capacity <= 1) {
                            return 0;
                        }

                        return (int) ($qty / $capacity);
                    })
                    ->formatStateUsing(fn ($state) => $state > 0 ? number_format($state) : '-')
                    ->alignEnd()
                    ->width('60px'),

                TextColumn::make('loose_quantity')
                    ->label('バラ')
                    ->state(function ($record) {
                        $qty = $record->expected_quantity ?? 0;
                        if ($record->quantity_type === QuantityType::CASE) {
                            return 0;
                        }
                        $capacity = $record->item?->capacity_case;
                        if (! $capacity || $capacity <= 1) {
                            return $qty;
                        }

                        return $qty % $capacity;
                    })
                    ->formatStateUsing(fn ($state) => $state > 0 ? number_format($state) : '-')
                    ->alignEnd()
                    ->width('60px'),

                TextColumn::make('total_piece_quantity')
                    ->label('発注総バラ')
                    ->state(fn (WmsOrderIncomingSchedule $record): int => $record->expected_piece_quantity)
                    ->numeric()
                    ->alignEnd()
                    ->width('70px'),

                TextColumn::make('received_quantity')
                    ->label('入荷総バラ')
                    ->state(fn (WmsOrderIncomingSchedule $record): int => $record->received_piece_quantity)
                    ->formatStateUsing(fn ($state) => $state > 0 ? number_format($state) : '-')
                    ->alignEnd()
                    ->width('70px'),

                TextColumn::make('expiration_date')
                    ->label('賞味期限')
                    ->date('Y/m/d')
                    ->sortable()
                    ->alignCenter()
                    ->placeholder('-')
                    ->width('90px'),

                TextColumn::make('computed_default_location')
                    ->label('ロケーション')
                    ->placeholder('-')
                    ->alignCenter()
                    ->width('100px'),

                TextColumn::make('computed_current_stock')
                    ->label('現在庫')
                    ->numeric()
                    ->alignEnd()
                    ->width('70px'),

                TextColumn::make('computed_available_stock')
                    ->label('有効在庫')
                    ->numeric()
                    ->alignEnd()
                    ->width('70px'),

                TextColumn::make('shipped_quantity')
                    ->label('出荷実績')
                    ->numeric()
                    ->alignEnd()
                    ->width('70px')
                    ->placeholder('-')
                    ->color(fn ($record) => $record->shipped_quantity > 0 && $record->shipped_quantity < $record->expected_quantity ? 'warning' : null),

                TextColumn::make('shortage_quantity')
                    ->label('欠品数')
                    ->numeric()
                    ->alignEnd()
                    ->color(fn ($state) => $state > 0 ? 'danger' : null)
                    ->placeholder('0')
                    ->width('70px'),

                TextColumn::make('purchase_unit_price')
                    ->label('仕入単価')
                    ->state(function ($record) {
                        if ($record->order_source !== OrderSource::RECEIVED) {
                            return $record->unit_price ?? $record->case_price;
                        }

                        $priceType = $record->price_type ?? 'PIECE';

                        return $priceType === 'CASE'
                            ? $record->partner_case_price
                            : $record->partner_unit_price;
                    })
                    ->money('JPY')
                    ->alignEnd()
                    ->width('90px'),

                // --- 以下、補助カラム ---

                TextColumn::make('warehouse.code')
                    ->label('倉庫CD')
                    ->searchable()
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('50px'),

                TextColumn::make('remaining')
                    ->label('残数')
                    ->state(fn ($record) => $record->remaining_quantity)
                    ->numeric()
                    ->alignEnd()
                    ->color(fn ($record) => $record->remaining_quantity > 0 ? 'warning' : 'success')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('70px'),

                TextColumn::make('is_receive_matched')
                    ->label('照合')
                    ->formatStateUsing(fn ($state) => $state ? '済' : '-')
                    ->badge()
                    ->color(fn ($state) => $state ? 'success' : 'gray')
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('50px'),

                TextColumn::make('unit_price')
                    ->label('自社単価')
                    ->money('JPY')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('90px'),

                TextColumn::make('partner_unit_price')
                    ->label('仕入先単価')
                    ->money('JPY')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('90px'),

                TextColumn::make('price_mismatch')
                    ->label('単価差')
                    ->state(function ($record) {
                        if ($record->price_type === 'CASE') {
                            if ($record->case_price !== null && $record->partner_case_price !== null
                                && (float) $record->case_price !== (float) $record->partner_case_price) {
                                return '不一致';
                            }
                        } elseif ($record->price_type === 'PIECE') {
                            if ($record->unit_price !== null && $record->partner_unit_price !== null
                                && (float) $record->unit_price !== (float) $record->partner_unit_price) {
                                return '不一致';
                            }
                        }

                        return null;
                    })
                    ->badge()
                    ->color('warning')
                    ->placeholder('-')
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('60px'),

                TextColumn::make('order_candidate_id')
                    ->label('発注候補ID')
                    ->alignCenter()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('80px'),

                TextColumn::make('manual_order_number')
                    ->label('発注番号')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('100px'),

                TextColumn::make('purchase_slip_number')
                    ->label('仕入伝票')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('100px'),

                TextColumn::make('note')
                    ->label('備考')
                    ->limit(30)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('作成日時')
                    ->dateTime('m/d H:i')
                    ->sortable()
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
                Filter::make('order_date')
                    ->label('発注日')
                    ->form([
                        DatePicker::make('order_date')
                            ->label('発注日'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['order_date'], fn (Builder $q, $date) => $q->where('order_date', $date))
                    )
                    ->indicateUsing(function (array $data): ?string {
                        if (! $data['order_date']) {
                            return null;
                        }

                        return '発注日: '.\Carbon\Carbon::parse($data['order_date'])->format('Y年m月d日');
                    }),

                Filter::make('expected_arrival_date')
                    ->label('入荷予定日')
                    ->form([
                        DatePicker::make('expected_arrival_date')
                            ->label('入荷予定日'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['expected_arrival_date'], fn (Builder $q, $date) => $q->where('expected_arrival_date', $date))
                    )
                    ->indicateUsing(function (array $data): ?string {
                        if (! $data['expected_arrival_date']) {
                            return null;
                        }

                        return '入荷予定日: '.\Carbon\Carbon::parse($data['expected_arrival_date'])->format('Y年m月d日');
                    }),

                Filter::make('actual_arrival_date')
                    ->label('入荷日')
                    ->form([
                        DatePicker::make('actual_arrival_date')
                            ->label('入荷日'),
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
                        'RECEIVED' => '受信',
                        'APP_UNPLANNED' => '予定なし',
                    ]),

                static::warehouseFilter(),

                static::contractorFilter(),
            ])
            ->recordActionsColumnLabel('操作')
            ->recordActions([
                Action::make('editConfirmedIncoming')
                    ->label('修正')
                    ->icon('heroicon-o-pencil-square')
                    ->color('warning')
                    ->visible(fn (WmsOrderIncomingSchedule $record): bool => $record->purchase_queue_id === null)
                    ->modalHeading('入荷確定データ修正')
                    ->modalDescription('仕入連携前の入荷確定データだけ修正できます。仕入キュー作成後は修正できません。')
                    ->modalWidth('5xl')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->schema(fn (WmsOrderIncomingSchedule $record): array => [
                        static::completedDetailView($record),
                        Grid::make(4)->schema([
                            TextInput::make('slip_number')
                                ->label('伝票番号')
                                ->default($record->slip_number)
                                ->maxLength(20)
                                ->required(),
                            TextInput::make('received_quantity')
                                ->label($record->quantity_type === QuantityType::CASE ? '入荷数量（ケース）' : '入荷数量（バラ）')
                                ->numeric()
                                ->minValue(0)
                                ->default((int) $record->received_quantity)
                                ->required(),
                            DatePicker::make('actual_arrival_date')
                                ->label('入荷日')
                                ->default($record->actual_arrival_date)
                                ->required(),
                            DatePicker::make('expiration_date')
                                ->label('賞味期限')
                                ->default($record->expiration_date),
                        ]),
                    ])
                    ->modalSubmitActionLabel('修正する')
                    ->modalCancelActionLabel('修正せず閉じる')
                    ->action(function (WmsOrderIncomingSchedule $record, array $data): void {
                        try {
                            DB::connection('sakemaru')->transaction(function () use ($record, $data): void {
                                $locked = WmsOrderIncomingSchedule::query()
                                    ->whereKey($record->id)
                                    ->lockForUpdate()
                                    ->firstOrFail();

                                if ($locked->purchase_queue_id !== null) {
                                    throw new \RuntimeException('仕入連携済みのため修正できません。');
                                }

                                if ($locked->status !== IncomingScheduleStatus::CONFIRMED) {
                                    throw new \RuntimeException('入荷完了ではないため修正できません。');
                                }

                                $receivedQuantity = max(0, (int) $data['received_quantity']);

                                $locked->update([
                                    'slip_number' => trim((string) $data['slip_number']),
                                    'received_quantity' => $receivedQuantity,
                                    'shipped_quantity' => $receivedQuantity,
                                    'shortage_quantity' => max(0, (int) $locked->expected_quantity - $receivedQuantity),
                                    'actual_arrival_date' => $data['actual_arrival_date'],
                                    'expiration_date' => $data['expiration_date'] ?? null,
                                ]);
                            });

                            Notification::make()
                                ->title('入荷確定データを修正しました')
                                ->success()
                                ->send();
                        } catch (\Throwable $throwable) {
                            Notification::make()
                                ->title('修正できませんでした')
                                ->body($throwable->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('deleteConfirmedIncoming')
                    ->label('削除')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (WmsOrderIncomingSchedule $record): bool => $record->purchase_queue_id === null)
                    ->requiresConfirmation()
                    ->modalHeading('入荷確定データを削除')
                    ->modalDescription('この入荷確定データを削除済みにし、入荷完了一覧から非表示にします。仕入連携済みのデータは削除できません。')
                    ->modalSubmitActionLabel('削除する')
                    ->modalCancelActionLabel('削除せず閉じる')
                    ->action(function (WmsOrderIncomingSchedule $record): void {
                        try {
                            $result = static::deleteConfirmedIncomingSchedules(collect([$record]));

                            $notification = Notification::make()
                                ->title($result['deleted_count'] > 0 ? '入荷確定データを削除しました' : '削除対象がありません')
                                ->body($result['deleted_count'] > 0
                                    ? '全数欠品の数量情報を残して削除済みにしました。'
                                    : '仕入連携済みまたは入荷完了以外のため削除できませんでした。');

                            ($result['deleted_count'] > 0 ? $notification->success() : $notification->warning())->send();
                        } catch (\Throwable $throwable) {
                            Notification::make()
                                ->title('削除できませんでした')
                                ->body($throwable->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->toolbarActions([
                static::getExportAction(),
                BulkActionGroup::make([
                    BulkAction::make('transmitSelectedPurchase')
                        ->label('仕入データ一括送信')
                        ->icon('heroicon-o-paper-airplane')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('仕入データ一括送信')
                        ->modalDescription(fn (Collection $records): string => "チェックした {$records->count()} 件だけを基幹システムの仕入キューに登録します。同一の倉庫・仕入先・伝票番号・入荷日の中で未選択のデータは未送信のまま残ります。")
                        ->modalSubmitActionLabel('チェック分を送信')
                        ->modalCancelActionLabel('送信せず閉じる')
                        ->action(function (Collection $records): void {
                            $scheduleIds = $records
                                ->pluck('id')
                                ->map(fn ($id): int => (int) $id)
                                ->filter(fn (int $id): bool => $id > 0)
                                ->unique()
                                ->values()
                                ->all();

                            try {
                                $result = app(IncomingTransmissionService::class)
                                    ->transmitConfirmedIncomings(scheduleIds: $scheduleIds);
                            } catch (\Throwable $e) {
                                Notification::make()
                                    ->title('登録エラー')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();

                                return;
                            }

                            if ($result['success']) {
                                Notification::make()
                                    ->title('仕入キューに登録しました')
                                    ->body("キュー: {$result['queue_count']}件 / 入荷データ: {$result['schedule_count']}件")
                                    ->success()
                                    ->send();
                            } else {
                                $errors = collect($result['errors'] ?? [])
                                    ->map(fn ($error): string => is_array($error) ? ($error['error'] ?? json_encode($error, JSON_UNESCAPED_UNICODE)) : (string) $error)
                                    ->take(5)
                                    ->implode("\n");

                                Notification::make()
                                    ->title('一部エラーが発生しました')
                                    ->body("成功: {$result['schedule_count']}件 / エラー: ".count($result['errors']).($errors !== '' ? "\n{$errors}" : ''))
                                    ->warning()
                                    ->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('bulkUpdateSlipNumber')
                        ->label('伝票番号一括変更')
                        ->icon('heroicon-o-pencil-square')
                        ->color('warning')
                        ->modalHeading('伝票番号一括変更')
                        ->modalDescription(fn (Collection $records): string => "チェックした {$records->count()} 件に同じ伝票番号を設定します。仕入連携済みのデータは修正できません。")
                        ->modalWidth('lg')
                        ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                        ->modalFooterActionsAlignment(Alignment::End)
                        ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('変更を適用')->color('danger'))
                        ->modalCancelActionLabel('変更せず閉じる')
                        ->schema([
                            TextInput::make('slip_number')
                                ->label('新しい伝票番号')
                                ->maxLength(20)
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            try {
                                $result = static::updateConfirmedIncomingSlipNumbers(
                                    $records,
                                    trim((string) ($data['slip_number'] ?? ''))
                                );
                            } catch (\Throwable $throwable) {
                                Notification::make()
                                    ->title('一括修正できませんでした')
                                    ->body($throwable->getMessage())
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $skippedCount = $result['selected_count'] - $result['updated_count'];

                            $notification = Notification::make()
                                ->title("{$result['updated_count']}件の伝票番号を修正しました")
                                ->body($skippedCount > 0
                                    ? "{$skippedCount}件は仕入連携済みまたは入荷完了以外のため除外しました。"
                                    : "伝票番号を {$result['slip_number']} に変更しました。");

                            ($result['updated_count'] > 0 ? $notification->success() : $notification->warning())->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('bulkDeleteConfirmedIncoming')
                        ->label('一括削除')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('一括削除')
                        ->modalDescription(fn (Collection $records): string => "チェックした {$records->count()} 件を削除済みにし、入荷完了一覧から非表示にします。仕入連携済みのデータは削除できません。")
                        ->modalSubmitActionLabel('チェック分を削除')
                        ->modalCancelActionLabel('削除せず閉じる')
                        ->action(function (Collection $records): void {
                            try {
                                $result = static::deleteConfirmedIncomingSchedules($records);
                            } catch (\Throwable $throwable) {
                                Notification::make()
                                    ->title('一括削除できませんでした')
                                    ->body($throwable->getMessage())
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $skippedCount = $result['selected_count'] - $result['deleted_count'];

                            $notification = Notification::make()
                                ->title("{$result['deleted_count']}件を削除しました")
                                ->body($skippedCount > 0
                                    ? "{$skippedCount}件は仕入連携済みまたは入荷完了以外のため除外しました。"
                                    : '全数欠品の数量情報を残して削除済みにしました。');

                            ($result['deleted_count'] > 0 ? $notification->success() : $notification->warning())->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort(
                fn (Builder $query): Builder => $query
                    ->orderBy('confirmed_at', 'desc')
                    ->orderBy('warehouse_id')
                    ->orderBy('item_id'),
                'desc'
            );
    }

    private static function completedDetailView(WmsOrderIncomingSchedule $record): View
    {
        $orderCandidate = $record->orderCandidate;
        $transferCandidate = $record->transferCandidate;
        $candidate = $orderCandidate ?? $transferCandidate;
        $log = null;
        $details = [];

        if ($candidate) {
            $warehouseId = $orderCandidate
                ? $orderCandidate->warehouse_id
                : $transferCandidate->satellite_warehouse_id;
            $log = WmsOrderCalculationLog::where('batch_code', $candidate->batch_code)
                ->where('warehouse_id', $warehouseId)
                ->where('item_id', $candidate->item_id)
                ->first();
            $details = $log?->calculation_details ?? [];
        }

        $item = $record->item;
        $capacityText = '-';
        if ($item) {
            $parts = [];
            if ($item->capacity_case) {
                $parts[] = "ケース: {$item->capacity_case}";
            }
            if ($item->capacity_carton) {
                $parts[] = "ボール: {$item->capacity_carton}";
            }
            $capacityText = implode(' / ', $parts) ?: '-';
        }

        $currentStock = 0;
        $availableStock = 0;
        if ($record->warehouse_id && $record->item_id) {
            $stockData = RealStock::where('warehouse_id', $record->warehouse_id)
                ->where('item_id', $record->item_id)
                ->selectRaw('SUM(current_quantity) as current_qty, SUM(available_quantity) as available_qty')
                ->first();
            $currentStock = $stockData->current_qty ?? 0;
            $availableStock = $stockData->available_qty ?? 0;
        }

        $defaultLocation = ItemDefaultLocation::getDefaultLocation(
            $record->warehouse_id,
            $record->item_id
        );
        $locationText = $defaultLocation ? "{$defaultLocation->code1}-{$defaultLocation->code2}-{$defaultLocation->code3}" : '-';

        $shiftedDays = (int) ($details['到着日調整'] ?? 0);
        $isDateManuallyChanged = false;
        $calculatedDateFormatted = null;
        if ($candidate?->original_arrival_date && $record->expected_arrival_date) {
            $calculatedDate = \Carbon\Carbon::parse($candidate->original_arrival_date)->addDays($shiftedDays);
            $calculatedDateFormatted = $calculatedDate->format('Y/m/d');
            $isDateManuallyChanged = $calculatedDate->format('Y-m-d') !== $record->expected_arrival_date->format('Y-m-d');
        }

        return View::make('filament.components.incoming-schedule-detail')
            ->viewData([
                'orderSource' => match ($record->order_source) {
                    OrderSource::AUTO => '発注',
                    OrderSource::MANUAL => '手動',
                    OrderSource::TRANSFER => '移動',
                    OrderSource::RECEIVED => $record->isUnassignedJxReceived() ? '不明' : '受信',
                    OrderSource::APP_UNPLANNED => '予定なし入荷',
                    default => '-',
                },
                'itemCode' => $record->item_code ?? $item?->code ?? '-',
                'searchCode' => $record->search_code ?? '-',
                'itemName' => $item?->name ?? '-',
                'packaging' => $item?->packaging ?? '-',
                'capacityText' => $capacityText,
                'capacityCase' => $item?->capacity_case ?? 0,
                'warehouseName' => $record->warehouse ? "[{$record->warehouse->code}]{$record->warehouse->name}" : '-',
                'contractorName' => $record->contractor ? "[{$record->contractor->code}]{$record->contractor->name}" : '-',
                'orderDate' => $record->order_date?->format('Y/m/d') ?? '-',
                'expectedArrivalDate' => $record->expected_arrival_date?->format('Y/m/d') ?? '-',
                'actualArrivalDateTime' => $record->confirmed_at?->format('Y/m/d H:i') ?? '-',
                'confirmedByName' => $record->confirmedByUser?->name ?? '-',
                'confirmedByPickerName' => $record->confirmedByPicker?->name ?? '-',
                'locationText' => $locationText,
                'expectedQuantity' => $record->expected_quantity ?? 0,
                'quantityType' => $record->quantity_type?->value,
                'receivedQuantity' => $record->received_piece_quantity,
                'remainingQuantity' => $record->remaining_quantity ?? 0,
                'status' => $record->status->label(),
                'statusColor' => $record->status->color(),
                'currentStock' => $currentStock,
                'availableStock' => $availableStock,
                'hasOrderCandidate' => $candidate !== null,
                'orderCandidateId' => $candidate?->id,
                'batchCodeFormatted' => $candidate?->batch_code
                    ? \Carbon\Carbon::createFromFormat('YmdHis', substr($candidate->batch_code, 0, 14))->format('Y/m/d H:i')
                    : null,
                'hasCalculationLog' => ! empty($details),
                'leadTimeDays' => $log?->lead_time_days ?? 0,
                'originalArrivalDate' => $candidate?->original_arrival_date
                    ? \Carbon\Carbon::parse($candidate->original_arrival_date)->format('m/d')
                    : null,
                'shiftedDays' => $shiftedDays,
                'shiftReasons' => $details['調整理由'] ?? '',
                'isDateManuallyChanged' => $isDateManuallyChanged,
                'calculatedDate' => $calculatedDateFormatted,
                'formula' => $details['計算式'] ?? '-',
                'effectiveStock' => $details['有効在庫'] ?? 0,
                'incomingStock' => $details['入庫予定数'] ?? 0,
                'safetyStock' => $details['安全在庫'] ?? 0,
                'shortageQty' => $details['不足数'] ?? 0,
                'purchaseUnit' => $details['最小仕入単位'] ?? 1,
                'transferIncoming' => $details['移動入庫予定'] ?? 0,
                'transferOutgoing' => $details['移動出庫予定'] ?? 0,
                'unitAdjustmentNote' => $details['単位調整説明'] ?? '',
                'orderQuantity' => $orderCandidate?->order_quantity ?? $transferCandidate?->transfer_quantity ?? $record->expected_quantity,
            ]);
    }

    private static function isPurchaseTransmissionSelectable(WmsOrderIncomingSchedule $record): bool
    {
        if (! in_array($record->order_source, [
            OrderSource::AUTO,
            OrderSource::MANUAL,
            OrderSource::RECEIVED,
            OrderSource::APP_UNPLANNED,
        ], true)) {
            return false;
        }

        if ($record->transfer_candidate_id || $record->source_warehouse_id || $record->stock_transfer_id) {
            return false;
        }

        return $record->contractor?->wmsSetting?->transmission_type !== TransmissionType::INTERNAL;
    }

    private static function canDeleteConfirmedIncoming(WmsOrderIncomingSchedule $record): bool
    {
        return $record->status === IncomingScheduleStatus::CONFIRMED
            && $record->purchase_queue_id === null;
    }

    private static function canUpdateConfirmedIncomingSlipNumber(WmsOrderIncomingSchedule $record): bool
    {
        return $record->status === IncomingScheduleStatus::CONFIRMED
            && $record->purchase_queue_id === null;
    }

    /**
     * @return array{selected_count: int, updated_count: int, slip_number: string}
     */
    private static function updateConfirmedIncomingSlipNumbers(Collection $records, string $slipNumber): array
    {
        if ($slipNumber === '') {
            throw new \InvalidArgumentException('伝票番号を入力してください。');
        }

        $scheduleIds = $records
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($scheduleIds === []) {
            return [
                'selected_count' => 0,
                'updated_count' => 0,
                'slip_number' => $slipNumber,
            ];
        }

        $updatedCount = DB::connection('sakemaru')->transaction(function () use ($scheduleIds, $slipNumber): int {
            $lockedSchedules = WmsOrderIncomingSchedule::query()
                ->whereKey($scheduleIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $updatedCount = 0;

            foreach ($lockedSchedules as $schedule) {
                if (! static::canUpdateConfirmedIncomingSlipNumber($schedule)) {
                    continue;
                }

                $schedule->update([
                    'slip_number' => $slipNumber,
                ]);

                $updatedCount++;
            }

            return $updatedCount;
        });

        return [
            'selected_count' => count($scheduleIds),
            'updated_count' => $updatedCount,
            'slip_number' => $slipNumber,
        ];
    }

    /**
     * @return array{selected_count: int, deleted_count: int}
     */
    private static function deleteConfirmedIncomingSchedules(Collection $records): array
    {
        $scheduleIds = $records
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($scheduleIds === []) {
            return [
                'selected_count' => 0,
                'deleted_count' => 0,
            ];
        }

        $deletedCount = DB::connection('sakemaru')->transaction(function () use ($scheduleIds): int {
            $lockedSchedules = WmsOrderIncomingSchedule::query()
                ->whereKey($scheduleIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $deletedCount = 0;

            foreach ($lockedSchedules as $schedule) {
                if (! static::canDeleteConfirmedIncoming($schedule)) {
                    continue;
                }

                $expectedQuantity = max(0, (int) $schedule->expected_quantity);

                $schedule->update([
                    'received_quantity' => 0,
                    'shipped_quantity' => 0,
                    'shortage_quantity' => $expectedQuantity,
                    'actual_arrival_date' => $schedule->actual_arrival_date
                        ?? $schedule->expected_arrival_date
                        ?? now()->toDateString(),
                    'status' => IncomingScheduleStatus::DELETED,
                    'confirmed_at' => $schedule->confirmed_at ?? now(),
                ]);

                $deletedCount++;
            }

            return $deletedCount;
        });

        return [
            'selected_count' => count($scheduleIds),
            'deleted_count' => $deletedCount,
        ];
    }
}
