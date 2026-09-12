<?php

namespace App\Filament\Resources\WmsShipmentSlips\Tables;

use App\Enums\PaginationOptions;
use App\Enums\QuantityType;
use App\Filament\Concerns\HasExportAction;
use App\Filament\Concerns\HasOptimizedFilters;
use App\Models\Sakemaru\ClientPrinterDriver;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\DeliveryCourse;
use App\Models\Sakemaru\Warehouse;
use App\Models\SpecificSlipPrintRequestQueue;
use App\Models\WmsPickingItemResult;
use App\Models\WmsPickingTask;
use App\Models\WmsShortage;
use App\Services\Print\PrintRequestService;
use App\Services\Print\SpecificSlipPrintQueueService;
use App\Services\Print\SpecificSlipPrintTargetService;
use App\Services\QuantityUpdate\QuantityUpdateQueueService;
use App\Services\Shortage\ShortageApprovalService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Size;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

class WmsShipmentSlipsTable
{
    use HasExportAction;
    use HasOptimizedFilters;

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
                    ->sortable(),

                TextColumn::make('sync_status')
                    ->label('在庫同期')
                    ->badge()
                    ->state(fn ($record) => $record->is_stock_synced ? '同期済' : '同期中')
                    ->color(fn ($record) => $record->is_stock_synced ? 'success' : 'warning')
                    ->icon(fn ($record) => $record->is_stock_synced ? 'heroicon-o-check-circle' : 'heroicon-o-arrow-path')
                    ->alignCenter(),

                TextColumn::make('delivery_course_code')
                    ->label('配送コード')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('deliveryCourse.name')
                    ->label('配送コース名')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('wave.wave_no')
                    ->label('波動識別ID')
                    ->description(fn ($record) => $record->wave?->waveSetting?->name)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('shipment_date')
                    ->label('納品日')
                    ->date('Y-m-d')
                    ->sortable(),

                TextColumn::make('warehouse.name')
                    ->label('倉庫'),

                TextColumn::make('grouped_status')
                    ->label('ステータス')
                    ->badge()
                    ->state(function ($record) {
                        $allCompleted = $record->grouped_tasks->every(fn ($task) => in_array($task->status, ['COMPLETED', 'SHIPPED']));
                        $anyPicking = $record->grouped_tasks->contains(fn ($task) => $task->status === 'PICKING');

                        if ($allCompleted) {
                            return 'COMPLETED';
                        } elseif ($anyPicking) {
                            return 'PICKING';
                        }

                        return 'PENDING';
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'PENDING' => 'warning',
                        'PICKING' => 'info',
                        'COMPLETED' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'PENDING' => '待機中',
                        'PICKING' => 'ピッキング中',
                        'COMPLETED' => '完了',
                        default => $state ?? '-',
                    }),

