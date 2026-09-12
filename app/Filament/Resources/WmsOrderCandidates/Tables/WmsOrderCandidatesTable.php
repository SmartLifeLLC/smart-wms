<?php

namespace App\Filament\Resources\WmsOrderCandidates\Tables;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\LotStatus;
use App\Enums\AutoOrder\OriginType;
use App\Enums\PaginationOptions;
use App\Enums\QuantityType;
use App\Filament\Concerns\HasExportAction;
use App\Filament\Concerns\HasModifierDisplay;
use App\Filament\Concerns\HasOptimizedFilters;
use App\Filament\Resources\WmsOrderConfirmationWaiting\Tables\WmsOrderConfirmationWaitingTable;
use App\Models\Concerns\OptimisticLockException;
use App\Models\Sakemaru\ItemContractor;
use App\Models\Sakemaru\User;
use App\Models\WmsAutoOrderJobControl;
use App\Models\WmsMonthlySafetyStock;
use App\Models\WmsOrderCalculationLog;
use App\Models\WmsOrderCandidate;
use App\Services\AutoOrder\OrderAuditService;
use App\Services\AutoOrder\OrderCandidateToTransferCandidateService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\View;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class WmsOrderCandidatesTable
{
    use HasExportAction;
    use HasModifierDisplay;
    use HasOptimizedFilters;

    protected static function getFilterModelTable(): string
    {
        return (new WmsOrderCandidate)->getTable();
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->extraAttributes(['class' => 'order-candidates-table sticky-actions'])
            ->columns([
                TextColumn::make('item_code')
                    ->label('商品CD')
                    ->searchable()
                    ->sortable()
                    ->alignCenter(),

                TextColumn::make('ordering_code')
                    ->label('発注CD')
                    ->searchable()
                    ->sortable()
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('ordering_unit_quantity')
                    ->label('発注CD入数')
                    ->state(fn (WmsOrderCandidate $record) => WmsOrderConfirmationWaitingTable::resolveOrderingUnitQuantity($record) ?? '-')
                    ->alignCenter()
                    ->badge()
                    ->color(fn ($state) => is_numeric($state) ? 'warning' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('warehouse.name')
                    ->label('倉庫')
                    ->state(fn ($record) => $record->warehouse ? "[{$record->warehouse->code}]{$record->warehouse->name}" : '-')
                    ->searchable()
                    ->sortable()
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('item.name')
                    ->label('商品名')
                    ->searchable()
                    ->sortable()
                    ->grow(),

                TextColumn::make('contractor.name')
                    ->label('発注先')
                    ->state(fn ($record) => $record->contractor ? "[{$record->contractor->code}]{$record->contractor->name}" : '-')
                    ->searchable()
                    ->sortable(query: fn (EloquentBuilder $query, string $direction): EloquentBuilder => static::orderByContractorCode($query, $direction))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('180px'),

                TextColumn::make('supplier.partner_name')
                    ->label('仕入先')
                    ->state(fn ($record) => $record->supplier ? "[{$record->supplier->partner_code}]{$record->supplier->partner_name}" : '-')
                    ->sortable(query: fn (EloquentBuilder $query, string $direction): EloquentBuilder => static::orderBySupplierCode($query, $direction))
                    ->toggleable()
                    ->width('180px'),

                TextColumn::make('item.packaging')
                    ->label('規格')
                    ->alignCenter()
                    ->toggleable(),

                TextColumn::make('case_quantity')
                    ->label('ケース')
                    ->state(fn (WmsOrderCandidate $record) => $record->quantity_type === QuantityType::CASE ? (int) $record->order_quantity : 0)
                    ->numeric()
                    ->alignEnd()
                    ->color(fn ($state) => (int) $state > 0 ? 'danger' : 'gray')
                    ->weight(fn ($state) => (int) $state > 0 ? 'bold' : null),

                TextColumn::make('piece_quantity')
                    ->label('バラ')
                    ->state(fn (WmsOrderCandidate $record) => $record->quantity_type === QuantityType::PIECE ? (int) $record->order_quantity : 0)
                    ->numeric()
                    ->alignEnd()
                    ->color('danger')
                    ->weight('bold'),

                TextColumn::make('total_pieces')
                    ->label('総バラ')
                    ->state(function ($record) {
                        $capacityCase = $record->item?->capacity_case ?? 1;
                        $caseQty = $record->quantity_type === QuantityType::CASE ? $record->order_quantity : 0;
                        $pieceQty = $record->quantity_type === QuantityType::PIECE ? $record->order_quantity : 0;

                        return $caseQty * $capacityCase + $pieceQty;
                    })
                    ->numeric()
                    ->alignEnd()
                    ->weight('bold')
                    ->extraAttributes(['class' => '!text-[1.5em] !font-bold !text-black dark:!text-white'])
                    ->summarize(
                        Summarizer::make()
                            ->label('合計')
                            ->numeric(thousandsSeparator: ',')
                            ->extraAttributes(['class' => '!font-bold !text-black dark:!text-white'])
                            ->using(function (Builder $query) {
                                return (int) $query->sum(
                                    DB::raw('CASE WHEN quantity_type = \'CASE\' THEN COALESCE(order_quantity, 0) * COALESCE((SELECT capacity_case FROM items WHERE items.id = wms_order_candidates.item_id), 1) ELSE COALESCE(order_quantity, 0) END')
                                );
                            })
                    ),

                TextColumn::make('current_effective_stock')
                    ->label('理論在庫')
                    ->state(fn ($record) => $record->current_stock ?? '-')
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('effective_incoming_quantity')
                    ->label('入荷予定')
                    ->state(fn ($record) => $record->effective_incoming_quantity ?? '-')
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('default_location')
                    ->label('棚番')
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('calculated_available')
                    ->label('見込在庫')
                    ->state(fn ($record) => $record->calculated_available ?? '-')
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('satellite_demand_qty')
                    ->label('移動依頼')
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('safety_stock')
                    ->label('発注点')
                    ->state(fn (WmsOrderCandidate $record) => (int) ($record->ic_safety_stock ?? $record->safety_stock ?? 0))
                    ->numeric()
                    ->alignEnd(),

                TextColumn::make('setting_max_stock')
                    ->label('最大発注点')
                    ->state(fn (WmsOrderCandidate $record) => (int) ($record->ic_max_stock ?? 0))
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('setting_min_stock')
                    ->label('最低在庫数')
                    ->state(fn (WmsOrderCandidate $record) => (int) ($record->ic_min_stock ?? 0))
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('setting_auto_order_quantity')
                    ->label('自動発注数')
                    ->state(fn (WmsOrderCandidate $record) => (int) ($record->ic_auto_order_quantity ?? 0))
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('setting_is_auto_order')
                    ->label('自動発注')
                    ->state(fn (WmsOrderCandidate $record) => ((bool) ($record->ic_is_auto_order ?? false)) ? 'ON' : 'OFF')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'ON' ? 'success' : 'gray')
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('shortage_qty')
                    ->label('不足分')
                    ->state(fn ($record) => $record->shortage_qty ?? '-')
                    ->numeric()
                    ->alignEnd()
                    ->color(fn ($record) => ($record->shortage_qty ?? 0) > 0 ? 'danger' : null)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('sales_today')
                    ->label('当日')
                    ->state(fn ($record) => $record->salesSummary?->sales_today_qty ?? 0)
                    ->numeric()
                    ->alignEnd()
                    ->color(fn ($record) => ($record->salesSummary?->sales_today_qty ?? 0) > 0 ? null : 'gray'),

                TextColumn::make('sales_yesterday')
                    ->label('前日')
                    ->state(fn ($record) => $record->salesSummary?->sales_yesterday_qty ?? 0)
                    ->numeric()
                    ->alignEnd()
                    ->color(fn ($record) => ($record->salesSummary?->sales_yesterday_qty ?? 0) > 0 ? null : 'gray'),

                TextColumn::make('sales_2days_ago')
                    ->label('前々日')
                    ->state(fn ($record) => $record->salesSummary?->sales_2days_ago_qty ?? 0)
                    ->numeric()
                    ->alignEnd()
                    ->color(fn ($record) => ($record->salesSummary?->sales_2days_ago_qty ?? 0) > 0 ? null : 'gray'),

                TextColumn::make('sales_3d')
                    ->label('3日累計')
                    ->state(fn ($record) => $record->salesSummary?->last_3d_qty ?? 0)
                    ->numeric()
                    ->alignEnd()
                    ->color(fn ($record) => ($record->salesSummary?->last_3d_qty ?? 0) > 0 ? null : 'gray'),

                TextColumn::make('sales_5d')
                    ->label('5日累計')
                    ->state(fn ($record) => $record->salesSummary?->last_5d_qty ?? 0)
                    ->numeric()
                    ->alignEnd()
                    ->color(fn ($record) => ($record->salesSummary?->last_5d_qty ?? 0) > 0 ? null : 'gray'),

                TextColumn::make('sales_7d')
                    ->label('7日累計')
                    ->state(fn ($record) => $record->salesSummary?->last_7d_qty ?? 0)
                    ->numeric()
                    ->alignEnd()
                    ->color(fn ($record) => ($record->salesSummary?->last_7d_qty ?? 0) > 0 ? null : 'gray'),

                TextColumn::make('sales_30d')
                    ->label('30日累計')
                    ->state(fn ($record) => $record->salesSummary?->last_30d_qty ?? 0)
                    ->numeric()
                    ->alignEnd()
                    ->color(fn ($record) => ($record->salesSummary?->last_30d_qty ?? 0) > 0 ? null : 'gray'),

                TextColumn::make('item.capacity_case')
                    ->label('入数')
                    ->numeric()
                    ->alignCenter(),

                TextColumn::make('case_price_display')
                    ->label('ケース単価')
                    ->state(fn ($record) => $record->item?->current_price?->purchase_case_price !== null
                        ? number_format((float) $record->item->current_price->purchase_case_price, 2)
                        : '-')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('piece_price_display')
                    ->label('バラ単価')
                    ->state(fn ($record) => $record->item?->current_price?->purchase_unit_price !== null
                        ? number_format((float) $record->item->current_price->purchase_unit_price, 2)
                        : '-')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('expected_arrival_date')
                    ->label('入荷予定日')
                    ->date('m/d')
                    ->sortable()
                    ->alignCenter(),

                TextColumn::make('lot_status')
                    ->label('ロット')
                    ->badge()
                    ->color(fn (LotStatus $state): string => match ($state) {
                        LotStatus::RAW => 'gray',
                        LotStatus::APPLIED => 'success',
                        LotStatus::BLOCKED => 'danger',
                        LotStatus::NEED_APPROVAL => 'warning',
                        default => 'gray',
                    })
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('transmission_status')
                    ->label('送信')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('origin_type')
                    ->label('生成元')
                    ->badge()
                    ->formatStateUsing(fn (OriginType $state): string => $state->label())
                    ->color(fn ($record) => $record->origin_type?->color() ?? 'gray')
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('status')
                    ->label('状態')
                    ->badge()
                    ->formatStateUsing(fn (CandidateStatus $state): string => $state->label())
                    ->color(fn (CandidateStatus $state): string => $state->color())
                    ->sortable(),

                static::modifierColumn(),

                TextColumn::make('created_at')
                    ->label('作成日時')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                static::batchCodeFilter(WmsOrderCandidate::class),

                Filter::make('executed_at_range')
                    ->label('実行時間')
                    ->schema([
                        Grid::make(2)->schema([
                            DateTimePicker::make('executed_from')
                                ->label('開始'),
                            DateTimePicker::make('executed_until')
                                ->label('終了'),
                        ]),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['executed_from'] ?? null, fn ($q, $date) => $q
                                ->where('batch_code', '>=', \Carbon\Carbon::parse($date)->format('YmdHis')))
                            ->when($data['executed_until'] ?? null, fn ($q, $date) => $q
                                ->where('batch_code', '<=', \Carbon\Carbon::parse($date)->endOfMinute()->format('YmdHis').'999'));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['executed_from'] ?? null) {
                            $indicators[] = '実行時間開始: '.\Carbon\Carbon::parse($data['executed_from'])->format('Y/m/d H:i');
                        }
                        if ($data['executed_until'] ?? null) {
                            $indicators[] = '実行時間終了: '.\Carbon\Carbon::parse($data['executed_until'])->format('Y/m/d H:i');
                        }

                        return $indicators;
                    }),

                SelectFilter::make('status')
                    ->label('ステータス')
                    ->options([
                        CandidateStatus::PENDING->value => CandidateStatus::PENDING->label(),
                        CandidateStatus::EXCLUDED->value => CandidateStatus::EXCLUDED->label(),
                    ])
                    ->default(CandidateStatus::PENDING->value),

                static::candidateCreatorFilter(),

                SelectFilter::make('origin_type')
                    ->label('生成元')
                    ->options(collect(OriginType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])),

                static::warehouseFilter()
                    ->label('発注倉庫')
                    ->query(function ($query, array $data) {
                        if (blank($data['value'])) {
                            return;
                        }

                        $query->where((new WmsOrderCandidate)->getTable().'.warehouse_id', $data['value']);
                    }),

                static::contractorFilter(),

                static::supplierFilter(),

                static::modifierFilter(),
            ])
            ->recordActionsColumnLabel('操作')
            ->recordActions([
                Action::make('viewCalculation')
                    ->label('詳細')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading('発注候補詳細')
                    ->modalWidth('5xl')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalSubmitAction(fn ($record, $action) => $record->status === CandidateStatus::PENDING
                        ? $action->makeModalSubmitAction('submit', [])->label('変更を保存')->color('danger')
                        : false)
                    ->modalCancelActionLabel('変更せず閉じる')
                    ->modalFooterActionsAlignment(\Filament\Support\Enums\Alignment::End)
                    ->fillForm(function ($record) {
                        $itemContractor = static::resolveItemContractor($record);

                        return [
                            'case_quantity' => $record->case_quantity,
                            'piece_quantity' => $record->piece_quantity,
                            'expected_arrival_date' => $record->expected_arrival_date,
                            'safety_stock' => $record->safety_stock,
                            'max_stock' => $itemContractor?->max_stock ?? 0,
                            'min_stock' => $itemContractor?->min_stock ?? 0,
                            'auto_order_quantity' => $itemContractor?->auto_order_quantity ?? 0,
                            'is_auto_order' => (bool) ($itemContractor?->is_auto_order ?? false),
                            'ordering_code' => static::resolveOrderingCodeForForm($record),
                        ];
                    })
                    ->schema(function (?WmsOrderCandidate $record): array {
                        if (! $record) {
                            return [];
                        }

                        $log = WmsOrderCalculationLog::where('batch_code', $record->batch_code)
                            ->where('warehouse_id', $record->warehouse_id)
                            ->where('item_id', $record->item_id)
                            ->first();

                        $details = $log?->calculation_details ?? [];
                        $item = $record->item;
                        $itemContractor = static::resolveItemContractor($record);
                        $capacityCase = $item?->capacity_case ?? 1;
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

                        $isEditable = $record->status === CandidateStatus::PENDING;

                        // 理論在庫は real_stocks のライブ値（引当可能数）を表示する
                        // （current_effective_stock はバッチ算出時のスナップショットのためズレる）
                        $liveAvailableStock = $record->liveAvailableStock();
                        $snapshotEffectiveStock = $record->current_effective_stock ?? 0;

                        // 手動変更判定: 算出日と現在の予定日を比較
                        $shiftedDays = (int) ($details['到着日調整'] ?? 0);
                        $isDateManuallyChanged = false;
                        $calculatedDateFormatted = null;
                        if ($record->original_arrival_date && $record->expected_arrival_date) {
                            $calculatedDate = \Carbon\Carbon::parse($record->original_arrival_date)->addDays($shiftedDays);
                            $calculatedDateFormatted = $calculatedDate->format('Y/m/d');
                            $isDateManuallyChanged = $calculatedDate->format('Y-m-d') !== $record->expected_arrival_date->format('Y-m-d');
                        }

                        $schema = [
                            View::make('filament.components.order-candidate-detail')
                                ->viewData([
                                    'batchCode' => $record->batch_code,
                                    'batchCodeFormatted' => \Carbon\Carbon::createFromFormat('YmdHis', substr($record->batch_code, 0, 14))->format('Y/m/d H:i'),
                                    'warehouseName' => $record->warehouse ? "[{$record->warehouse->code}]{$record->warehouse->name}" : '-',
                                    'contractorName' => $record->contractor ? "[{$record->contractor->code}]{$record->contractor->name}" : '-',
                                    'expectedArrivalDate' => $record->expected_arrival_date
                                        ? \Carbon\Carbon::parse($record->expected_arrival_date)->format('Y/m/d')
                                        : '-',
                                    'leadTimeDays' => $log?->lead_time_days ?? 0,
                                    'orderDate' => $record->created_at?->format('m/d') ?? '-',
                                    'originalArrivalDate' => $record->original_arrival_date
                                        ? \Carbon\Carbon::parse($record->original_arrival_date)->format('m/d')
                                        : null,
                                    'shiftedDays' => $shiftedDays,
                                    'shiftReasons' => $details['調整理由'] ?? '',
                                    'isDateManuallyChanged' => $isDateManuallyChanged,
                                    'calculatedDate' => $calculatedDateFormatted,
                                    'itemCode' => $record->item_code ?? $item?->code ?? '-',
                                    'searchCode' => $record->search_code ?? '-',
                                    'itemName' => $item?->name ?? '-',
                                    'packaging' => $item?->packaging ?? '-',
                                    'capacityText' => $capacityText,
                                    'statusLabel' => $record->status->label(),
                                    'currentEffectiveStock' => $liveAvailableStock,
                                    'snapshotEffectiveStock' => $snapshotEffectiveStock,
                                    'suggestedQuantity' => $record->suggested_quantity ?? 0,
                                    'orderQuantity' => $record->order_quantity ?? 0,
                                    'hasCalculationLog' => ! empty($details),
                                    'formula' => str_replace(['安全在庫', '入庫予定'], ['発注点', '入荷予定'], $details['計算式'] ?? '-'),
                                    'effectiveStock' => $details['有効在庫'] ?? 0,
                                    'incomingStock' => $details['入荷予定'] ?? $details['入庫予定数'] ?? 0,
                                    'transferIncoming' => $details['移動入庫予定'] ?? 0,
                                    'transferOutgoing' => $details['移動出庫予定'] ?? 0,
                                    'safetyStock' => $details['発注点'] ?? $details['安全在庫'] ?? 0,
                                    'shortageQty' => $details['不足数'] ?? 0,
                                    'autoOrderQuantity' => $details['旧自動発注数'] ?? 0,
                                    'settingAutoOrderQuantity' => $itemContractor?->auto_order_quantity ?? 0,
                                    'maxStock' => $details['最大発注点'] ?? $itemContractor?->max_stock ?? 0,
                                    'minStock' => $itemContractor?->min_stock ?? 0,
                                    'maxOrderQuantity' => $details['最大発注可能数量(バラ)'] ?? null,
                                    'isAutoOrder' => (bool) ($itemContractor?->is_auto_order ?? false),
                                    'orderQuantitySource' => $details['発注数量計算元'] ?? null,
                                    'orderQuantitySourceQty' => $details['発注数量計算元数量(バラ)'] ?? null,
                                    'purchaseUnit' => $details['最小仕入単位'] ?? 1,
                                    'purchaseUnitAdjustment' => $details['単位調整説明'] ?? null,
                                    'isEditable' => $isEditable,
                                ]),
                        ];

                        if ($isEditable) {
                            $schema[] = Hidden::make('safety_stock');

                            $schema[] = Grid::make(3)->schema([
                                TextInput::make('case_quantity')
                                    ->label('ケース')
                                    ->integer()
                                    ->minValue(0)
                                    ->disabled($capacityCase <= 1),

                                TextInput::make('piece_quantity')
                                    ->label('バラ')
                                    ->integer()
                                    ->minValue(0),

                                ViewField::make('expected_arrival_date')
                                    ->label('入荷予定日')
                                    ->view('filament.forms.components.smart-date-input')
                                    ->required(),
                            ]);

                            $schema[] = Grid::make(4)->schema([
                                TextInput::make('max_stock')
                                    ->label('最大発注点')
                                    ->integer()
                                    ->minValue(0),
                                TextInput::make('min_stock')
                                    ->label('最低在庫数')
                                    ->integer()
                                    ->minValue(0),
                                TextInput::make('auto_order_quantity')
                                    ->label('自動発注数')
                                    ->integer()
                                    ->minValue(0),
                                Toggle::make('is_auto_order')
                                    ->label('自動発注ON/OFF'),
                            ]);

                            $codes = DB::connection('sakemaru')
                                ->table('item_search_information')
                                ->where('item_id', $record->item_id)
                                ->where('is_active', true)
                                ->select('search_string', 'code_type', 'is_used_for_ordering')
                                ->get();

                            if ($codes->isNotEmpty()) {
                                $codeOptions = $codes->mapWithKeys(function ($code) {
                                    $label = $code->search_string;
                                    if ($code->is_used_for_ordering) {
                                        $label .= ' (現在の発注用)';
                                    }

                                    return [$code->search_string => $label];
                                })->toArray();

                                $schema[] = Select::make('ordering_code')
                                    ->label('発注CD')
                                    ->options($codeOptions)
                                    ->searchable()
                                    ->helperText('この商品に登録されている検索コードから発注CDを選択');
                            }
                        }

                        return $schema;
                    })
                    ->action(function ($record, array $data) {
                        if ($record->status !== CandidateStatus::PENDING) {
                            Notification::make()
                                ->title('承認後は変更できません')
                                ->danger()
                                ->send();

                            return;
                        }

                        $updated = false;
                        $oldQuantity = $record->order_quantity;
                        $updateData = [
                            'is_manually_modified' => true,
                            'modified_by' => auth()->id(),
                            'modified_at' => now(),
                        ];

                        $caseQty = (int) ($data['case_quantity'] ?? 0);
                        $pieceQty = (int) ($data['piece_quantity'] ?? 0);
                        $currentPrice = $record->item?->current_price;

                        if ($caseQty > 0) {
                            $updateData['order_quantity'] = $caseQty;
                            $updateData['quantity_type'] = QuantityType::CASE->value;
                            $updateData['purchase_unit_price'] = $currentPrice?->purchase_case_price;
                            $updated = true;
                        } elseif ($pieceQty > 0) {
                            $updateData['order_quantity'] = $pieceQty;
                            $updateData['quantity_type'] = QuantityType::PIECE->value;
                            $updateData['purchase_unit_price'] = $currentPrice?->purchase_unit_price;
                            $updated = true;
                        }

                        $newArrivalDate = $data['expected_arrival_date'] instanceof \Carbon\Carbon
                            ? $data['expected_arrival_date']->format('Y-m-d')
                            : $data['expected_arrival_date'];
                        $currentArrivalDate = $record->expected_arrival_date
                            ? $record->expected_arrival_date->format('Y-m-d')
                            : null;

                        if ($newArrivalDate !== $currentArrivalDate) {
                            $updateData['expected_arrival_date'] = $newArrivalDate;
                            $updated = true;
                        }

                        if (isset($data['safety_stock']) && (int) $data['safety_stock'] !== (int) $record->safety_stock) {
                            $newSafetyStock = (int) $data['safety_stock'];
                            $updateData['safety_stock'] = $newSafetyStock;
                            $updated = true;

                            WmsMonthlySafetyStock::updateOrCreate(
                                [
                                    'item_id' => $record->item_id,
                                    'warehouse_id' => $record->warehouse_id,
                                    'contractor_id' => $record->contractor_id,
                                    'month' => now()->month,
                                ],
                                [
                                    'safety_stock' => $newSafetyStock,
                                ]
                            );
                        }

                        $itemContractor = static::resolveItemContractor($record);
                        $itemContractorData = [];
                        if (isset($data['safety_stock']) && (int) $data['safety_stock'] !== (int) ($itemContractor?->safety_stock ?? 0)) {
                            $itemContractorData['safety_stock'] = max(0, (int) $data['safety_stock']);
                        }
                        if (isset($data['max_stock']) && (int) $data['max_stock'] !== (int) ($itemContractor?->max_stock ?? 0)) {
                            $itemContractorData['max_stock'] = max(0, (int) $data['max_stock']);
                        }
                        if (isset($data['min_stock']) && (int) $data['min_stock'] !== (int) ($itemContractor?->min_stock ?? 0)) {
                            $itemContractorData['min_stock'] = max(0, (int) $data['min_stock']);
                        }
                        if (isset($data['auto_order_quantity']) && (int) $data['auto_order_quantity'] !== (int) ($itemContractor?->auto_order_quantity ?? 0)) {
                            $itemContractorData['auto_order_quantity'] = max(0, (int) $data['auto_order_quantity']);
                        }
                        if (array_key_exists('is_auto_order', $data) && (bool) $data['is_auto_order'] !== (bool) ($itemContractor?->is_auto_order ?? false)) {
                            $itemContractorData['is_auto_order'] = (bool) $data['is_auto_order'];
                        }
                        if ($itemContractorData !== []) {
                            ItemContractor::where('item_id', $record->item_id)
                                ->where('warehouse_id', $record->warehouse_id)
                                ->where('contractor_id', $record->contractor_id)
                                ->update($itemContractorData);
                            $updated = true;
                        }

                        if (
                            isset($data['ordering_code'])
                            && static::normalizeOrderingCode($data['ordering_code']) !== static::normalizeOrderingCode($record->ordering_code)
                        ) {
                            $newSearchCode = $data['ordering_code'];
                            $updateData['search_code'] = $newSearchCode;
                            $updateData['ordering_code'] = str_pad($newSearchCode, 13, '0', STR_PAD_LEFT);
                            $updated = true;
                        }

                        if ($updated) {
                            try {
                                $record->updateWithLock($updateData);

                                if ($oldQuantity !== ($updateData['order_quantity'] ?? $oldQuantity)) {
                                    app(OrderAuditService::class)->logQuantityChange($record, $oldQuantity, $updateData['order_quantity']);
                                }

                                Notification::make()
                                    ->title('発注候補を更新しました')
                                    ->success()
                                    ->send();
                            } catch (OptimisticLockException $e) {
                                Notification::make()
                                    ->title('更新エラー')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }
                    }),

                Action::make('delete')
                    ->label('削除')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn ($record) => $record->status === CandidateStatus::PENDING)
                    ->requiresConfirmation()
                    ->modalHeading('発注候補を削除')
                    ->modalDescription('この発注候補を削除します。この操作は取り消せません。')
                    ->action(function ($record) {
                        $record->delete();

                        Notification::make()
                            ->title('発注候補を削除しました')
                            ->warning()
                            ->send();
                    }),

                Action::make('convertToTransferCandidate')
                    ->label('移動候補へ変更')
                    ->icon('heroicon-o-arrows-right-left')
                    ->color('warning')
                    ->visible(fn ($record) => $record->status === CandidateStatus::PENDING
                        && (string) $record->contractor?->code === '9012')
                    ->requiresConfirmation()
                    ->modalHeading('発注候補を移動候補へ変更')
                    ->modalDescription(fn ($record) => "[{$record->item_code}] {$record->item?->name}\n発注先CD9012の発注候補を、91倉庫からの移動候補に変更します。")
                    ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('移動候補へ変更')->color('danger'))
                    ->modalCancelActionLabel('変更せず閉じる')
                    ->action(function (WmsOrderCandidate $record) {
                        try {
                            app(OrderCandidateToTransferCandidateService::class)->convert($record, auth()->id());

                            Notification::make()
                                ->title('移動候補へ変更しました')
                                ->body('元の発注候補は除外に変更しました。')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('移動候補へ変更できませんでした')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->toolbarActions([
                static::getExportAction(),
                BulkActionGroup::make([
                    BulkAction::make('bulkApprove')
                        ->label('選択を承認')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalDescription('選択した発注候補を承認しますか？')
                        ->action(function (Collection $records) {
                            // 一括UPDATEで高速化（N+1問題を解消）
                            $pendingIds = $records
                                ->where('status', CandidateStatus::PENDING)
                                ->pluck('id')
                                ->toArray();

                            if (! empty($pendingIds)) {
                                WmsOrderCandidate::whereIn('id', $pendingIds)
                                    ->get()
                                    ->each(function (WmsOrderCandidate $candidate) {
                                        $candidate->update([
                                            'status' => CandidateStatus::APPROVED,
                                            'updated_at' => now(),
                                        ]);
                                    });
                            }

                            $count = count($pendingIds);

                            Notification::make()
                                ->title("{$count}件を承認しました")
                                ->success()
                                ->send();
                        }),

                    BulkAction::make('bulkConvertToTransferCandidate')
                        ->label('選択を移動候補へ変更')
                        ->icon('heroicon-o-arrows-right-left')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('選択した発注候補を移動候補へ変更')
                        ->modalDescription('選択した承認前かつ発注先CD9012の発注候補を、91倉庫からの移動候補に変更します。対象外の行はスキップします。')
                        ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('移動候補へ変更')->color('danger'))
                        ->modalCancelActionLabel('変更せず閉じる')
                        ->action(function (Collection $records) {
                            $service = app(OrderCandidateToTransferCandidateService::class);
                            $converted = 0;
                            $skipped = 0;
                            $errors = [];

                            $records->loadMissing('contractor');

                            foreach ($records as $record) {
                                if ($record->status !== CandidateStatus::PENDING || (string) $record->contractor?->code !== '9012') {
                                    $skipped++;

                                    continue;
                                }

                                try {
                                    $service->convert($record, auth()->id());
                                    $converted++;
                                } catch (\Throwable $e) {
                                    $errors[] = "[{$record->item_code}] {$e->getMessage()}";
                                }
                            }

                            if ($converted > 0) {
                                Notification::make()
                                    ->title("{$converted}件を移動候補へ変更しました")
                                    ->body($skipped > 0 ? "対象外 {$skipped}件はスキップしました。" : null)
                                    ->success()
                                    ->send();
                            } elseif ($skipped > 0 && empty($errors)) {
                                Notification::make()
                                    ->title('変更対象がありません')
                                    ->body('承認前かつ発注先CD9012の発注候補を選択してください。')
                                    ->warning()
                                    ->send();
                            }

                            if (! empty($errors)) {
                                Notification::make()
                                    ->title(count($errors).'件でエラーが発生しました')
                                    ->body(implode("\n", array_slice($errors, 0, 5)))
                                    ->danger()
                                    ->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('bulkUpdateArrivalDate')
                        ->label('入荷予定日変更')
                        ->icon('heroicon-o-pencil-square')
                        ->color('warning')
                        ->modalHeading('')
                        ->extraModalWindowAttributes(['class' => 'bulk-update-course-date-modal'])
                        ->modalFooterActionsAlignment(Alignment::End)
                        ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('変更を適用')->color('danger'))
                        ->modalCancelActionLabel('変更せず閉じる')
                        ->schema(fn (Collection $records) => [
                            ViewField::make('header')
                                ->view('filament.components.bulk-update-course-date-header', [
                                    'totalCount' => $records->count(),
                                    'pendingCount' => $records->where('status', CandidateStatus::PENDING)->count(),
                                ]),
                            ViewField::make('expected_arrival_date')
                                ->label('入荷予定日')
                                ->view('filament.forms.components.smart-date-input')
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data) {
                            if (empty($data['expected_arrival_date'])) {
                                Notification::make()
                                    ->title('入荷予定日を指定してください')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $pendingIds = $records
                                ->where('status', CandidateStatus::PENDING)
                                ->pluck('id')
                                ->toArray();

                            if (empty($pendingIds)) {
                                Notification::make()
                                    ->title('承認前の候補がありません')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $count = WmsOrderCandidate::whereIn('id', $pendingIds)
                                ->update([
                                    'expected_arrival_date' => $data['expected_arrival_date'],
                                    'is_manually_modified' => true,
                                    'modified_by' => auth()->id(),
                                    'modified_at' => now(),
                                    'updated_at' => now(),
                                ]);

                            Notification::make()
                                ->title("{$count}件の入荷予定日を更新しました")
                                ->success()
                                ->send();
                        }),

                    BulkAction::make('bulkDelete')
                        ->label('選択を削除')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('選択した発注候補を削除')
                        ->modalDescription('選択した承認前の発注候補を削除します。この操作は取り消せません。')
                        ->action(function (Collection $records) {
                            $pendingIds = $records
                                ->where('status', CandidateStatus::PENDING)
                                ->pluck('id')
                                ->toArray();

                            if (empty($pendingIds)) {
                                Notification::make()
                                    ->title('承認前の候補がありません')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $count = WmsOrderCandidate::whereIn('id', $pendingIds)->delete();

                            Notification::make()
                                ->title("{$count}件を削除しました")
                                ->warning()
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort(fn (EloquentBuilder $query): EloquentBuilder => static::applyDefaultOrder($query));
    }

    public static function applyDefaultOrder(EloquentBuilder $query): EloquentBuilder
    {
        $mainTable = static::getFilterModelTable();

        $query->orderBy("{$mainTable}.batch_code", 'desc');
        static::orderByContractorCode($query);
        static::orderBySupplierCode($query);

        return $query
            ->orderBy("{$mainTable}.warehouse_id")
            ->orderBy("{$mainTable}.item_code")
            ->orderBy("{$mainTable}.id");
    }

    private static function orderByContractorCode(EloquentBuilder $query, string $direction = 'asc'): EloquentBuilder
    {
        $mainTable = static::getFilterModelTable();
        $direction = strtolower($direction) === 'desc' ? 'DESC' : 'ASC';

        return $query->orderByRaw("COALESCE((SELECT contractor_sort.code FROM contractors AS contractor_sort WHERE contractor_sort.id = {$mainTable}.contractor_id LIMIT 1), '') {$direction}");
    }

    private static function orderBySupplierCode(EloquentBuilder $query, string $direction = 'asc'): EloquentBuilder
    {
        $mainTable = static::getFilterModelTable();
        $direction = strtolower($direction) === 'desc' ? 'DESC' : 'ASC';

        return $query->orderByRaw("COALESCE((SELECT supplier_partner_sort.code FROM suppliers AS supplier_sort LEFT JOIN partners AS supplier_partner_sort ON supplier_partner_sort.id = supplier_sort.partner_id WHERE supplier_sort.id = {$mainTable}.supplier_id LIMIT 1), '') {$direction}");
    }

    public static function applyQuantityChange(WmsOrderCandidate $record, QuantityType $targetType, int $newQuantity, ?int $userId): string
    {
        if ($record->status !== CandidateStatus::PENDING) {
            return 'skipped';
        }

        if ($newQuantity === 0 && $record->quantity_type !== $targetType) {
            return 'skipped';
        }

        if ($newQuantity > 0 && $record->quantity_type !== $targetType) {
            $existingRow = WmsOrderCandidate::where('batch_code', $record->batch_code)
                ->where('item_id', $record->item_id)
                ->where('warehouse_id', $record->warehouse_id)
                ->where('contractor_id', $record->contractor_id)
                ->where('quantity_type', $targetType)
                ->where('id', '!=', $record->id)
                ->first();

            if ($existingRow) {
                $existingRow->update([
                    'order_quantity' => $existingRow->order_quantity + $newQuantity,
                    'is_manually_modified' => true,
                    'modified_by' => $userId,
                    'modified_at' => now(),
                ]);
                $record->delete();

                return 'merged';
            }
        }

        $oldQuantity = (int) $record->order_quantity;
        $oldQuantityType = $record->quantity_type;
        $price = $targetType === QuantityType::CASE
            ? $record->item?->current_price?->purchase_case_price
            : $record->item?->current_price?->purchase_unit_price;

        $record->updateWithLock([
            'order_quantity' => $newQuantity,
            'quantity_type' => $targetType->value,
            'purchase_unit_price' => $price,
            'is_manually_modified' => true,
            'modified_by' => $userId,
            'modified_at' => now(),
        ]);

        if ($oldQuantity !== $newQuantity) {
            app(OrderAuditService::class)->logQuantityChange($record, $oldQuantity, $newQuantity);
        }

        if ($oldQuantity === $newQuantity && $oldQuantityType === $targetType) {
            return 'skipped';
        }

        return 'updated';
    }

    private static function resolveItemContractor(WmsOrderCandidate $record): ?ItemContractor
    {
        return ItemContractor::query()
            ->where('item_id', $record->item_id)
            ->where('warehouse_id', $record->warehouse_id)
            ->where('contractor_id', $record->contractor_id)
            ->first();
    }

    private static function resolveOrderingCodeForForm(WmsOrderCandidate $record): ?string
    {
        $orderingCode = static::normalizeOrderingCode($record->ordering_code);

        if ($orderingCode !== null) {
            $searchString = DB::connection('sakemaru')
                ->table('item_search_information')
                ->where('item_id', $record->item_id)
                ->where('is_active', true)
                ->whereRaw('LPAD(search_string, 13, "0") = ?', [$orderingCode])
                ->value('search_string');

            return $searchString ?: $record->ordering_code;
        }

        return $record->search_code;
    }

    private static function normalizeOrderingCode(?string $code): ?string
    {
        $code = trim((string) $code);

        if ($code === '' || preg_match('/^0+$/', $code) === 1) {
            return null;
        }

        return str_pad($code, 13, '0', STR_PAD_LEFT);
    }

    private static function candidateCreatorFilter(): SelectFilter
    {
        return SelectFilter::make('candidate_created_by')
            ->label('作成者')
            ->searchable()
            ->default(auth()->id())
            ->options(fn () => self::buildCandidateCreatorOptions())
            ->getSearchResultsUsing(fn (string $search) => self::buildCandidateCreatorOptions($search))
            ->query(function ($query, array $data) {
                if (blank($data['value'])) {
                    return;
                }

                $query->whereIn('batch_code', WmsAutoOrderJobControl::query()
                    ->where('created_by', $data['value'])
                    ->select('batch_code'));
            });
    }

    private static function buildCandidateCreatorOptions(?string $search = null): array
    {
        $query = User::query()
            ->whereIn('id', fn ($q) => $q
                ->select('created_by')
                ->from((new WmsAutoOrderJobControl)->getTable())
                ->whereNotNull('created_by')
                ->distinct());

        if ($search) {
            $search = mb_convert_kana($search, 'as');
            $query->where(fn ($q) => $q
                ->where('code', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%"));
        }

        return $query
            ->orderBy('code')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn ($u) => [$u->id => "[{$u->code}]{$u->name}"])
            ->toArray();
    }
}
