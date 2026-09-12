<?php

namespace App\Filament\Resources\WmsShortageAllocations\Tables;

use App\Enums\PaginationOptions;
use App\Enums\QuantityType;
use App\Filament\Concerns\HasExportAction;
use App\Filament\Support\Tables\Columns\QuantityTypeColumn;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsShortage;
use App\Models\WmsShortageAllocation;
use App\Services\QuantityUpdate\AllocationSyncService;
use App\Services\Shortage\ProxyShipmentService;
use App\Services\Shortage\StockTransferQueueService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class WmsShortageAllocationsTable
{
    use HasExportAction;

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->striped()
            ->extraAttributes(['class' => 'sticky-actions'])
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

                QuantityTypeColumn::make('assign_qty_type', '単位'),

                TextColumn::make('assign_qty')
                    ->label('予定数')
                    ->alignment('center'),

                TextInputColumn::make('picked_qty')
                    ->label('ピック数')
                    ->type('number')
                    ->rules(['required', 'integer', 'min:0'])
                    ->disabled(fn (WmsShortageAllocation $record): bool => ! in_array($record->status, ['RESERVED', 'PICKING']))
                    ->afterStateUpdated(function (WmsShortageAllocation $record, $state) {
                        // picked_qtyがassign_qtyを超えないようにチェック
                        if ($state > $record->assign_qty) {
                            Notification::make()
                                ->title('エラー')
                                ->body("ピック数は予定数（{$record->assign_qty}）を超えることはできません")
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->picked_qty = $state;

                        // ピック数量が入力されたらステータスをPICKINGに変更（完了していない場合）
                        if ($state > 0 && ! $record->is_finished) {
                            $record->status = 'PICKING';
                        }

                        $record->save();

                        Notification::make()
                            ->title('ピック数を更新しました')
                            ->success()
                            ->send();
                    })
                    ->alignment('center')
                    ->extraInputAttributes([
                        'class' => 'w-20 !h-8 !py-1 text-center border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 border focus:border-primary-500 focus:ring-primary-500 !text-sm',
                        'style' => 'height: 32px !important; padding-top: 0.25rem !important; padding-bottom: 0.25rem !important;',
                        'min' => '0',
                        'step' => '1',
                        'inputmode' => 'numeric',
                        'pattern' => '[0-9]*',
                    ]),

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

                TextColumn::make('created_at')
                    ->label('作成日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->alignment('center')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                \Filament\Tables\Filters\Filter::make('shipment_date')
                    ->label('出荷日')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('shipment_date')
                            ->label('出荷日'),
                    ])
                    ->query(function (\Illuminate\Database\Eloquent\Builder $query, array $data): \Illuminate\Database\Eloquent\Builder {
                        return $query->when(
                            $data['shipment_date'],
                            fn (\Illuminate\Database\Eloquent\Builder $query, $date) => $query->where('shipment_date', $date),
                        );
                    })
                    ->indicateUsing(function (array $data): ?string {
                        if (! $data['shipment_date']) {
                            return null;
                        }

                        return '出荷日: '.$data['shipment_date'];
                    }),

                SelectFilter::make('status')
                    ->label('ステータス')
                    ->options([
                        'PENDING' => '承認待ち',
                        'RESERVED' => '引当済み',
                        'PICKING' => 'ピッキング中',
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
            ->recordActions([
                // 完了ボタン
                Action::make('complete')
                    ->label('完了')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (WmsShortageAllocation $record): bool => ! $record->is_finished
                        && in_array($record->status, ['PICKING', 'RESERVED'])
                    )
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalHeading('横持ち出荷の完了確認')
                    ->modalDescription(fn (WmsShortageAllocation $record): string => $record->remaining_qty > 0
                        ? "予定数: {$record->assign_qty}、ピック数: {$record->picked_qty}、欠品数: {$record->remaining_qty}\n欠品として確定しますか？このまま確定すると欠品状態になります。"
                        : "予定数: {$record->assign_qty}、ピック数: {$record->picked_qty}"
                    )
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->modalSubmitAction(
                        fn ($action) => $action
                            ->makeModalSubmitAction('submit', [])
                            ->label('確定')
                            ->color('danger')
                    )
                    ->modalCancelActionLabel('確定せず閉じる')
                    ->action(function (WmsShortageAllocation $record): void {
                        $record->is_finished = true;
                        $record->finished_at = now();
                        $record->finished_user_id = auth()->id();

                        // 完了時のステータス判定
                        if ($record->picked_qty >= $record->assign_qty) {
                            $record->status = 'FULFILLED';
                        } else {
                            $record->status = 'SHORTAGE';
                        }

                        $record->save();

                        // 倉庫移動伝票キューと最終数量同期キューを作成
                        try {
                            $queueService = app(StockTransferQueueService::class);
                            $queueId = $queueService->createStockTransferQueue($record);
                            $syncQueue = app(AllocationSyncService::class)->syncAllocationIfReady($record->fresh() ?? $record);

                            $statusLabel = $record->status === 'FULFILLED' ? '完了' : '欠品確定';
                            $message = "横持ち出荷を{$statusLabel}しました";
                            if ($queueId) {
                                $message .= "\n倉庫移動伝票キューID: {$queueId}";
                            }
                            if ($syncQueue) {
                                $message .= "\n数量同期キューID: {$syncQueue->id}";
                            }

                            Notification::make()
                                ->title($message)
                                ->body("ステータス: {$statusLabel}")
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('完了しましたが、キュー作成でエラーが発生しました')
                                ->body($e->getMessage())
                                ->warning()
                                ->send();
                        }
                    }),

                // 横持ち出荷修正アクション（残数量がある場合のみ）
                Action::make('addPartialShipment')
                    ->label('修正')
                    ->icon('heroicon-o-pencil-square')
                    ->color('warning')
                    ->visible(fn (WmsShortageAllocation $record): bool => $record->remaining_qty > 0
                        && in_array($record->status, ['PICKING', 'RESERVED'])
                    )
                    ->modalHeading('横持ち出荷修正')
                    ->modalWidth('7xl')
                    ->extraModalWindowAttributes(['class' => 'proxy-shipment-modal'])
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('確定')->color('danger'))
                    ->modalCancelActionLabel('確定せず閉じる')
                    ->schema([
                        \Filament\Forms\Components\ViewField::make('allocations')
                            ->hiddenLabel()
                            ->live()
                            ->view('filament.forms.components.proxy-shipment-allocations')
                            ->viewData(function (WmsShortageAllocation $record): array {
                                $shortage = $record->shortage;

                                // 該当商品の全倉庫在庫を取得（在庫がある倉庫のみ）
                                $stocks = \DB::connection('sakemaru')
                                    ->table('real_stocks')
                                    ->select([
                                        'real_stocks.warehouse_id',
                                        \DB::raw('warehouses.name as warehouse_name'),
                                        \DB::raw('SUM(real_stocks.current_quantity) as total_pieces'),
                                    ])
                                    ->join('warehouses', 'warehouses.id', '=', 'real_stocks.warehouse_id')
                                    ->where('real_stocks.item_id', $shortage->item_id)
                                    ->where('real_stocks.current_quantity', '>', 0)
                                    ->groupBy('real_stocks.warehouse_id', 'warehouses.name')
                                    ->orderBy('warehouses.name')
                                    ->get();

                                $caseSize = $shortage->item->capacity_case ?? 1;

                                $stockData = $stocks->map(function ($stock) use ($caseSize) {
                                    $totalPieces = (int) $stock->total_pieces;
                                    $cases = floor($totalPieces / $caseSize);

                                    return [
                                        'warehouse_id' => $stock->warehouse_id,
                                        'warehouse_name' => $stock->warehouse_name,
                                        'cases' => $cases,
                                        'total_pieces' => $totalPieces,
                                    ];
                                })->toArray();

                                $qtyType = QuantityType::tryFrom($record->assign_qty_type);

                                // 容量
                                $volumeValue = '-';
                                if ($shortage->item->volume) {
                                    $unit = \App\Enums\EVolumeUnit::tryFrom($shortage->item->volume_unit);
                                    $volumeValue = $shortage->item->volume.($unit ? $unit->name() : '');
                                }

                                // 欠品内訳
                                $shortageDetailsValue = "残数量: {$record->remaining_qty}";

                                // 得意先の最寄倉庫を取得
                                $partnerId = $shortage->trade->partner_id ?? null;
                                $nearestWarehouseId = null;
                                if ($partnerId) {
                                    $nearest = \App\Models\WmsPartnerNearestWarehouse::where('partner_id', $partnerId)->first();
                                    $nearestWarehouseId = $nearest?->nearest_warehouse_id;
                                }

                                // 同一配送コース内の横持ち出荷予定倉庫を取得
                                $sameCourseAllocations = [];
                                $courseNearestWarehouses = [];
                                if ($shortage->wave_id && $shortage->delivery_course_id) {
                                    $sameCourseAllocations = WmsShortageAllocation::query()
                                        ->whereHas('shortage', function ($q) use ($shortage) {
                                            $q->where('wave_id', $shortage->wave_id)
                                                ->where('delivery_course_id', $shortage->delivery_course_id)
                                                ->where('id', '!=', $shortage->id);
                                        })
                                        ->whereNotIn('status', ['CANCELLED'])
                                        ->with('targetWarehouse')
                                        ->get()
                                        ->groupBy('target_warehouse_id')
                                        ->map(function ($group) {
                                            $warehouse = $group->first()->targetWarehouse;

                                            return [
                                                'warehouse_id' => $warehouse->id,
                                                'warehouse_name' => $warehouse->name,
                                                'allocation_count' => $group->count(),
                                                'total_qty' => $group->sum('assign_qty'),
                                            ];
                                        })
                                        ->values()
                                        ->toArray();

                                    $coursePartnerIds = WmsShortage::where('wave_id', $shortage->wave_id)
                                        ->where('delivery_course_id', $shortage->delivery_course_id)
                                        ->with('trade')
                                        ->get()
                                        ->pluck('trade.partner_id')
                                        ->filter()
                                        ->unique()
                                        ->values()
                                        ->toArray();

                                    if (! empty($coursePartnerIds)) {
                                        $nearestDistances = \App\Models\WmsPartnerWarehouseDistance::whereIn('partner_id', $coursePartnerIds)
                                            ->orderBy('distance_km', 'asc')
                                            ->get()
                                            ->unique('warehouse_id')
                                            ->take(3);

                                        foreach ($nearestDistances as $dist) {
                                            $warehouse = Warehouse::find($dist->warehouse_id);
                                            if ($warehouse) {
                                                $courseNearestWarehouses[] = [
                                                    'warehouse_id' => $warehouse->id,
                                                    'warehouse_name' => $warehouse->name,
                                                    'distance_km' => $dist->distance_km,
                                                ];
                                            }
                                        }
                                    }
                                }

                                // マップ用位置情報データ
                                $locations = [];

                                // 1. 出発倉庫（departure）
                                $departureWarehouse = $shortage->warehouse;
                                if ($departureWarehouse && $departureWarehouse->latitude && $departureWarehouse->longitude) {
                                    $locations[] = [
                                        'id' => $departureWarehouse->id,
                                        'name' => $departureWarehouse->name,
                                        'lat' => (float) $departureWarehouse->latitude,
                                        'lng' => (float) $departureWarehouse->longitude,
                                        'type' => 'departure',
                                    ];
                                }

                                // 2. 横持ち出荷倉庫（warehouse）
                                $stockByWarehouse = collect($stockData)->keyBy('warehouse_id');
                                $proxyWarehouses = \DB::connection('sakemaru')
                                    ->table('warehouses')
                                    ->join('ret_stores', 'warehouses.code', '=', 'ret_stores.code')
                                    ->whereNotNull('ret_stores.pos_store_code')
                                    ->whereNotNull('warehouses.latitude')
                                    ->whereNotNull('warehouses.longitude')
                                    ->where('warehouses.id', '!=', $shortage->warehouse_id)
                                    ->select('warehouses.id', 'warehouses.name', 'warehouses.latitude', 'warehouses.longitude')
                                    ->get();

                                foreach ($proxyWarehouses as $wh) {
                                    $stockInfo = null;
                                    if ($stockByWarehouse->has($wh->id)) {
                                        $s = $stockByWarehouse->get($wh->id);
                                        $stockInfo = $s['cases'].'CS / '.$s['total_pieces'].'バラ';
                                    }
                                    $locations[] = [
                                        'id' => $wh->id,
                                        'name' => $wh->name,
                                        'lat' => (float) $wh->latitude,
                                        'lng' => (float) $wh->longitude,
                                        'type' => 'warehouse',
                                        'stock_info' => $stockInfo,
                                    ];
                                }

                                // 3. 納品先（customer）: 同一配送コース内の得意先
                                if ($shortage->wave_id && $shortage->delivery_course_id) {
                                    $courseShortages = WmsShortage::where('wave_id', $shortage->wave_id)
                                        ->where('delivery_course_id', $shortage->delivery_course_id)
                                        ->with('trade.partner')
                                        ->get();

                                    $addedPartnerIds = [];
                                    foreach ($courseShortages as $s) {
                                        $partner = $s->trade?->partner;
                                        if ($partner && $partner->latitude && $partner->longitude && ! in_array($partner->id, $addedPartnerIds)) {
                                            $locations[] = [
                                                'id' => $partner->id,
                                                'name' => $partner->name,
                                                'lat' => (float) $partner->latitude,
                                                'lng' => (float) $partner->longitude,
                                                'type' => 'customer',
                                            ];
                                            $addedPartnerIds[] = $partner->id;
                                        }
                                    }
                                }

                                return [
                                    'stocks' => $stockData,
                                    'warehouses' => Warehouse::pluck('name', 'id')->toArray(),
                                    'shortage_qty' => $record->remaining_qty,
                                    'qty_type' => $record->assign_qty_type,
                                    'qty_type_label' => $qtyType ? $qtyType->name() : $record->assign_qty_type,
                                    // Info Table Data
                                    'item_code' => $shortage->item->code ?? '-',
                                    'item_name' => $shortage->item->name ?? '-',
                                    'capacity_case' => $shortage->item->capacity_case ? (string) $shortage->item->capacity_case : '-',
                                    'volume_value' => $volumeValue,
                                    'partner_code' => $shortage->trade->partner->code ?? '-',
                                    'partner_name' => $shortage->trade->partner->name ?? '-',
                                    'warehouse_name' => $shortage->warehouse->name ?? '-',
                                    'order_qty' => (string) $record->assign_qty,
                                    'picked_qty' => (string) $record->picked_qty,
                                    'picked_qty_label' => 'ピック数',
                                    'shortage_details' => $shortageDetailsValue,
                                    // 倉庫推薦データ
                                    'nearest_warehouse_id' => $nearestWarehouseId,
                                    'same_course_allocations' => $sameCourseAllocations,
                                    'course_nearest_warehouses' => $courseNearestWarehouses,
                                    'has_delivery_course' => (bool) $shortage->delivery_course_id,
                                    // マップ用位置情報
                                    'locations' => $locations,
                                ];
                            })
                            ->default([])
                            ->rules([
                                function (WmsShortageAllocation $record) {
                                    return function (string $attribute, $value, \Closure $fail) use ($record) {
                                        if (! is_array($value)) {
                                            return;
                                        }

                                        $totalAllocated = collect($value)->sum(function ($item) {
                                            $qty = $item['assign_qty'] ?? 0;

                                            return is_numeric($qty) ? (int) $qty : 0;
                                        });

                                        if ($totalAllocated > $record->remaining_qty) {
                                            $qtyType = QuantityType::tryFrom($record->assign_qty_type);
                                            $unit = $qtyType ? $qtyType->name() : $record->assign_qty_type;
                                            $fail("追加出荷総数（{$totalAllocated}{$unit}）が残数量（{$record->remaining_qty}{$unit}）を超えています。");
                                        }

                                        $selectedWarehouses = collect($value)
                                            ->pluck('from_warehouse_id')
                                            ->filter()
                                            ->toArray();

                                        // 現在の倉庫が含まれていないかチェック
                                        if (in_array($record->target_warehouse_id, $selectedWarehouses)) {
                                            $fail('現在の出荷元倉庫と同じ倉庫は選択できません。');
                                        }

                                        $counts = array_count_values($selectedWarehouses);
                                        foreach ($counts as $warehouseId => $count) {
                                            if ($count > 1) {
                                                $warehouseName = Warehouse::find($warehouseId)?->name ?? '倉庫';
                                                $fail("{$warehouseName}が重複して選択されています。");
                                                break;
                                            }
                                        }
                                    };
                                },
                            ]),
                    ])
                    ->action(function (WmsShortageAllocation $record, array $data): void {
                        try {
                            $service = app(ProxyShipmentService::class);
                            $createdCount = 0;
                            $totalReallocatedQty = 0;

                            DB::transaction(function () use ($record, $data, $service, &$createdCount, &$totalReallocatedQty) {
                                foreach ($data['allocations'] as $allocation) {
                                    if (empty($allocation['assign_qty']) || $allocation['assign_qty'] <= 0) {
                                        continue;
                                    }

                                    // 新しい横持ち出荷レコードを作成
                                    $service->createProxyShipment(
                                        shortage: $record->shortage,
                                        fromWarehouseId: (int) $allocation['from_warehouse_id'],
                                        assignQty: (int) $allocation['assign_qty'],
                                        assignQtyType: $record->assign_qty_type,
                                        createdBy: auth()->id() ?? 0
                                    );
                                    $createdCount++;
                                    $totalReallocatedQty += (int) $allocation['assign_qty'];
                                }

                                if ($createdCount > 0) {
                                    // 元の横持ち出荷指示の数量を減らす
                                    $record->assign_qty -= $totalReallocatedQty;
                                    $record->save();

                                    // 欠品情報の承認状態を解除
                                    $record->shortage->is_confirmed = false;
                                    $record->shortage->save();
                                }
                            });

                            if ($createdCount > 0) {
                                Notification::make()
                                    ->title("追加の横持ち出荷を{$createdCount}件作成しました")
                                    ->body("元の出荷指示から{$totalReallocatedQty}個を振り分けました。承認状態が解除されました。")
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('追加の出荷指示がありませんでした')
                                    ->warning()
                                    ->send();
                            }
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('エラー')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('bulkComplete')
                        ->label('一括完了')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                        ->modalHeading('横持ち出荷の一括完了確認')
                        ->modalDescription(function (Collection $records): string {
                            $shortageRecords = $records->filter(fn ($r) => $r->remaining_qty > 0);
                            $message = "選択された {$records->count()} 件の横持ち出荷を完了します。";
                            if ($shortageRecords->isNotEmpty()) {
                                $message .= "\n⚠ {$shortageRecords->count()} 件は欠品として確定されます。";
                            }

                            return $message;
                        })
                        ->modalFooterActionsAlignment(Alignment::End)
                        ->modalSubmitAction(
                            fn ($action) => $action
                                ->makeModalSubmitAction('submit', [])
                                ->label('確定')
                                ->color('danger')
                        )
                        ->modalCancelActionLabel('確定せず閉じる')
                        ->action(function (Collection $records): void {
                            $userId = auth()->id();
                            $completedCount = 0;
                            $fulfilledCount = 0;
                            $shortageCount = 0;
                            $queueCreatedCount = 0;
                            $queueErrorCount = 0;
                            $syncQueueCreatedCount = 0;

                            $queueService = app(StockTransferQueueService::class);
                            $allocationSyncService = app(AllocationSyncService::class);

                            foreach ($records as $record) {
                                // 既に完了している、またはステータスがPICKING/RESERVED以外の場合はスキップ
                                if ($record->is_finished || ! in_array($record->status, ['PICKING', 'RESERVED'])) {
                                    continue;
                                }

                                $record->is_finished = true;
                                $record->finished_at = now();
                                $record->finished_user_id = $userId;

                                // 完了時のステータス判定
                                if ($record->picked_qty >= $record->assign_qty) {
                                    $record->status = 'FULFILLED';
                                    $fulfilledCount++;
                                } else {
                                    $record->status = 'SHORTAGE';
                                    $shortageCount++;
                                }

                                $record->save();
                                $completedCount++;

                                // 倉庫移動伝票キューと最終数量同期キューを作成
                                try {
                                    $queueId = $queueService->createStockTransferQueue($record);
                                    if ($queueId) {
                                        $queueCreatedCount++;
                                    }
                                    $syncQueue = $allocationSyncService->syncAllocationIfReady($record->fresh() ?? $record);
                                    if ($syncQueue) {
                                        $syncQueueCreatedCount++;
                                    }
                                } catch (\Exception $e) {
                                    $queueErrorCount++;
                                    \Log::error('Failed to create queue in bulk proxy shipment completion', [
                                        'allocation_id' => $record->id,
                                        'error' => $e->getMessage(),
                                    ]);
                                }
                            }

                            $message = "完了件数: {$completedCount}件 (完了: {$fulfilledCount}件、欠品: {$shortageCount}件)";
                            if ($queueCreatedCount > 0) {
                                $message .= "\n倉庫移動伝票キュー作成: {$queueCreatedCount}件";
                            }
                            if ($syncQueueCreatedCount > 0) {
                                $message .= "\n数量同期キュー作成: {$syncQueueCreatedCount}件";
                            }
                            if ($queueErrorCount > 0) {
                                $message .= "\nキューエラー: {$queueErrorCount}件";
                            }

                            Notification::make()
                                ->title('横持ち出荷を一括完了しました')
                                ->body($message)
                                ->success()
                                ->send();
                        }),
                ]),
            ])
            ->toolbarActions([
                static::getExportAction(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(null);
    }
}