                TextColumn::make('wave.created_at')
                    ->label('生成日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->placeholder('-'),

                TextColumn::make('wave.print_count')
                    ->label('印刷回数')
                    ->suffix('回')
                    ->alignCenter(),

                TextColumn::make('last_printed_at')
                    ->label('最終印刷時刻')
                    ->state(fn ($record) => $record->last_printed_at)
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('-')
                    ->alignCenter(),

                TextColumn::make('lastPrintedByUser.name')
                    ->label('最終印刷者')
                    ->placeholder('-')
                    ->alignCenter(),

                TextColumn::make('last_printed_printer_name')
                    ->label('最終印刷先')
                    ->state(function ($record) {
                        if (! $record->last_printed_at) {
                            return null;
                        }

                        return $record->lastPrintedPrinter?->name ?? 'PDFのみ';
                    })
                    ->placeholder('-')
                    ->alignCenter(),
            ])
            ->filters([
                static::warehouseFilter(),

                SelectFilter::make('delivery_course_id')
                    ->label('配送コース')
                    ->searchable()
                    ->getSearchResultsUsing(function (string $search): array {
                        $search = mb_convert_kana($search, 'as');

                        return DeliveryCourse::query()
                            ->where(fn ($q) => $q
                                ->where('code', 'like', "%{$search}%")
                                ->orWhere('name', 'like', "%{$search}%"))
                            ->orderBy('code')
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn ($c) => [$c->id => "[{$c->code}]{$c->name}"])
                            ->toArray();
                    }),

                SelectFilter::make('print_status')
                    ->label('出荷確定状態')
                    ->options([
                        'unprinted' => '未出荷確定',
                        'printed' => '出荷確定済み',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'printed' => $query->where(function (Builder $query) {
                                $query->where('print_requested_count', '>', 0)
                                    ->orWhereNotNull('last_printed_at')
                                    ->orWhereHas('wave', fn (Builder $query) => $query->where('print_count', '>', 0));
                            }),
                            'unprinted' => $query
                                ->where(function (Builder $query) {
                                    $query->whereNull('print_requested_count')
                                        ->orWhere('print_requested_count', 0);
                                })
                                ->whereNull('last_printed_at')
                                ->where(function (Builder $query) {
                                    $query->whereDoesntHave('wave')
                                        ->orWhereHas('wave', fn (Builder $query) => $query
                                            ->whereNull('print_count')
                                            ->orWhere('print_count', 0));
                                }),
                            default => $query,
                        };
                    }),

                \Filament\Tables\Filters\Filter::make('shipment_date')
                    ->label('出荷日')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('shipment_date')
                            ->label('出荷日'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['shipment_date'],
                            fn (Builder $query, $date) => $query->where('shipment_date', $date),
                        );
                    }),
            ])
            ->recordAction('')
            ->recordActionsColumnLabel('操作')
            ->recordActions([
                Action::make('addShortage')
                    ->hidden()
                    ->extraAttributes(['class' => 'whitespace-nowrap'])
                    ->label('追加欠品')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('warning')
                    ->modalHeading('追加欠品')
                    ->modalWidth('7xl')
                    ->schema(fn (WmsPickingTask $record) => [
                        static::additionalShortagesSection($record),
                    ])
                    ->modalSubmitActionLabel('追加欠品を登録')
                    ->action(function (WmsPickingTask $record, array $data) {
                        $additionalShortageRows = static::normalizeAdditionalShortageRows($data['additional_shortages'] ?? []);

                        try {
                            static::validateAdditionalShortageRows($record, $additionalShortageRows);
                        } catch (\RuntimeException $e) {
                            Notification::make()
                                ->title('追加欠品を登録できません')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        $additionalShortageCount = static::createAdditionalConfirmedShortages(
                            $record,
                            $additionalShortageRows,
                            auth()->id()
                        );

                        if ($additionalShortageCount === 0) {
                            Notification::make()
                                ->title('追加欠品は登録されませんでした')
                                ->body('追加する欠品明細を入力してください。')
                                ->warning()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('追加欠品を登録しました')
                            ->body("追加欠品を{$additionalShortageCount}件登録しました。伝票発行後に在庫更新キューを作成します。")
                            ->success()
                            ->send();
                    }),

                Action::make('print')
                    ->extraAttributes(['class' => 'whitespace-nowrap'])
                    ->label(function (WmsPickingTask $record) {
                        $printCount = $record->wave->print_count ?? 0;

                        return $printCount === 0 ? '出荷確定(伝票印刷)' : '伝票再印刷';
                    })
                    ->icon('heroicon-o-printer')
                    ->color(function (WmsPickingTask $record) {
                        $approvalService = app(ShortageApprovalService::class);
                        $printability = $approvalService->checkPrintability(
                            $record->delivery_course_id,
                            $record->shipment_date,
                            $record->wave_id
                        );

                        return $printability['can_print'] ? 'primary' : 'warning';
                    })
                    ->requiresConfirmation()
                    ->modalHeading(function (WmsPickingTask $record) {
                        $printCount = $record->wave->print_count ?? 0;

                        return $printCount === 0 ? '出荷確定(伝票印刷)' : '伝票再印刷';
                    })
                    ->modalWidth('3xl')
                    ->modalDescription(function (WmsPickingTask $record) {
                        $approvalService = app(ShortageApprovalService::class);
                        $printability = $approvalService->checkPrintability(
                            $record->delivery_course_id,
                            $record->shipment_date,
                            $record->wave_id
                        );

                        if (! $printability['can_print']) {
                            return new \Illuminate\Support\HtmlString(
                                self::buildPrintabilityErrorHtml($printability)
                            );
                        }

                        return 'この配送コースの伝票を印刷します。プリンターを選択してください。';
                    })
                    ->schema(function (WmsPickingTask $record) {
                        return [
                            Section::make('プリンター選択')
                                ->schema([
                                    Select::make('printer_warehouse_id')
                                        ->label('倉庫')
                                        ->options(
                                            Warehouse::query()
                                                ->where('is_active', true)
                                                ->pluck('name', 'id')
                                        )
                                        ->default($record->warehouse_id)
                                        ->searchable()
                                        ->live()
                                        ->afterStateUpdated(fn ($set) => $set('printer_driver_id', null)),

                                    Select::make('printer_driver_id')
                                        ->label('プリンター')
                                        ->options(function (Get $get) {
                                            $warehouseId = $get('printer_warehouse_id');
                                            if (! $warehouseId) {
                                                return [];
                                            }

                                            $printers = ClientPrinterDriver::query()
                                                ->where('warehouse_id', $warehouseId)
                                                ->where('is_active', true)
                                                ->get()
                                                ->mapWithKeys(fn ($p) => [
                                                    $p->id => filled($p->user_name) ? $p->user_name : $p->display_name,
                                                ]);

                                            if ($printers->isEmpty()) {
                                                return ['' => 'なし（PDFのみ生成）'];
                                            }

                                            return ['' => 'なし（PDFのみ生成）'] + $printers->toArray();
                                        })
                                        ->default('')
                                        ->extraAlpineAttributes(function () {
                                            return [
                                                'x-init' => "
                                                    const savedPrinterId = localStorage.getItem('wms-shipment-slips.printer')
                                                    if ((state === null || state === undefined || state === '') && savedPrinterId !== null) {
                                                        state = savedPrinterId
                                                    }
                                                ",
                                                'x-effect' => "
                                                    if (state !== null && state !== undefined) {
                                                        localStorage.setItem('wms-shipment-slips.printer', state)
                                                    }
                                                ",
                                            ];
                                        })
                                        ->live()
                                        ->searchable()
                                        ->helperText(function (Get $get) {
                                            $printerId = $get('printer_driver_id');
                                            if (! $printerId) {
                                                return '印刷されず、酒丸側でPDFのみが生成されます。';
                                            }

                                            return null;
                                        }),
                                ])
                                ->compact(),
                        ];
                    })
                    ->modalSubmitActionLabel(function (WmsPickingTask $record) {
                        $printCount = $record->wave->print_count ?? 0;

                        return $printCount === 0 ? '出荷確定(伝票印刷)' : '伝票再印刷';
                    })
                    ->action(function (WmsPickingTask $record, array $data) {
                        $shipmentDate = $record->shipment_date;
                        $isShipmentConfirmationPrint = static::isShipmentConfirmationPrint($record);

                        // 印刷可能性チェック
                        $approvalService = app(ShortageApprovalService::class);
                        $printability = $approvalService->checkPrintability(
                            $record->delivery_course_id,
                            $shipmentDate,
                            $record->wave_id
                        );

                        // モーダルで選択されたプリンター（空文字列はnullに変換）
                        $selectedPrinterId = ! empty($data['printer_driver_id'])
                            ? (int) $data['printer_driver_id']
                            : null;
                        $useDefaultPrinter = false;

                        // 印刷依頼を作成
                        $printService = app(PrintRequestService::class);
                        $result = $printService->createPrintRequest(
                            $record->delivery_course_id,
                            $shipmentDate,
                            $record->warehouse_id,
                            $record->wave_id,
                            $selectedPrinterId,
                            $useDefaultPrinter
                        );

                        if (! $result['success']) {
                            Notification::make()
                                ->title('エラー')
                                ->body($result['message'])
                                ->danger()
                                ->send();

                            return;
                        }

                        if ($result['already_queued'] ?? false) {
                            Notification::make()
                                ->title('印刷依頼は作成済みです')
                                ->body('同じ配送コースの未処理印刷依頼が既にあるため、重複作成は行いませんでした。')
                                ->warning()
                                ->send();

                            return;
                        }

                        // 同じ配送コース・納品日・Waveのタスクをすべて取得
                        $query = WmsPickingTask::where('shipment_date', $shipmentDate)
                            ->where('delivery_course_id', $record->delivery_course_id);

                        if ($record->wave_id) {
                            $query->where('wave_id', $record->wave_id);
                        }

                        $tasksToUpdate = $query->get();

                        // print_requested_countをインクリメント + COMPLETED/SHORTAGEタスクをSHIPPEDに変更 + 最終印刷情報を記録
                        $printedAt = now();
                        $printedBy = auth()->id();
                        foreach ($tasksToUpdate as $task) {
                            $task->increment('print_requested_count');
                            $updateData = [
                                'last_printed_at' => $printedAt,
                                'last_printed_by' => $printedBy,
                                'last_printed_printer_id' => $selectedPrinterId,
                            ];
                            if (in_array($task->status, [WmsPickingTask::STATUS_COMPLETED, WmsPickingTask::STATUS_SHORTAGE])) {
                                $updateData['status'] = WmsPickingTask::STATUS_SHIPPED;
                            }
                            $task->update($updateData);
                        }

                        // Waveの印刷回数をインクリメント、初回出荷確定時はstatusをCOMPLETEDに更新
                        if ($record->wave) {
                            $record->wave->increment('print_count');
                            if ($isShipmentConfirmationPrint) {
                                $record->wave->update(['status' => 'COMPLETED']);
                            }
                        }

                        $additionalShortageQueueCount = static::createQuantityQueuesForAdditionalShortages($record);

                        $hasPrinter = $result['has_printer'] ?? true;
                        if (! $printability['can_print']) {
                            $title = '強制印刷依頼';
                            $notificationType = 'warning';
                        } elseif (! $hasPrinter) {
                            $title = 'PDF生成依頼';
                            $notificationType = 'warning';
                        } else {
                            $title = '印刷依頼';
                            $notificationType = 'success';
                        }

                        $bodyParts = ['伝票処理を依頼しました。（売上'.$result['earning_count'].'件、タスク'.$tasksToUpdate->count().'件）'];
                        if ($result['no_print_targets'] ?? false) {
                            $title = '出荷確定';
                            $notificationType = 'success';
                            $bodyParts = ['印刷対象の売上・倉庫移動がないため、伝票キューを作らず出荷確定を完了しました。'];
                        }
                        if (! $hasPrinter && ! ($result['no_print_targets'] ?? false)) {
                            $bodyParts[] = '※プリンター未設定のためPDFのみ生成されます。';
                        }
                        if ($additionalShortageQueueCount > 0) {
                            $bodyParts[] = "追加欠品の在庫更新キューを{$additionalShortageQueueCount}件作成しました。";
                        }

                        Notification::make()
                            ->title($title)
                            ->body(implode("\n", $bodyParts))
                            ->{$notificationType}()
                            ->send();
                    }),

                Action::make('specificSlipPrint')
                    ->extraAttributes(['class' => 'whitespace-nowrap'])
                    ->label('専用伝票')
                    ->badge(fn (WmsPickingTask $record) => static::specificSlipActionBadge($record))
                    ->badgeColor(fn (WmsPickingTask $record) => static::specificSlipActionBadgeColor($record))
                    ->icon('heroicon-o-document-duplicate')
                    ->color('info')
                    ->visible(fn (WmsPickingTask $record) => static::recordHasSpecificSlipTargets($record))
                    ->modalHeading('専用伝票印刷')
                    ->modalWidth('7xl')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal specific-slip-print-modal'])
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->modalCancelActionLabel('印刷せず閉じる')
                    ->schema(fn (WmsPickingTask $record) => static::specificSlipPaperConfirmationSchema($record, true))
                    ->modalSubmitAction(false)
                    ->action(fn (): null => null),

                Action::make('resetPrint')
                    ->extraAttributes(['class' => 'whitespace-nowrap'])
                    ->label('印刷消し')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (WmsPickingTask $record) => app()->environment('production') === false && ($record->wave->print_count ?? 0) > 0)
                    ->requiresConfirmation()
                    ->modalHeading('印刷消し')
                    ->modalDescription('この配送コースの印刷状態をリセットし、再度出荷確定できるようにします。')
                    ->modalSubmitActionLabel('リセット')
                    ->action(function (WmsPickingTask $record) {
                        $query = WmsPickingTask::where('shipment_date', $record->shipment_date)
                            ->where('delivery_course_id', $record->delivery_course_id);

                        if ($record->wave_id) {
                            $query->where('wave_id', $record->wave_id);
                        }

                        $tasks = $query->get();
                        $taskIds = $tasks->pluck('id')->all();

                        foreach ($tasks as $task) {
                            $update = [
                                'print_requested_count' => 0,
                                'last_printed_at' => null,
                                'last_printed_by' => null,
                                'last_printed_printer_id' => null,
                            ];
                            if ($task->status === WmsPickingTask::STATUS_SHIPPED) {
                                $update['status'] = WmsPickingTask::STATUS_COMPLETED;
                            }
                            $task->update($update);
                        }

                        if ($record->wave) {
                            $record->wave->update(['print_count' => 0]);
                        }

                        Notification::make()
                            ->success()
                            ->title('印刷状態をリセットしました')
                            ->body(count($taskIds).'件のタスクをリセットしました')
                            ->send();
                    }),
            ], position: RecordActionsPosition::BeforeColumns)
            ->checkIfRecordIsSelectableUsing(function (WmsPickingTask $record): bool {
                $printCount = $record->wave->print_count ?? 0;

                return $printCount === 0 && $record->is_stock_synced;
            })
            ->groupedBulkActions([
                BulkAction::make('bulkPrint')
                    ->label('一括出荷確定')
                    ->icon('heroicon-o-printer')
                    ->color('primary')
                    ->modalHeading('一括出荷確定')
                    ->modalWidth('3xl')
                    ->schema(function () {
                        return [
                            Section::make('プリンター選択')
                                ->schema([
                                    Select::make('printer_warehouse_id')
                                        ->label('倉庫')
                                        ->options(
                                            Warehouse::query()
                                                ->where('is_active', true)
                                                ->pluck('name', 'id')
                                        )
                                        ->default(fn () => auth()->user()?->default_warehouse_id)
                                        ->searchable()
                                        ->live()
                                        ->afterStateUpdated(fn ($set) => $set('printer_driver_id', null)),

                                    Select::make('printer_driver_id')
                                        ->label('プリンター')
                                        ->options(function (Get $get) {
                                            $warehouseId = $get('printer_warehouse_id');
                                            if (! $warehouseId) {
                                                return [];
                                            }

                                            $printers = ClientPrinterDriver::query()
                                                ->where('warehouse_id', $warehouseId)
                                                ->where('is_active', true)
                                                ->get()
                                                ->mapWithKeys(fn ($p) => [
                                                    $p->id => filled($p->user_name) ? $p->user_name : $p->display_name,
                                                ]);

                                            if ($printers->isEmpty()) {
                                                return ['' => 'なし（PDFのみ生成）'];
                                            }

                                            return ['' => 'なし（PDFのみ生成）'] + $printers->toArray();
                                        })
                                        ->default('')
                                        ->extraAlpineAttributes([
                                            'x-init' => "
                                                const savedPrinterId = localStorage.getItem('wms-shipment-slips.printer')
                                                if ((state === null || state === undefined || state === '') && savedPrinterId !== null) {
                                                    state = savedPrinterId
                                                }
                                            ",
                                            'x-effect' => "
                                                if (state !== null && state !== undefined) {
                                                    localStorage.setItem('wms-shipment-slips.printer', state)
                                                }
                                            ",
                                        ])
                                        ->live()
                                        ->searchable()
                                        ->helperText(function (Get $get) {
                                            $printerId = $get('printer_driver_id');
                                            if (! $printerId) {
                                                return '印刷されず、酒丸側でPDFのみが生成されます。';
                                            }

                                            return null;
                                        }),
                                ])
                                ->compact(),
                        ];
                    })
                    ->modalDescription(function (Collection $records): \Illuminate\Support\HtmlString|string {
                        $approvalService = app(ShortageApprovalService::class);

                        $printableCount = 0;
                        $alreadyPrintedRecords = [];
                        $forcePrintRecords = [];

                        foreach ($records as $record) {
                            // 既に出荷確定済み（印刷回数1以上）はスキップ対象
                            $printCount = $record->wave->print_count ?? 0;
                            if ($printCount > 0) {
                                $alreadyPrintedRecords[] = [
                                    'course_code' => $record->delivery_course_code,
                                    'course_name' => $record->deliveryCourse?->name ?? '-',
                                    'print_count' => $printCount,
                                ];

                                continue;
                            }

                            $printability = $approvalService->checkPrintability(
                                $record->delivery_course_id,
                                $record->shipment_date,
                                $record->wave_id
                            );

                            if ($printability['can_print']) {
                                $printableCount++;
                            } else {
                                $forcePrintRecords[] = [
                                    'course_code' => $record->delivery_course_code,
                                    'course_name' => $record->deliveryCourse?->name ?? '-',
                                    'error' => $printability['error_message'],
                                ];
                            }
                        }

                        if (empty($alreadyPrintedRecords) && empty($forcePrintRecords)) {
                            return "選択された {$records->count()} 件の配送コースを出荷確定します。";
                        }

                        $html = '<div class="space-y-4">';

                        if ($printableCount > 0) {
                            $html .= '<div class="text-success-600 dark:text-success-400">';
                            $html .= "出荷確定可能: {$printableCount} 件";
                            $html .= '</div>';
                        }

                        // 既に出荷確定済み
                        if (! empty($alreadyPrintedRecords)) {
                            $html .= '<div class="text-warning-600 dark:text-warning-400 font-medium">';
                            $html .= '以下は既に出荷確定済みのため、対象外です（個別に再印刷してください）:';
                            $html .= '</div>';

                            $html .= '<div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3 max-h-32 overflow-y-auto">';
                            $html .= '<table class="w-full text-sm">';
                            $html .= '<thead><tr class="text-left text-gray-500 dark:text-gray-400">';
                            $html .= '<th class="pb-2">コード</th><th class="pb-2">配送コース名</th><th class="pb-2">印刷回数</th>';
                            $html .= '</tr></thead><tbody>';

                            foreach ($alreadyPrintedRecords as $ap) {
                                $html .= '<tr class="border-t border-gray-200 dark:border-gray-700">';
                                $html .= '<td class="py-1">'.e($ap['course_code']).'</td>';
                                $html .= '<td class="py-1">'.e(mb_substr($ap['course_name'], 0, 15)).'</td>';
                                $html .= '<td class="py-1">'.e($ap['print_count']).'回</td>';
                                $html .= '</tr>';
                            }

                            $html .= '</tbody></table>';
                            $html .= '</div>';
                        }

                        // 強制印刷が必要なもの
                        if (! empty($forcePrintRecords)) {
                            $html .= '<div class="text-danger-600 dark:text-danger-400 font-medium">';
                            $html .= '以下は欠品対応または在庫同期が未完了ですが、強制出荷確定します:';
                            $html .= '</div>';

                            $html .= '<div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3 max-h-32 overflow-y-auto">';
                            $html .= '<table class="w-full text-sm">';
                            $html .= '<thead><tr class="text-left text-gray-500 dark:text-gray-400">';
                            $html .= '<th class="pb-2">コード</th><th class="pb-2">配送コース名</th><th class="pb-2">理由</th>';
                            $html .= '</tr></thead><tbody>';

                            foreach ($forcePrintRecords as $fp) {
                                $html .= '<tr class="border-t border-gray-200 dark:border-gray-700">';
                                $html .= '<td class="py-1">'.e($fp['course_code']).'</td>';
                                $html .= '<td class="py-1">'.e(mb_substr($fp['course_name'], 0, 15)).'</td>';
                                $html .= '<td class="py-1 text-danger-600">'.e(mb_substr($fp['error'], 0, 20)).'</td>';
                                $html .= '</tr>';
                            }

                            $html .= '</tbody></table>';
                            $html .= '</div>';
                        }

                        $forcePrintCount = count($forcePrintRecords);
                        $processCount = $printableCount + $forcePrintCount;

                        if ($processCount > 0) {
                            $html .= '<div class="text-sm text-gray-600 dark:text-gray-400">';
                            $html .= "{$processCount} 件を出荷確定します。";
                            if ($forcePrintCount > 0) {
                                $html .= "（うち {$forcePrintCount} 件は強制出荷確定）";
                            }
                            $html .= '</div>';
                        } else {
                            $html .= '<div class="text-sm text-danger-600 dark:text-danger-400">';
                            $html .= '出荷確定対象の配送コースがありません。';
                            $html .= '</div>';
                        }

                        $html .= '</div>';

                        return new \Illuminate\Support\HtmlString($html);
                    })
                    ->modalSubmitActionLabel('一括出荷確定')
                    ->action(function (Collection $records, array $data, $livewire): void {
                        $selectedPrinterId = ! empty($data['printer_driver_id'])
                            ? (int) $data['printer_driver_id']
                            : null;

                        $records = static::sortRecordsForBulkPrint($records, $livewire);

                        $printService = app(PrintRequestService::class);
                        $approvalService = app(ShortageApprovalService::class);
                        $successCount = 0;
                        $forcePrintCount = 0;
                        $alreadyPrintedCount = 0;
                        $alreadyQueuedCount = 0;
                        $errorCount = 0;
                        $totalEarnings = 0;
                        $totalTasks = 0;

                        foreach ($records as $record) {
                            // 既に出荷確定済み（印刷回数1以上）はスキップ
                            $printCount = $record->wave->print_count ?? 0;
                            if ($printCount > 0) {
                                $alreadyPrintedCount++;

                                continue;
                            }

                            // 印刷不可のものも、確認後の一括処理では強制出荷確定する
                            $printability = $approvalService->checkPrintability(
                                $record->delivery_course_id,
                                $record->shipment_date,
                                $record->wave_id
                            );

                            if (! $printability['can_print']) {
                                $forcePrintCount++;
                            }

                            try {
                                // 印刷依頼を作成
                                $result = $printService->createPrintRequest(
                                    $record->delivery_course_id,
                                    $record->shipment_date,
                                    $record->warehouse_id,
                                    $record->wave_id,
                                    $selectedPrinterId
                                );

                                if ($result['success']) {
                                    if ($result['already_queued'] ?? false) {
                                        $alreadyQueuedCount++;

                                        continue;
                                    }

                                    // 同じ配送コース・納品日・Waveのタスクをすべて取得
                                    $query = WmsPickingTask::where('shipment_date', $record->shipment_date)
                                        ->where('delivery_course_id', $record->delivery_course_id);

                                    if ($record->wave_id) {
                                        $query->where('wave_id', $record->wave_id);
                                    }

                                    $tasksToUpdate = $query->get();

                                    // print_requested_countをインクリメント + COMPLETED/SHORTAGEタスクをSHIPPEDに変更 + 最終印刷情報を記録
                                    $printedAt = now();
                                    $printedBy = auth()->id();
                                    foreach ($tasksToUpdate as $task) {
                                        $task->increment('print_requested_count');
                                        $updateData = [
                                            'last_printed_at' => $printedAt,
                                            'last_printed_by' => $printedBy,
                                            'last_printed_printer_id' => $selectedPrinterId,
                                        ];
                                        if (in_array($task->status, [WmsPickingTask::STATUS_COMPLETED, WmsPickingTask::STATUS_SHORTAGE])) {
                                            $updateData['status'] = WmsPickingTask::STATUS_SHIPPED;
                                        }
                                        $task->update($updateData);
                                    }

                                    // Waveの印刷回数をインクリメント、初回出荷確定時はstatusをCOMPLETEDに更新
                                    if ($record->wave) {
                                        $isFirstPrint = $record->wave->print_count === 0;
                                        $record->wave->increment('print_count');
                                        if ($isFirstPrint) {
                                            $record->wave->update(['status' => 'COMPLETED']);
                                        }
                                    }

                                    $successCount++;
                                    $totalEarnings += $result['earning_count'];
                                    $totalTasks += $tasksToUpdate->count();
                                } else {
                                    $errorCount++;
                                }
                            } catch (\Exception $e) {
                                $errorCount++;
                            }
                        }

                        $totalSkipped = $alreadyPrintedCount + $alreadyQueuedCount;

                        if ($successCount === 0 && $totalSkipped > 0) {
                            $reasons = [];
                            if ($alreadyPrintedCount > 0) {
                                $reasons[] = "{$alreadyPrintedCount}件は出荷確定済み";
                            }
                            if ($alreadyQueuedCount > 0) {
                                $reasons[] = "{$alreadyQueuedCount}件は印刷依頼作成済み";
                            }

                            Notification::make()
                                ->title('出荷確定できません')
                                ->body('選択されたすべての配送コースが対象外です。'.implode('、', $reasons).'。')
                                ->danger()
                                ->send();

                            return;
                        }

                        $message = "出荷確定完了: {$successCount}件成功";
                        if ($alreadyPrintedCount > 0) {
                            $message .= "、{$alreadyPrintedCount}件スキップ（出荷確定済み）";
                        }
                        if ($alreadyQueuedCount > 0) {
                            $message .= "、{$alreadyQueuedCount}件スキップ（印刷依頼作成済み）";
                        }
                        if ($forcePrintCount > 0) {
                            $message .= "、{$forcePrintCount}件強制出荷確定";
                        }
                        if ($errorCount > 0) {
                            $message .= "、{$errorCount}件失敗";
                        }
                        $message .= "（売上{$totalEarnings}件、タスク{$totalTasks}件）";

                        Notification::make()
                            ->title('一括出荷確定')
                            ->body($message)
                            ->success()
                            ->send();
                    }),
            ])
            ->modifyQueryUsing(function (Builder $query) {
                // 各配送コース・Wave ID・出荷日ごとに最初のレコードのみ取得
                $query->whereIn('id', function ($subQuery) {
                    $subQuery->select(DB::raw('MIN(id)'))
                        ->from('wms_picking_tasks')
                        ->groupBy('shipment_date', 'delivery_course_id', 'wave_id');
                })
                    ->whereExists(fn ($query) => static::printTargetExistsQuery($query))
                    ->with(['deliveryCourse', 'warehouse', 'wave.waveSetting', 'lastPrintedByUser', 'lastPrintedPrinter']);
            })
            ->pushToolbarActions([
                static::getExportAction(),
            ])
            ->defaultSort('wave.created_at', 'desc')
            ->poll(function ($livewire): ?string {
                if ($livewire->allSynced ?? false) {
                    return null;
                }

                return '5s';
            });
    }

    protected static function recordHasSpecificSlipTargets(WmsPickingTask $record): bool
    {
        if (array_key_exists('has_dedicated_slip', $record->getAttributes())) {
            return (bool) $record->getAttribute('has_dedicated_slip');
        }

        return app(SpecificSlipPrintTargetService::class)
            ->collectForRecord($record)
            ->isNotEmpty();
    }

    protected static function isShipmentConfirmationPrint(WmsPickingTask $record): bool
    {
        return (int) ($record->wave?->print_count ?? 0) === 0;
    }

    protected static function specificSlipPaperConfirmationSchema(WmsPickingTask $record, bool $includeWhenEmpty = false): array
    {
        $groups = app(SpecificSlipPrintTargetService::class)->collectForRecord($record);

        return static::specificSlipPaperConfirmationSchemaFromGroups($groups, $includeWhenEmpty);
    }

    protected static function specificSlipPaperConfirmationSchemaForRecords(iterable $records, bool $includeWhenEmpty = false): array
    {
        $groups = static::specificSlipGroupsForRecords($records);

        return static::specificSlipPaperConfirmationSchemaFromGroups($groups, $includeWhenEmpty);
    }

    protected static function specificSlipPaperConfirmationSchemaFromGroups(SupportCollection $groups, bool $includeWhenEmpty = false): array
    {
        if ($groups->isEmpty() && ! $includeWhenEmpty) {
            return [];
        }

        if ($groups->isEmpty()) {
            $components = [
                Placeholder::make('specific_slip_empty')
                    ->hiddenLabel()
                    ->content(new \Illuminate\Support\HtmlString(
                        '<div class="text-sm text-gray-600 dark:text-gray-400">印刷対象の専用伝票はありません。</div>'
                    )),
            ];
        } else {
            $components = $groups
                ->values()
                ->flatMap(fn (array $group, int $groupIndex) => collect($group['slips'] ?? [])
                    ->values()
                    ->map(fn ($slip, int $slipIndex) => static::specificSlipCardComponent($group, (array) $slip, $groupIndex, $slipIndex)))
                ->values()
                ->all();
        }

        return [
            Section::make()
                ->schema($components)
                ->compact(),
        ];
    }

    protected static function specificSlipCardComponent(array $group, array $slip, int $groupIndex, int $slipIndex): Grid
    {
        $target = static::specificSlipTargetForSlip($group, $slip);
        $target = app(SpecificSlipPrintQueueService::class)
            ->annotateGroupsWithQueueStatus(collect([$target]))
            ->first() ?? $target;

        return Grid::make([
            'default' => 1,
            'md' => 12,
        ])
            ->schema([
                ViewField::make('specific_slip_group_'.$groupIndex.'_slip_'.$slipIndex)
                    ->hiddenLabel()
                    ->dehydrated(false)
                    ->view('filament.components.specific-slip-print-slip-row')
                    ->viewData([
                        'group' => $group,
                        'slip' => $slip,
                        'target' => $target,
                        'index' => $slipIndex,
                    ])
                    ->columnSpan([
                        'default' => 'full',
                        'md' => 10,
                    ]),
                SchemaActions::make([
                    static::specificSlipRowPrintAction($target, (($groupIndex + 1) * 1000) + $slipIndex),
                ])
                    ->hiddenLabel()
                    ->alignEnd()
                    ->extraAttributes(['class' => 'specific-slip-print-slip-action'])
                    ->columnSpan([
                        'default' => 'full',
                        'md' => 2,
                    ]),
                ViewField::make('specific_slip_group_'.$groupIndex.'_slip_'.$slipIndex.'_info')
                    ->hiddenLabel()
                    ->dehydrated(false)
                    ->view('filament.components.specific-slip-print-slip-info')
                    ->viewData([
                        'slip' => $slip,
                    ])
                    ->columnSpan([
                        'default' => 'full',
                        'md' => 'full',
                    ]),
                ViewField::make('specific_slip_group_'.$groupIndex.'_slip_'.$slipIndex.'_items')
                    ->hiddenLabel()
                    ->dehydrated(false)
                    ->view('filament.components.specific-slip-print-slip-items')
                    ->viewData([
                        'items' => collect($slip['items'] ?? [])->values()->all(),
                    ])
                    ->columnSpan([
                        'default' => 'full',
                        'md' => 'full',
                    ]),
            ])
            ->extraAttributes([
                'class' => 'specific-slip-print-slip-card rounded-md border border-slate-200 bg-white p-2 dark:border-slate-700 dark:bg-slate-950',
            ])
            ->columnSpan([
                'default' => 'full',
                'md' => 'full',
            ]);
    }

    protected static function specificSlipRowPrintAction(array $group, int $index): Action
    {
        $canPrint = (bool) ($group['can_print'] ?? false);
        $isQueued = in_array($group['queue_status'] ?? null, [
            SpecificSlipPrintRequestQueue::STATUS_PENDING,
            SpecificSlipPrintRequestQueue::STATUS_PROCESSING,
        ], true);
        $actionName = 'specific_slip_row_print_'.substr(sha1(json_encode([
            $group['confirmation_key'] ?? static::specificSlipConfirmationKey($group),
            $group['print_order'] ?? $index,
            $group['source_record_id'] ?? null,
        ], JSON_THROW_ON_ERROR)), 0, 16);

        return Action::make($actionName)
            ->label('印刷')
            ->icon('heroicon-o-printer')
            ->color('primary')
            ->size(Size::Small)
            ->button()
            ->disabled(! $canPrint || $isQueued)
            ->tooltip(match (true) {
                ! $canPrint => (string) ($group['disabled_reason'] ?? '印刷設定を確認してください'),
                $isQueued => '専用伝票キューは作成済みです。',
                default => null,
            })
            ->action(function () use ($group): void {
                $result = app(SpecificSlipPrintQueueService::class)
                    ->createForGroups(collect([$group]), true);

                static::sendSpecificSlipPrintNotification($result);
            });
    }

    protected static function specificSlipActionBadge(WmsPickingTask $record): string|int|null
    {
        $groups = app(SpecificSlipPrintTargetService::class)->collectForRecord($record);
        if ($groups->isEmpty()) {
            return null;
        }

        $count = app(SpecificSlipPrintQueueService::class)
            ->countIncompleteTargets(static::specificSlipPrintTargetsFromGroups($groups));

        return $count > 0 ? $count : '完了';
    }

    protected static function specificSlipActionBadgeColor(WmsPickingTask $record): string
    {
        $badge = static::specificSlipActionBadge($record);

        return $badge === '完了' ? 'success' : 'warning';
    }

    protected static function sendSpecificSlipPrintNotification(array $result): void
    {
        $lines = static::specificSlipNotificationLines($result);
        if (empty($lines)) {
            $lines[] = '印刷対象の専用伝票はありません。';
        }

        $created = (int) ($result['created_count'] ?? 0);
        $alreadyQueued = (int) ($result['already_queued_count'] ?? 0);
        $type = $created > 0 ? 'success' : ($alreadyQueued > 0 ? 'warning' : 'danger');

        Notification::make()
            ->title('専用伝票印刷')
            ->body(implode("\n", $lines))
            ->{$type}()
            ->send();
    }

    protected static function queueSpecificSlipsForRecord(WmsPickingTask $record, array|bool $paperConfirmations = []): array
    {
        $groups = app(SpecificSlipPrintTargetService::class)->collectForRecord($record);

        return app(SpecificSlipPrintQueueService::class)
            ->createForGroups(static::specificSlipPrintTargetsFromGroups($groups), $paperConfirmations);
    }

    protected static function queueSpecificSlipsForRecords(iterable $records, array|bool $paperConfirmations = []): array
    {
        $groups = static::specificSlipGroupsForRecords($records);

        return app(SpecificSlipPrintQueueService::class)
            ->createForGroups(static::specificSlipPrintTargetsFromGroups($groups), $paperConfirmations);
    }

    protected static function specificSlipPrintTargetsFromGroups(SupportCollection $groups): SupportCollection
    {
        return $groups
            ->flatMap(function (array $group) {
                $slips = collect($group['slips'] ?? [])->values();

                if ($slips->isEmpty()) {
                    return [$group];
                }

                return $slips->map(fn ($slip) => static::specificSlipTargetForSlip($group, (array) $slip));
            })
            ->values();
    }

    protected static function specificSlipTargetForSlip(array $group, array $slip): array
    {
        $earningId = (int) ($slip['earning_id'] ?? 0);
        $target = $group;
        $target['earning_ids'] = $earningId > 0 ? [$earningId] : [];
        $target['earning_count'] = $earningId > 0 ? 1 : 0;
        $target['slips'] = [$slip];
        $target['confirmation_key'] = static::specificSlipConfirmationKeyForSlip($group, $earningId);

        unset($target['queue_id'], $target['queue_status'], $target['queue_processed_at']);

        return $target;
    }

    protected static function specificSlipConfirmationKeyForSlip(array $group, int $earningId): string
    {
        return implode('_', [
            $group['client_id'] ?? '',
            $group['warehouse_id'] ?? '',
            $group['slip_type_id'] ?? '',
            substr(sha1((string) ($group['printer_key'] ?? '')), 0, 10),
            $earningId,
        ]);
    }

    protected static function specificSlipGroupsForRecords(iterable $records): SupportCollection
    {
        $service = app(SpecificSlipPrintTargetService::class);
        $groups = collect();
        $printOrder = 0;

        foreach ($records as $record) {
            if ($record instanceof WmsPickingTask) {
                $recordGroups = $service->collectForRecord($record)
                    ->map(function (array $group) use ($record, &$printOrder) {
                        $printOrder++;
                        $group['print_order'] = $printOrder;
                        $group['source_record_id'] = $record->id;
                        $group['source_label'] = static::specificSlipSourceLabel($record);
                        $group['confirmation_key'] = static::specificSlipConfirmationKey($group).'_'.$record->id.'_'.$printOrder;

                        return $group;
                    });

                $groups = $groups->concat($recordGroups);
            }
        }

        return $groups->values();
    }

    protected static function specificSlipPaperConfirmationsFromData(array $data): array|bool
    {
        if (array_key_exists('specific_slip_confirmations', $data)) {
            return (array) $data['specific_slip_confirmations'];
        }

        if (array_key_exists('specific_slip_paper_confirmed', $data)) {
            return (bool) $data['specific_slip_paper_confirmed'];
        }

        return [];
    }

    protected static function specificSlipConfirmationKey(array $group): string
    {
        if (filled($group['confirmation_key'] ?? null)) {
            return (string) $group['confirmation_key'];
        }

        return implode('_', [
            $group['client_id'] ?? '',
            $group['warehouse_id'] ?? '',
            $group['slip_type_id'] ?? '',
            substr(sha1((string) ($group['printer_key'] ?? '')), 0, 10),
        ]);
    }

    protected static function specificSlipSourceLabel(WmsPickingTask $record): string
    {
        $courseName = $record->deliveryCourse?->name;
        $courseLabel = filled($courseName)
            ? $courseName
            : '配送コースID: '.$record->delivery_course_id;

        return 'WMS ID: '.$record->id.' / '.$courseLabel;
    }

    protected static function specificSlipNotificationLines(array $result): array
    {
        $lines = [];

        if (($result['created_count'] ?? 0) > 0) {
            $lines[] = '専用伝票キューを'.((int) $result['created_count']).'件作成しました。';
        }

        if (($result['already_queued_count'] ?? 0) > 0) {
            $lines[] = '専用伝票キューは'.((int) $result['already_queued_count']).'件作成済みです。';
        }

        if (($result['skipped_count'] ?? 0) > 0) {
            $messages = collect($result['messages'] ?? [])
                ->take(5)
                ->implode(' / ');
            $lines[] = '専用伝票を'.((int) $result['skipped_count']).'件スキップしました。'.$messages;
        }

        return $lines;
    }

    protected static function additionalShortagesSection(WmsPickingTask $record): Section
    {
        return Section::make('追加欠品')
            ->description('既存の欠品連携済みレコードは変更せず、欠品確定済みの行だけを追加します。')
            ->schema([
                Repeater::make('additional_shortages')
                    ->hiddenLabel()
                    ->default([])
                    ->addActionLabel('追加欠品を追加')
                    ->columns(4)
                    ->schema([
                        Select::make('picking_item_result_id')
                            ->label('対象明細')
                            ->options(fn () => static::additionalShortageItemOptions($record))
                            ->searchable()
                            ->required(),

                        TextInput::make('shortage_qty')
                            ->label('追加欠品数')
                            ->numeric()
                            ->minValue(1)
                            ->required(),

                        Select::make('reason_code')
                            ->label('理由')
                            ->options([
                                WmsShortage::REASON_NO_STOCK => '在庫不足',
                                WmsShortage::REASON_DAMAGED => '破損',
                                WmsShortage::REASON_MISSING_LOC => '所在不明',
                                WmsShortage::REASON_OTHER => 'その他',
                            ])
                            ->default(WmsShortage::REASON_NO_STOCK)
                            ->required(),

                        Textarea::make('note')
                            ->label('備考')
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ]),
            ])
            ->compact()
            ->collapsible();
    }

    protected static function additionalShortageItemOptions(WmsPickingTask $record): array
    {
        return static::additionalShortagePickResults($record)
            ->mapWithKeys(function (WmsPickingItemResult $pickResult) {
                $itemCode = $pickResult->item?->code ?? $pickResult->item_id;
                $itemName = $pickResult->item?->name ?? '-';
                $orderedQtyTypeValue = $pickResult->ordered_qty_type ?: 'PIECE';
                $pickedQtyTypeValue = $pickResult->picked_qty_type ?: $orderedQtyTypeValue;
                $orderedQtyType = QuantityType::tryFrom($orderedQtyTypeValue)?->name() ?? $orderedQtyTypeValue;
                $pickedQtyType = QuantityType::tryFrom($pickedQtyTypeValue)?->name() ?? $pickedQtyTypeValue;
                $shippableQty = static::shippableQuantityForAdditionalShortage($pickResult);
                $earningNo = $pickResult->earning?->slip_no
                    ?? $pickResult->earning?->code
                    ?? $pickResult->earning_id
                    ?? '-';

                return [
                    $pickResult->id => "[{$earningNo}] [{$itemCode}]{$itemName} / 発注{$pickResult->ordered_qty}{$orderedQtyType} / 出荷{$shippableQty}{$pickedQtyType}",
                ];
            })
            ->toArray();
    }

    protected static function additionalShortagePickResults(WmsPickingTask $record): Collection
    {
        $taskIds = static::shipmentSlipTaskQuery($record)->pluck('id');

        if ($taskIds->isEmpty()) {
            return new Collection;
        }

        return WmsPickingItemResult::query()
            ->with(['item', 'earning', 'pickingTask'])
            ->whereIn('picking_task_id', $taskIds)
            ->where(function (Builder $query) {
                $query->where('source_type', WmsPickingItemResult::SOURCE_TYPE_EARNING)
                    ->orWhereNull('source_type');
            })
            ->whereNotNull('trade_id')
            ->whereNotNull('trade_item_id')
            ->whereNotNull('earning_id')
            ->where(function (Builder $query) {
                $query->where('picked_qty', '>', 0)
                    ->orWhere('planned_qty', '>', 0)
                    ->orWhere('ordered_qty', '>', 0);
            })
            ->orderBy('earning_id')
            ->orderBy('item_id')
            ->get();
    }

    protected static function shipmentSlipTaskQuery(WmsPickingTask $record): Builder
    {
        return WmsPickingTask::query()
            ->where('shipment_date', $record->shipment_date)
            ->where('delivery_course_id', $record->delivery_course_id)
            ->when(
                $record->wave_id,
                fn (Builder $query) => $query->where('wave_id', $record->wave_id),
                fn (Builder $query) => $query->whereNull('wave_id'),
            );
    }

    protected static function normalizeAdditionalShortageRows(array $rows): array
    {
        return collect($rows)
            ->filter(fn (array $row) => filled($row['picking_item_result_id'] ?? null) || filled($row['shortage_qty'] ?? null))
            ->map(fn (array $row) => [
                'picking_item_result_id' => (int) ($row['picking_item_result_id'] ?? 0),
                'shortage_qty' => (int) ($row['shortage_qty'] ?? 0),
                'reason_code' => $row['reason_code'] ?? WmsShortage::REASON_NO_STOCK,
                'note' => trim((string) ($row['note'] ?? '')),
            ])
            ->values()
            ->all();
    }

    protected static function validateAdditionalShortageRows(WmsPickingTask $record, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $pickResults = static::additionalShortagePickResults($record)->keyBy('id');
        $seenPickResultIds = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 1;
            $pickResult = $pickResults->get($row['picking_item_result_id']);

            if (! $pickResult) {
                throw new \RuntimeException("追加欠品{$rowNumber}行目の対象明細が、この配送コースの出荷済み明細ではありません。");
            }

            if (isset($seenPickResultIds[$pickResult->id])) {
                throw new \RuntimeException("追加欠品{$rowNumber}行目の対象明細が重複しています。同じ明細の追加欠品は1行にまとめてください。");
            }
            $seenPickResultIds[$pickResult->id] = true;

            if ($row['shortage_qty'] < 1) {
                throw new \RuntimeException("追加欠品{$rowNumber}行目の欠品数は1以上で入力してください。");
            }

            if ($row['shortage_qty'] > static::shippableQuantityForAdditionalShortage($pickResult)) {
                throw new \RuntimeException("追加欠品{$rowNumber}行目の欠品数が出荷数を超えています。");
            }
        }
    }

    protected static function shippableQuantityForAdditionalShortage(WmsPickingItemResult $pickResult): int
    {
        $pickedQty = (int) ($pickResult->picked_qty ?? 0);
        if ($pickedQty > 0) {
            return $pickedQty;
        }

        $plannedQty = (int) ($pickResult->planned_qty ?? 0);
        if ($plannedQty > 0) {
            return $plannedQty;
        }

        return (int) ($pickResult->ordered_qty ?? 0);
    }

    protected static function createAdditionalConfirmedShortages(WmsPickingTask $record, array $rows, ?int $userId): int
    {
        if ($rows === []) {
            return 0;
        }

        $pickResults = static::additionalShortagePickResults($record)->keyBy('id');
        $createdCount = 0;

        DB::connection('sakemaru')->transaction(function () use ($rows, $pickResults, $userId, &$createdCount) {
            foreach ($rows as $row) {
                /** @var WmsPickingItemResult $pickResult */
                $pickResult = $pickResults->get($row['picking_item_result_id']);
                $qtyType = $pickResult->ordered_qty_type ?: $pickResult->planned_qty_type ?: WmsShortage::QTY_TYPE_PIECE;
                $notePrefix = "緊急追加欠品: 元ピッキング明細ID {$pickResult->id}";
                $note = $row['note'] !== '' ? "{$notePrefix} / {$row['note']}" : $notePrefix;

                WmsShortage::create([
                    'wave_id' => $pickResult->pickingTask?->wave_id,
                    'shipment_date' => $pickResult->pickingTask?->shipment_date,
                    'warehouse_id' => $pickResult->pickingTask?->warehouse_id,
                    'location_id' => $pickResult->location_id,
                    'item_id' => $pickResult->item_id,
                    'trade_id' => $pickResult->trade_id,
                    'earning_id' => $pickResult->earning_id,
                    'delivery_course_id' => $pickResult->pickingTask?->delivery_course_id,
                    'trade_item_id' => $pickResult->trade_item_id,
                    'order_qty' => (int) $pickResult->ordered_qty,
                    'planned_qty' => (int) $pickResult->planned_qty,
                    'picked_qty' => max(0, static::shippableQuantityForAdditionalShortage($pickResult) - $row['shortage_qty']),
                    'shortage_qty' => $row['shortage_qty'],
                    'allocation_shortage_qty' => 0,
                    'picking_shortage_qty' => $row['shortage_qty'],
                    'qty_type_at_order' => $qtyType,
                    'case_size_snap' => $pickResult->item?->capacity_case ?? 1,
                    'is_confirmed' => true,
                    'confirmed_by' => $userId,
                    'confirmed_user_id' => $userId,
                    'confirmed_at' => now(),
                    'is_synced' => false,
                    'source_pick_result_id' => $pickResult->id,
                    'status' => WmsShortage::STATUS_SHORTAGE,
                    'reason_code' => $row['reason_code'],
                    'note' => mb_substr($note, 0, 255),
                ]);

                $createdCount++;
            }
        });

        return $createdCount;
    }

    protected static function createQuantityQueuesForAdditionalShortages(WmsPickingTask $record): int
    {
        $pickResultIds = static::additionalShortagePickResults($record)->pluck('id');

        if ($pickResultIds->isEmpty()) {
            return 0;
        }

        $shortages = WmsShortage::query()
            ->whereIn('source_pick_result_id', $pickResultIds)
            ->where('is_confirmed', true)
            ->where('is_synced', false)
            ->where('note', 'like', '緊急追加欠品:%')
            ->get();

        if ($shortages->isEmpty()) {
            return 0;
        }

        $queueService = app(QuantityUpdateQueueService::class);
        $createdCount = 0;

        foreach ($shortages as $shortage) {
            $queue = $queueService->createQueueForAllocationSync($shortage, "additional-shortage-{$shortage->id}");
            if ($queue) {
                $createdCount++;
            }
        }

        return $createdCount;
    }

    /**
     * グループ化されたタスクを取得する
     * ListWmsShipmentSlipsから呼び出される
     */
    public static function loadGroupedTasks(SupportCollection $records): void
    {
        if ($records->isEmpty()) {
            return;
        }

        // 全レコードの配送コース・Wave ID・出荷日の組み合わせを取得
        $groupKeys = $records->map(function ($record) {
            return [
                'delivery_course_id' => $record->delivery_course_id,
                'wave_id' => $record->wave_id,
                'shipment_date' => $record->shipment_date,
            ];
        })->unique(function ($item) {
            return $item['delivery_course_id'].'-'.($item['wave_id'] ?? 'null').'-'.$item['shipment_date'];
        });

        // 該当する全タスクを取得
        $allTasks = WmsPickingTask::where(function ($query) use ($groupKeys) {
            foreach ($groupKeys as $key) {
                $query->orWhere(function ($q) use ($key) {
                    $q->where('delivery_course_id', $key['delivery_course_id'])
                        ->where('shipment_date', $key['shipment_date']);
                    if ($key['wave_id'] !== null) {
                        $q->where('wave_id', $key['wave_id']);
                    } else {
                        $q->whereNull('wave_id');
                    }
                });
            }
        })
            ->with(['floor', 'pickingArea'])
            ->get();

        // グループ化してレコードに割り当て
        $groupedTasks = $allTasks->groupBy(function ($task) {
            return $task->delivery_course_id.'-'.($task->wave_id ?? 'null').'-'.$task->shipment_date;
        });

        // 全タスクIDからピッキング明細に紐づく未同期欠品を一括取得
        $allTaskIds = $allTasks->pluck('id');
        $unsyncedByTask = collect();
        if ($allTaskIds->isNotEmpty()) {
            $unsyncedByTask = WmsShortage::query()
                ->join('wms_picking_item_results', 'wms_shortages.source_pick_result_id', '=', 'wms_picking_item_results.id')
                ->whereIn('wms_picking_item_results.picking_task_id', $allTaskIds)
                ->where('wms_shortages.shortage_qty', '>', 0)
                ->where('wms_shortages.is_synced', false)
                ->select('wms_picking_item_results.picking_task_id')
                ->distinct()
                ->pluck('picking_task_id')
                ->flip();
        }

        // 専用伝票判定: タスク→ピッキング明細→売上→得意先→得意先詳細(最新)→伝票種別
        $dedicatedByTask = collect();
        if ($allTaskIds->isNotEmpty()) {
            $systemDate = ClientSetting::systemDateYMD();
            $currentDetails = DB::connection('sakemaru')->table('buyer_details')
                ->selectRaw('buyer_id, slip_type_id, ROW_NUMBER() OVER (PARTITION BY buyer_id ORDER BY start_date DESC) AS rn')
                ->where('start_date', '<=', $systemDate);

            $dedicatedByTask = DB::connection('sakemaru')->table('wms_picking_item_results as pir')
                ->join('earnings as e', 'pir.earning_id', '=', 'e.id')
                ->joinSub($currentDetails, 'bd', function ($join) {
                    $join->on('e.buyer_id', '=', 'bd.buyer_id');
                })
                ->join('slip_types as st', 'bd.slip_type_id', '=', 'st.id')
                ->whereIn('pir.picking_task_id', $allTaskIds)
                ->where('bd.rn', 1)
                ->where('st.category', 2)
                ->select('pir.picking_task_id')
                ->distinct()
                ->pluck('picking_task_id')
                ->flip();
        }

        foreach ($records as $record) {
            $key = $record->delivery_course_id.'-'.($record->wave_id ?? 'null').'-'.$record->shipment_date;
            $tasks = $groupedTasks->get($key, collect());
            $record->grouped_tasks = $tasks;

            $record->is_stock_synced = $tasks->isNotEmpty()
                && ! $tasks->contains(fn ($task) => $unsyncedByTask->has($task->id));

            $record->has_dedicated_slip = $tasks->contains(fn ($task) => $dedicatedByTask->has($task->id));
        }
    }

    protected static function printTargetExistsQuery($query)
    {
        return $query
            ->select(DB::raw(1))
            ->from('wms_picking_item_results as target_pir')
            ->join('wms_picking_tasks as target_task', 'target_pir.picking_task_id', '=', 'target_task.id')
            ->leftJoin('earnings as target_e', 'target_pir.earning_id', '=', 'target_e.id')
            ->leftJoin('trades as target_et', 'target_e.trade_id', '=', 'target_et.id')
            ->leftJoin('stock_transfers as target_st', 'target_pir.stock_transfer_id', '=', 'target_st.id')
            ->leftJoin('trades as target_stt', 'target_st.trade_id', '=', 'target_stt.id')
            ->leftJoin('trade_items as target_ti', 'target_pir.trade_item_id', '=', 'target_ti.id')
            ->whereColumn('target_task.delivery_course_id', 'wms_picking_tasks.delivery_course_id')
            ->whereColumn('target_task.shipment_date', 'wms_picking_tasks.shipment_date')
            ->where(function ($query) {
                $query->whereColumn('target_task.wave_id', 'wms_picking_tasks.wave_id')
                    ->orWhere(function ($query) {
                        $query->whereNull('target_task.wave_id')
                            ->whereNull('wms_picking_tasks.wave_id');
                    });
            })
            ->where('target_pir.ordered_qty', '>', 0)
            ->where('target_ti.is_active', true)
            ->where(function ($query) {
                $query->where(function ($query) {
                    $query->whereNotNull('target_pir.earning_id')
                        ->where('target_e.is_active', true)
                        ->where('target_et.is_active', true);
                })
                    ->orWhere(function ($query) {
                        $query->whereNotNull('target_pir.stock_transfer_id')
                            ->where('target_st.is_active', true)
                            ->where('target_stt.is_active', true);
                    });
            });
    }

    /**
     * 配送コースのプリンター設定があるかチェック
     */
    protected static function checkPrinterSetting(int $warehouseId, int $deliveryCourseId): bool
    {
        return DB::connection('sakemaru')
            ->table('client_printer_course_settings')
            ->where('warehouse_id', $warehouseId)
            ->where('delivery_course_id', $deliveryCourseId)
            ->where('is_active', true)
            ->whereNotNull('printer_driver_id')
            ->exists();
    }

    protected static function sortRecordsForBulkPrint(Collection $records, $livewire): Collection
    {
        $sortColumn = $livewire->getTableSortColumn();
        $sortDirection = $livewire->getTableSortDirection();

        if ($sortColumn === null) {
            $sortColumn = 'wave.created_at';
            $sortDirection = 'desc';
        }

        $isDesc = $sortDirection === 'desc';

        $callback = match ($sortColumn) {
            'id' => fn ($r) => $r->id,
            'delivery_course_code' => fn ($r) => $r->delivery_course_code ?? '',
            'deliveryCourse.name' => fn ($r) => $r->deliveryCourse?->name ?? '',
            'shipment_date' => fn ($r) => $r->shipment_date ?? '',
            'wave.created_at' => fn ($r) => $r->wave?->created_at?->timestamp ?? 0,
            default => fn ($r) => $r->wave?->created_at?->timestamp ?? 0,
        };

        return $records->sortBy($callback, SORT_REGULAR, $isDesc)->values();
    }

    /**
     * 印刷不可理由のHTMLを生成
     */
    protected static function buildPrintabilityErrorHtml(array $printability): string
    {
        $html = '<div class="space-y-4">';

        // エラーメッセージ
        $html .= '<div class="text-danger-600 dark:text-danger-400 font-medium">';
        $html .= e($printability['error_message']);
        $html .= '</div>';

        // ピッキング未完了アイテム
        if (! empty($printability['incomplete_items'])) {
            $html .= '<div class="mt-4">';
            $html .= '<div class="font-medium text-sm text-gray-700 dark:text-gray-300 mb-2">ピッキング未完了アイテム:</div>';
            $html .= '<div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3 max-h-40 overflow-y-auto">';
            $html .= '<table class="w-full text-sm">';
            $html .= '<thead><tr class="text-left text-gray-500 dark:text-gray-400">';
            $html .= '<th class="pb-2">商品コード</th><th class="pb-2">商品名</th><th class="pb-2">ステータス</th>';
            $html .= '</tr></thead><tbody>';

            foreach ($printability['incomplete_items'] as $item) {
                $statusLabel = match ($item['status']) {
                    'PENDING' => '未着手',
                    'PICKING' => 'ピッキング中',
                    default => $item['status'],
                };
                $html .= '<tr class="border-t border-gray-200 dark:border-gray-700">';
                $html .= '<td class="py-1">'.e($item['item_code']).'</td>';
                $html .= '<td class="py-1">'.e(mb_substr($item['item_name'], 0, 20)).'</td>';
                $html .= '<td class="py-1"><span class="text-warning-600">'.e($statusLabel).'</span></td>';
                $html .= '</tr>';
            }

            $html .= '</tbody></table>';
            $html .= '</div></div>';
        }

        // 在庫同期未完了の欠品
        if (! empty($printability['unsynced_shortages'])) {
            $html .= '<div class="mt-4">';
            $html .= '<div class="font-medium text-sm text-gray-700 dark:text-gray-300 mb-2">在庫同期未完了の欠品:</div>';
            $html .= '<div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3 max-h-40 overflow-y-auto">';
            $html .= '<table class="w-full text-sm">';
            $html .= '<thead><tr class="text-left text-gray-500 dark:text-gray-400">';
            $html .= '<th class="pb-2">商品コード</th><th class="pb-2">商品名</th><th class="pb-2">欠品数</th><th class="pb-2">承認</th>';
            $html .= '</tr></thead><tbody>';

            foreach ($printability['unsynced_shortages'] as $shortage) {
                $confirmedLabel = $shortage['is_confirmed'] ? '済' : '未';
                $confirmedClass = $shortage['is_confirmed'] ? 'text-success-600' : 'text-danger-600';
                $html .= '<tr class="border-t border-gray-200 dark:border-gray-700">';
                $html .= '<td class="py-1">'.e($shortage['item_code']).'</td>';
                $html .= '<td class="py-1">'.e(mb_substr($shortage['item_name'], 0, 20)).'</td>';
                $html .= '<td class="py-1">'.e($shortage['shortage_qty']).'</td>';
                $html .= '<td class="py-1"><span class="'.$confirmedClass.'">'.e($confirmedLabel).'</span></td>';
                $html .= '</tr>';
            }

            $html .= '</tbody></table>';
            $html .= '</div></div>';
        }

        $html .= '<div class="mt-4 text-sm text-gray-600 dark:text-gray-400">';
        $html .= 'ピッキングや欠品対応が完了していない状態でも、現状のまま印刷します。本当に実行しますか？';
        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }
}
