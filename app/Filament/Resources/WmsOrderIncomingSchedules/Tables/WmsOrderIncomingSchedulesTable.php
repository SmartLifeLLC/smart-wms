<?php

namespace App\Filament\Resources\WmsOrderIncomingSchedules\Tables;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\AutoOrder\OrderSource;
use App\Enums\PaginationOptions;
use App\Enums\QuantityType;
use App\Filament\Concerns\HasExportAction;
use App\Filament\Concerns\HasOptimizedFilters;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\ItemDefaultLocation;
use App\Models\Sakemaru\Location;
use App\Models\Sakemaru\RealStock;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsOrderCalculationLog;
use App\Models\WmsOrderIncomingSchedule;
use App\Services\AutoOrder\IncomingConfirmationService;
use App\Services\AutoOrder\OrderCancellationService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\View;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class WmsOrderIncomingSchedulesTable
{
    use HasExportAction;
    use HasOptimizedFilters;

    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->extraAttributes(['class' => 'incoming-schedules-table sticky-actions'])
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->alignCenter()
                    ->width('50px'),

                TextColumn::make('order_source')
                    ->label('区分')
                    ->badge()
                    ->formatStateUsing(fn (OrderSource $state): string => match ($state) {
                        OrderSource::AUTO => '発注',
                        OrderSource::MANUAL => '手動',
                        OrderSource::TRANSFER => '移動',
                        OrderSource::RECEIVED => '受信',
                        OrderSource::APP_UNPLANNED => '予定なし',
                    })
                    ->color(fn (OrderSource $state): string => match ($state) {
                        OrderSource::AUTO => 'info',
                        OrderSource::MANUAL => 'gray',
                        OrderSource::TRANSFER => 'warning',
                        OrderSource::RECEIVED => 'success',
                        OrderSource::APP_UNPLANNED => 'warning',
                    })
                    ->sortable()
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('60px'),

                TextColumn::make('is_eos_sent_for_table')
                    ->label('EOS送信')
                    ->state(fn (WmsOrderIncomingSchedule $record): bool => (bool) ($record->getAttribute('is_eos_sent_for_table') ?? $record->isEosSent()))
                    ->formatStateUsing(fn (bool $state): string => $state ? '済' : '-')
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->alignCenter()
                    ->width('70px'),

                TextColumn::make('created_at')
                    ->label('作成日')
                    ->dateTime('m/d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('95px'),

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

                TextColumn::make('slip_number')
                    ->label('伝票番号')
                    ->searchable()
                    ->copyable()
                    ->placeholder('-')
                    ->width('130px'),

                TextColumn::make('contractor.code')
                    ->label('発注先CD')
                    ->searchable()
                    ->alignCenter()
                    ->width('70px'),

                TextColumn::make('contractor.name')
                    ->label('発注先')
                    ->searchable()
                    ->width('120px'),

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
                            return $qty; // order_quantityがケース数
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
                            return 0; // ケース行にバラ端数はない
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
                    ->label('総バラ数')
                    ->state(function ($record) {
                        $qty = $record->expected_quantity ?? 0;
                        if ($qty === 0) {
                            return 0;
                        }
                        $quantityType = $record->quantity_type;
                        if ($quantityType === QuantityType::CASE) {
                            $capacity = $record->item?->capacity_case ?? 1;

                            return $qty * $capacity;
                        }
                        if ($quantityType === QuantityType::CARTON) {
                            $capacity = $record->item?->capacity_carton ?? 1;

                            return $qty * $capacity;
                        }

                        return $qty;
                    })
                    ->numeric()
                    ->alignEnd()
                    ->width('70px'),

                TextColumn::make('received_quantity')
                    ->label('入荷実績')
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

                TextColumn::make('warehouse.name')
                    ->label('倉庫名')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('120px'),

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

                TextColumn::make('confirmed_at')
                    ->label('入荷日時')
                    ->dateTime('m/d H:i')
                    ->sortable()
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

                SelectFilter::make('status')
                    ->label('ステータス')
                    ->multiple()
                    ->options(collect(IncomingScheduleStatus::cases())->mapWithKeys(fn ($status) => [
                        $status->value => $status->label(),
                    ]))
                    ->default([
                        IncomingScheduleStatus::PENDING->value,
                        IncomingScheduleStatus::PARTIAL->value,
                    ]),

                SelectFilter::make('order_source')
                    ->label('入荷区分')
                    ->options([
                        'AUTO' => '発注',
                        'MANUAL' => '手動',
                        'TRANSFER' => '移動',
                        'RECEIVED' => '受信',
                        'APP_UNPLANNED' => '予定なし',
                    ]),

                static::warehouseFilter()
                    ->default(fn () => auth()->user()?->getSelectedWarehouseId())
                    ->getOptionLabelUsing(function ($value): ?string {
                        $warehouse = Warehouse::query()->find($value);

                        return $warehouse ? "[{$warehouse->code}]{$warehouse->name}" : null;
                    }),

                static::contractorFilter(),

                TernaryFilter::make('eos_sent')
                    ->label('EOS送信')
                    ->placeholder('すべて')
                    ->trueLabel('送信済み')
                    ->falseLabel('未送信')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->eosSent(),
                        false: fn (Builder $query): Builder => $query->notEosSent(),
                    ),

                Filter::make('created_at')
                    ->label('作成日')
                    ->form([
                        DatePicker::make('created_from')
                            ->label('作成日 From'),
                        DatePicker::make('created_until')
                            ->label('作成日 To'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['created_from'], fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['created_until'], fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))
                    )
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['created_from']) {
                            $indicators[] = '作成日 From: '.\Carbon\Carbon::parse($data['created_from'])->format('Y年m月d日');
                        }

                        if ($data['created_until']) {
                            $indicators[] = '作成日 To: '.\Carbon\Carbon::parse($data['created_until'])->format('Y年m月d日');
                        }

                        return $indicators;
                    }),
            ])
            ->recordActionsColumnLabel('操作')
            ->recordActions([
                Action::make('viewDetail')
                    ->label('詳細')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading('入荷予定詳細')
                    ->modalWidth('5xl')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalSubmitActionLabel('入荷確定')
                    ->modalSubmitAction(fn ($record, $action) => static::canManuallyConfirmIncoming($record)
                        ? $action->makeModalSubmitAction('submit', [])->label('入荷確定')->color('danger')->requiresConfirmation()
                            ->modalHeading('入荷確定')
                            ->modalDescription('入荷データを確定します。よろしいですか？')
                            ->modalSubmitActionLabel('確定する')
                        : false)
                    ->modalCancelActionLabel('変更せず閉じる')
                    ->modalFooterActionsAlignment(\Filament\Support\Enums\Alignment::End)
                    ->schema(function (?WmsOrderIncomingSchedule $record): array {
                        if (! $record) {
                            return [];
                        }

                        // 発注候補または移動候補からの計算ログを取得
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

                        $isEosSent = $record->isEosSent();
                        $isTransferIncoming = static::isTransferIncoming($record);
                        $isEditable = static::canManuallyConfirmIncoming($record);

                        // 手動変更判定
                        $shiftedDays = (int) ($details['到着日調整'] ?? 0);
                        $isDateManuallyChanged = false;
                        $calculatedDateFormatted = null;
                        if ($candidate?->original_arrival_date && $record->expected_arrival_date) {
                            $calculatedDate = \Carbon\Carbon::parse($candidate->original_arrival_date)->addDays($shiftedDays);
                            $calculatedDateFormatted = $calculatedDate->format('Y/m/d');
                            $isDateManuallyChanged = $calculatedDate->format('Y-m-d') !== $record->expected_arrival_date->format('Y-m-d');
                        }

                        $schema = [
                            View::make('filament.components.incoming-schedule-detail')
                                ->viewData([
                                    'orderSource' => match ($record->order_source) {
                                        OrderSource::AUTO => '発注',
                                        OrderSource::MANUAL => '手動',
                                        OrderSource::TRANSFER => '移動',
                                        OrderSource::RECEIVED => '受信',
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
                                    'locationText' => $locationText,
                                    'expectedQuantity' => $record->expected_quantity ?? 0,
                                    'quantityType' => $record->quantity_type?->value,
                                    'receivedQuantity' => $record->received_quantity ?? 0,
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
                                ]),
                        ];

                        if ($isEosSent) {
                            $schema[] = View::make('filament.components.eos-auto-incoming-confirmation-notice');
                        }

                        if ($isTransferIncoming) {
                            $schema[] = View::make('filament.components.transfer-incoming-schedule-notice');
                        }

                        if ($isEditable) {
                            $capacity = $item?->capacity_case;
                            $expectedQty = $record->expected_quantity ?? 0;
                            $remaining = $record->remaining_quantity;
                            if ($record->quantity_type === QuantityType::CASE) {
                                $totalPieces = ($capacity && $capacity > 0) ? $expectedQty * $capacity : $expectedQty;
                                $helperText = "残{$remaining}ケース ｜ {$expectedQty}ケース x {$capacity}(入数) ＝ {$totalPieces}バラ";
                            } elseif ($capacity && $capacity > 1) {
                                $caseCount = (int) ($expectedQty / $capacity);
                                $looseCount = $expectedQty % $capacity;
                                $helperText = "残{$remaining}バラ ｜ {$caseCount}ケース x {$capacity}(入数)＋{$looseCount}バラ ＝ {$expectedQty}バラ";
                            } else {
                                $helperText = "残{$remaining}バラ ｜ 総バラ {$expectedQty}";
                            }

                            $schema[] = Grid::make(4)->schema([
                                TextInput::make('received_quantity')
                                    ->label($record->quantity_type === QuantityType::CASE ? '入荷数量（ケース）' : '入荷数量（バラ）')
                                    ->numeric()
                                    ->required()
                                    ->default($record->remaining_quantity)
                                    ->helperText($helperText),

                                DatePicker::make('actual_date')
                                    ->label('入荷日')
                                    ->default($record->actual_arrival_date ?? ClientSetting::systemDate())
                                    ->required(),

                                DatePicker::make('expiration_date')
                                    ->label('賞味期限')
                                    ->default($record->expiration_date),

                                Select::make('location_id')
                                    ->label('ロケーション')
                                    ->options(fn () => Location::query()
                                        ->where('warehouse_id', $record->warehouse_id)
                                        ->orderBy('code1')
                                        ->orderBy('code2')
                                        ->orderBy('code3')
                                        ->get()
                                        ->mapWithKeys(fn ($loc) => [
                                            $loc->id => "{$loc->code1}-{$loc->code2}-{$loc->code3}".($loc->name ? " ({$loc->name})" : ''),
                                        ]))
                                    ->default($defaultLocation?->id)
                                    ->searchable()
                                    ->helperText($defaultLocation
                                        ? "デフォルト: {$defaultLocation->code1}-{$defaultLocation->code2}-{$defaultLocation->code3}"
                                        : 'デフォルトロケーション未設定'),
                            ]);
                        }

                        return $schema;
                    })
                    ->action(function ($record, array $data) {
                        // 確定済みの場合はスキップ（閉じるだけ）
                        if (! in_array($record->status, [
                            IncomingScheduleStatus::PENDING,
                            IncomingScheduleStatus::PARTIAL,
                        ])) {
                            return;
                        }

                        if ($record->isEosSent()) {
                            Notification::make()
                                ->title('入荷確定操作はできません')
                                ->body('EOS自動入荷確定対象であるため入荷確定操作はできません。')
                                ->warning()
                                ->send();

                            return;
                        }

                        if (static::isTransferIncoming($record)) {
                            Notification::make()
                                ->title('入荷確定操作はできません')
                                ->body('店間移動の場合、数量変更・入荷確定は「酒丸勘定衆」の「倉庫移動」メニューより実施してください。')
                                ->warning()
                                ->send();

                            return;
                        }

                        $service = app(IncomingConfirmationService::class);

                        try {
                            $receivedQty = (int) $data['received_quantity'];
                            $remainingQty = $record->remaining_quantity;
                            $expirationDate = $data['expiration_date'] ?? null;
                            $locationId = $data['location_id'] ?? null;

                            $confirmed = $service->confirmIncoming(
                                $record,
                                auth()->id(),
                                $service->resolveConfirmedReceivedQuantity($record, $receivedQty),
                                $data['actual_date'],
                                $expirationDate,
                                $locationId
                            );

                            if ($receivedQty < $remainingQty) {
                                Notification::make()
                                    ->title('入荷を欠品として確定しました')
                                    ->body("入荷数: {$receivedQty} / 欠品数: {$confirmed->shortage_quantity}")
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('入荷を確定しました')
                                    ->success()
                                    ->send();
                            }
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('エラーが発生しました')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('cancel')
                    ->label('取消')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn ($record) => static::canCancelIncomingSchedule($record))
                    ->requiresConfirmation()
                    ->modalHeading('入荷予定を取消')
                    ->extraModalWindowAttributes(['class' => 'incoming-cancel-modal'])
                    ->modalDescription(fn ($record) => $record->status === IncomingScheduleStatus::PARTIAL
                        ? "一部入荷済み（入荷済数量: {$record->received_quantity}）の入荷予定を取消します。入荷済み数量は維持され、残数量の入荷が取消されます。"
                        : 'この入荷予定を取消しますか？')
                    ->schema([
                        Textarea::make('reason')
                            ->label('取消理由')
                            ->required(),
                    ])
                    ->action(function ($record, array $data) {
                        if (static::isTransferIncoming($record)) {
                            Notification::make()
                                ->title('取消できません')
                                ->body('店間移動の場合、取消は「酒丸勘定衆」の「倉庫移動」メニューより実施してください。')
                                ->warning()
                                ->send();

                            return;
                        }

                        $service = app(OrderCancellationService::class);

                        try {
                            $service->cancelIncomingSchedule(
                                $record,
                                auth()->id(),
                                $data['reason']
                            );

                            $statusLabel = $record->fresh()->status->label();
                            Notification::make()
                                ->title("入荷予定を取消しました（{$statusLabel}）")
                                ->warning()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('エラーが発生しました')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->toolbarActions([
                static::getExportAction(),
                BulkActionGroup::make([
                    BulkAction::make('bulkUpdateDates')
                        ->label('入荷日・賞味期限を更新')
                        ->icon('heroicon-o-calendar')
                        ->color('info')
                        ->modalHeading('入荷日・賞味期限の一括更新')
                        ->modalDescription('選択した入荷予定の入荷日・賞味期限を更新します（確定は行いません）')
                        ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                        ->modalFooterActionsAlignment(Alignment::End)
                        ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('一括更新')->color('danger'))
                        ->modalCancelActionLabel('更新せず閉じる')
                        ->schema([
                            ViewField::make('actual_arrival_date')
                                ->label('入荷日')
                                ->view('filament.forms.components.smart-date-input')
                                ->helperText('空欄の場合は更新しません'),

                            ViewField::make('expiration_date')
                                ->label('賞味期限')
                                ->view('filament.forms.components.smart-date-input')
                                ->helperText('空欄の場合は更新しません'),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $validRecords = $records->filter(fn ($r) => static::canUpdateIncomingSchedule($r));

                            if ($validRecords->isEmpty()) {
                                Notification::make()
                                    ->title('更新可能なレコードがありません')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $updateData = [];
                            if (! empty($data['actual_arrival_date'])) {
                                $updateData['actual_arrival_date'] = $data['actual_arrival_date'];
                            }
                            if (! empty($data['expiration_date'])) {
                                $updateData['expiration_date'] = $data['expiration_date'];
                            }

                            if (empty($updateData)) {
                                Notification::make()
                                    ->title('更新する項目がありません')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $count = 0;
                            foreach ($validRecords as $record) {
                                $record->update($updateData);
                                $count++;
                            }

                            Notification::make()
                                ->title("{$count}件を更新しました")
                                ->success()
                                ->send();
                        }),

                    BulkAction::make('bulkConfirm')
                        ->label('選択を入荷確定')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('一括入荷確定')
                        ->modalDescription('選択した入荷予定を全量入荷確定します。')
                        ->schema([
                            DatePicker::make('actual_date')
                                ->label('入荷日')
                                ->default(now())
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $service = app(IncomingConfirmationService::class);
                            $validRecords = $records->filter(fn ($r) => static::canManuallyConfirmIncoming($r));

                            if ($validRecords->isEmpty()) {
                                Notification::make()
                                    ->title('確定可能なレコードがありません')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $result = $service->confirmMultiple(
                                $validRecords->pluck('id')->toArray(),
                                auth()->id(),
                                $data['actual_date']
                            );

                            Notification::make()
                                ->title("{$result['success']}件を入荷確定しました")
                                ->body($result['failed'] > 0 ? "{$result['failed']}件でエラーが発生" : static::bulkConfirmSkippedMessage($records, $validRecords))
                                ->success()
                                ->send();
                        }),

                    BulkAction::make('bulkCancel')
                        ->label('選択を取消')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('一括取消')
                        ->modalDescription('選択した入荷予定を取消します。')
                        ->schema([
                            Textarea::make('reason')
                                ->label('取消理由'),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $service = app(OrderCancellationService::class);
                            $validRecords = $records->filter(fn ($r) => static::canCancelIncomingSchedule($r));

                            if ($validRecords->isEmpty()) {
                                Notification::make()
                                    ->title('取消可能なレコードがありません')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $count = 0;
                            foreach ($validRecords as $record) {
                                try {
                                    $service->cancelIncomingSchedule($record, auth()->id(), $data['reason'] ?? '');
                                    $count++;
                                } catch (\Exception $e) {
                                    // Skip errors
                                }
                            }

                            Notification::make()
                                ->title("{$count}件を取消しました")
                                ->warning()
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByDesc('expected_arrival_date')
                ->orderBy('warehouse_id')
                ->orderBy('item_id')
                ->orderByDesc('id'));
    }

    private static function canManuallyConfirmIncoming(?WmsOrderIncomingSchedule $record): bool
    {
        return $record !== null
            && in_array($record->status, [
                IncomingScheduleStatus::PENDING,
                IncomingScheduleStatus::PARTIAL,
            ], true)
            && ! static::isTransferIncoming($record)
            && ! $record->isEosSent();
    }

    private static function canUpdateIncomingSchedule(?WmsOrderIncomingSchedule $record): bool
    {
        return $record !== null
            && in_array($record->status, [
                IncomingScheduleStatus::PENDING,
                IncomingScheduleStatus::PARTIAL,
            ], true)
            && ! static::isTransferIncoming($record);
    }

    private static function canCancelIncomingSchedule(?WmsOrderIncomingSchedule $record): bool
    {
        return $record !== null
            && in_array($record->status, [
                IncomingScheduleStatus::PENDING,
                IncomingScheduleStatus::PARTIAL,
            ], true)
            && ! static::isTransferIncoming($record);
    }

    private static function isTransferIncoming(?WmsOrderIncomingSchedule $record): bool
    {
        return $record !== null
            && (
                $record->order_source === OrderSource::TRANSFER
                || $record->transfer_candidate_id !== null
                || $record->source_warehouse_id !== null
                || $record->stock_transfer_id !== null
            );
    }

    private static function bulkConfirmSkippedMessage(Collection $records, Collection $validRecords): ?string
    {
        $skippedCount = $records->count() - $validRecords->count();

        return $skippedCount > 0
            ? "EOS自動入荷確定対象・店間移動など、{$skippedCount}件を除外しました。"
            : null;
    }
}
