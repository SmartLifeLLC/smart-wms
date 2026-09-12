<?php

namespace App\Filament\Resources\WmsShortageAllocations\Pages;

use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsShortageAllocations\Tables\WmsShortageAllocationsFinishedTable;
use App\Filament\Resources\WmsShortageAllocations\WmsShortageAllocationResource;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\DeliveryCourse;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsShortageAllocation;
use App\Services\PickingList\PickingListPdfService;
use App\Services\PickingList\ProxyShipmentPickingListService;
use App\Services\QuantityUpdate\AllocationSyncService;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class ListFinishedWmsShortageAllocations extends ListRecords
{
    use AdvancedTables, HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsShortageAllocationResource::class;

    protected static ?string $title = '横持ち出荷完了一覧';

    /**
     * アクティブなプリセットビューから倉庫IDを解決
     */
    protected function resolveWarehouseIdFromPresetView(): ?int
    {
        $activeView = $this->activePresetView;

        if (! $activeView) {
            return auth()->user()?->default_warehouse_id;
        }

        if (str_starts_with($activeView, 'wh_')) {
            return (int) str_replace('wh_', '', $activeView);
        }

        if ($activeView === 'default') {
            $userDefaultWarehouseId = auth()->user()?->default_warehouse_id;
            if ($userDefaultWarehouseId) {
                return $userDefaultWarehouseId;
            }

            return WmsShortageAllocation::where('is_finished', true)
                ->distinct()
                ->pluck('target_warehouse_id')
                ->filter()
                ->first();
        }

        return auth()->user()?->default_warehouse_id;
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function table(Table $table): Table
    {
        $table = WmsShortageAllocationsFinishedTable::configure($table);

        $systemDate = ClientSetting::cachedFirst()?->system_date;

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->where('is_finished', true)
                ->when($systemDate, fn ($q) => $q->whereDate('shipment_date', $systemDate))
                ->with([
                    'shortage.wave',
                    'shortage.warehouse',
                    'shortage.item',
                    'shortage.trade.partner',
                    'targetWarehouse',
                    'sourceWarehouse',
                    'finishedUser',
                    'deliveryCourse',
                ])
            )
            ->toolbarActions([
                WmsShortageAllocationsFinishedTable::getExportAction(),

                // ピッキングリスト出力
                Action::make('printProxyShipmentPickingList')
                    ->label('ピッキングリスト出力')
                    ->icon('heroicon-o-printer')
                    ->color('info')
                    ->modalHeading('横持ち出荷ピッキングリスト出力')
                    ->modalDescription('出荷日・配送コースを選択し、横持ち出荷ピッキングリストを出力します')
                    ->modalWidth('3xl')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])
                        ->label('PDF出力')
                        ->color('danger'))
                    ->modalCancelActionLabel('出力せず閉じる')
                    ->schema([
                        Placeholder::make('warehouse_display')
                            ->label('ピッキング倉庫')
                            ->content(function () {
                                $warehouseId = $this->resolveWarehouseIdFromPresetView();
                                if (! $warehouseId) {
                                    return new HtmlString('<span class="text-gray-400">倉庫が選択されていません</span>');
                                }
                                $warehouse = Warehouse::find($warehouseId);

                                return $warehouse ? "[{$warehouse->code}] {$warehouse->name}" : '';
                            }),

                        Hidden::make('warehouse_id')
                            ->default(fn () => $this->resolveWarehouseIdFromPresetView()),

                        Grid::make(2)->schema([
                            DatePicker::make('shipment_date')
                                ->label('出荷日')
                                ->default(fn () => ClientSetting::systemDateYMD())
                                ->required()
                                ->live(),

                            Select::make('delivery_course_id')
                                ->label('配送コース（任意）')
                                ->helperText('未選択の場合は全コース')
                                ->options(function () {
                                    $warehouseId = $this->resolveWarehouseIdFromPresetView();
                                    if (! $warehouseId) {
                                        return [];
                                    }

                                    $courseIds = WmsShortageAllocation::where('target_warehouse_id', $warehouseId)
                                        ->where('is_finished', true)
                                        ->distinct()
                                        ->pluck('delivery_course_id')
                                        ->toArray();

                                    if (empty($courseIds)) {
                                        return [];
                                    }

                                    return DeliveryCourse::whereIn('id', $courseIds)
                                        ->orderBy('code')
                                        ->get()
                                        ->mapWithKeys(fn ($c) => [$c->id => "[{$c->code}] {$c->name}"])
                                        ->toArray();
                                })
                                ->searchable()
                                ->live(),
                        ]),

                        Placeholder::make('allocation_preview')
                            ->label('対象明細')
                            ->content(function (Get $get) {
                                $warehouseId = $this->resolveWarehouseIdFromPresetView();
                                $shipmentDate = $get('shipment_date');

                                if (! $warehouseId || ! $shipmentDate) {
                                    return new HtmlString('<div class="py-4 text-center text-sm text-gray-400">出荷日を選択してください</div>');
                                }

                                $query = WmsShortageAllocation::where('target_warehouse_id', $warehouseId)
                                    ->where('shipment_date', $shipmentDate)
                                    ->where('is_finished', true);

                                $deliveryCourseId = $get('delivery_course_id');
                                if ($deliveryCourseId) {
                                    $query->where('delivery_course_id', $deliveryCourseId);
                                }

                                $count = $query->count();

                                if ($count === 0) {
                                    return new HtmlString('<div class="py-4 text-center text-sm text-gray-400">対象の横持ち出荷依頼がありません</div>');
                                }

                                return new HtmlString("<div class=\"p-2 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-100 dark:border-blue-800\"><span class=\"text-sm font-bold text-blue-600 dark:text-blue-400\">{$count} 件</span></div>");
                            }),
                    ])
                    ->action(function (array $data) {
                        $warehouseId = $data['warehouse_id'] ?? $this->resolveWarehouseIdFromPresetView();
                        $shipmentDate = $data['shipment_date'];
                        $deliveryCourseId = $data['delivery_course_id'] ?? null;

                        if (! $warehouseId) {
                            Notification::make()->title('倉庫が選択されていません')->warning()->send();

                            return null;
                        }

                        try {
                            $service = new ProxyShipmentPickingListService;
                            $listData = $service->generateList($warehouseId, $shipmentDate, $deliveryCourseId);

                            if (empty($listData['courses'])) {
                                Notification::make()->title('対象の横持ち出荷依頼がありません')->warning()->send();

                                return null;
                            }

                            $operatorName = auth()->user()?->name ?? '';
                            foreach ($listData['courses'] as &$courseData) {
                                $courseData['header']['operator_name'] = $operatorName;
                            }
                            unset($courseData);

                            $pdfService = new PickingListPdfService;
                            $pdf = $pdfService->renderProxyShipmentPdf($listData);

                            $dateStr = str_replace('-', '', $shipmentDate);
                            $filename = "proxy-shipment-picking-list-{$dateStr}.pdf";

                            return response()->streamDownload(
                                fn () => print ($pdf),
                                $filename,
                                ['Content-Type' => 'application/pdf']
                            );
                        } catch (\Exception $e) {
                            Notification::make()->title('PDF生成に失敗しました')->body($e->getMessage())->danger()->send();

                            return null;
                        }
                    }),

                // データ同期
                Action::make('syncAllocations')
                    ->label('データ同期')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->modalHeading('欠品データ同期')
                    ->schema(function () {
                        $warehouseId = $this->resolveWarehouseIdFromPresetView();
                        if (! $warehouseId) {
                            return [
                                Placeholder::make('sync_info')
                                    ->hiddenLabel()
                                    ->content(new HtmlString('<div class="py-4 text-center text-sm text-gray-400">倉庫が選択されていません</div>')),
                            ];
                        }

                        $warehouse = Warehouse::find($warehouseId);
                        $warehouseName = $warehouse ? $warehouse->name : "ID: {$warehouseId}";
                        $shipmentDate = ClientSetting::systemDate(true)?->toDateString();

                        $syncService = app(AllocationSyncService::class);
                        $unsyncedCount = $shipmentDate ? $syncService->getUnsyncedCount($warehouseId, $shipmentDate) : 0;

                        if ($unsyncedCount === 0) {
                            return [
                                Placeholder::make('sync_info')
                                    ->hiddenLabel()
                                    ->content(new HtmlString(
                                        '<div class="py-4 text-center">'
                                        .'<div class="text-base font-semibold text-gray-600 dark:text-gray-300">【'.$warehouseName.'】</div>'
                                        .'<div class="mt-1 text-xs text-gray-400">出荷日: '.e($shipmentDate ?? '未設定').'</div>'
                                        .'<div class="mt-2 text-sm text-gray-400">同期が必要な欠品データはありません。</div>'
                                        .'</div>'
                                    )),
                            ];
                        }

                        return [
                            Placeholder::make('sync_info')
                                ->hiddenLabel()
                                ->content(new HtmlString(
                                    '<div class="py-4 text-center">'
                                    .'<div class="text-base font-semibold text-gray-700 dark:text-gray-200">【'.$warehouseName.'】</div>'
                                    .'<div class="mt-1 text-xs text-gray-500 dark:text-gray-400">出荷日: '.e($shipmentDate ?? '未設定').'</div>'
                                    .'<div class="mt-3 text-lg font-bold text-orange-600 dark:text-orange-400">未同期の欠品データが <span class="text-2xl">'.$unsyncedCount.'</span> 件あります。</div>'
                                    .'<div class="mt-2 text-sm text-gray-500 dark:text-gray-400">ai-coreへ数量修正データを同期しますか？</div>'
                                    .'</div>'
                                )),
                        ];
                    })
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->modalSubmitAction(function ($action) {
                        $warehouseId = $this->resolveWarehouseIdFromPresetView();
                        $shipmentDate = ClientSetting::systemDate(true)?->toDateString();
                        $syncService = app(AllocationSyncService::class);
                        $unsyncedCount = ($warehouseId && $shipmentDate) ? $syncService->getUnsyncedCount($warehouseId, $shipmentDate) : 0;

                        if ($unsyncedCount === 0) {
                            return $action->makeModalSubmitAction('submit', [])
                                ->label('同期')
                                ->color('danger')
                                ->disabled();
                        }

                        return $action->makeModalSubmitAction('submit', [])
                            ->label('同期')
                            ->color('danger');
                    })
                    ->modalCancelActionLabel('同期せず閉じる')
                    ->action(function () {
                        $warehouseId = $this->resolveWarehouseIdFromPresetView();
                        if (! $warehouseId) {
                            Notification::make()->title('倉庫が選択されていません')->warning()->send();

                            return;
                        }

                        try {
                            $syncService = app(AllocationSyncService::class);
                            $shipmentDate = ClientSetting::systemDate(true)?->toDateString();
                            if (! $shipmentDate) {
                                Notification::make()->title('出荷日が取得できません')->warning()->send();

                                return;
                            }

                            $result = $syncService->syncByWarehouse($warehouseId, $shipmentDate);

                            if ($result['syncedCount'] === 0 && empty($result['errors'])) {
                                Notification::make()
                                    ->title('同期対象のデータがありません')
                                    ->info()
                                    ->send();

                                return;
                            }

                            $message = "同期件数: {$result['syncedCount']}件\nキュー作成: {$result['queueCreated']}件";
                            if ($result['skipped'] > 0) {
                                $message .= "\nスキップ: {$result['skipped']}件";
                            }

                            if (! empty($result['errors'])) {
                                $message .= "\nエラー: ".count($result['errors']).'件';
                                Notification::make()
                                    ->title('データ同期が完了しました（一部エラーあり）')
                                    ->body($message)
                                    ->warning()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('データ同期が完了しました')
                                    ->body($message)
                                    ->success()
                                    ->send();
                            }
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('データ同期に失敗しました')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }

    public function getPresetViews(): array
    {
        $userDefaultWarehouseId = auth()->user()?->default_warehouse_id;

        // 完了済みの横持ち出荷依頼で使われている倉庫を取得
        $warehouseIds = WmsShortageAllocation::where('is_finished', true)
            ->distinct()
            ->pluck('target_warehouse_id')
            ->filter()
            ->toArray();

        // デフォルト倉庫がデータになくても含める
        if ($userDefaultWarehouseId && ! in_array($userDefaultWarehouseId, $warehouseIds)) {
            $warehouseIds[] = $userDefaultWarehouseId;
        }

        $warehouses = Warehouse::whereIn('id', $warehouseIds)
            ->where('is_virtual', false)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $defaultWarehouse = $userDefaultWarehouseId
            ? $warehouses->firstWhere('id', $userDefaultWarehouseId)
            : null;

        $views = [];

        if ($defaultWarehouse) {
            $views['default'] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('target_warehouse_id', $userDefaultWarehouseId))
                ->favorite()
                ->label($defaultWarehouse->name)
                ->default();
        } elseif ($warehouses->isNotEmpty()) {
            $first = $warehouses->first();
            $views['default'] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('target_warehouse_id', $first->id))
                ->favorite()
                ->label($first->name)
                ->default();
        }

        foreach ($warehouses as $warehouse) {
            if ($defaultWarehouse && $warehouse->id === $defaultWarehouse->id) {
                continue;
            }
            if (! $defaultWarehouse && $warehouse->id === $warehouses->first()->id) {
                continue;
            }
            $views["wh_{$warehouse->id}"] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('target_warehouse_id', $warehouse->id))
                ->favorite()
                ->label($warehouse->name);
        }

        return $views;
    }
}
