<?php

namespace App\Filament\Resources\WmsOrderConfirmed\Tables;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\AutoOrder\LotStatus;
use App\Enums\AutoOrder\OrderChannel;
use App\Enums\AutoOrder\OrderDataFileChannel;
use App\Enums\PaginationOptions;
use App\Enums\QuantityType;
use App\Filament\Concerns\HasExportAction;
use App\Filament\Concerns\HasModifierDisplay;
use App\Filament\Concerns\HasOptimizedFilters;
use App\Filament\Resources\WmsOrderConfirmationWaiting\Tables\WmsOrderConfirmationWaitingTable;
use App\Models\WmsOrderCandidate;
use App\Models\WmsOrderIncomingSchedule;
use App\Services\AutoOrder\OrderCancellationService;
use App\Services\AutoOrder\OrderDataFileService;
use App\Services\AutoOrder\OrderTransmissionService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\View;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WmsOrderConfirmedTable
{
    use HasExportAction;
    use HasModifierDisplay;
    use HasOptimizedFilters;

    protected static function getFilterModelTable(): string
    {
        return (new WmsOrderCandidate)->getTable();
    }

    /**
     * @param  bool  $forJx  JX発注データ作成ページ用。標準フィルタ（JX未生成/FAX未生成/確定日〜当日/確定者なし）を初期値に設定する。
     */
    public static function configure(Table $table, bool $forJx = false): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->extraAttributes(['class' => 'order-confirmed-table sticky-actions'])
            ->columns([
                TextColumn::make('batch_code')
                    ->label('実行CD')
                    ->sortable()
                    ->searchable()
                    ->width('120px'),

                TextColumn::make('executed_at')
                    ->label('実行時刻')
                    ->state(function ($record) {
                        try {
                            return \Carbon\Carbon::createFromFormat('YmdHis', substr($record->batch_code, 0, 14))
                                ->format('m月d日 H時i分');
                        } catch (\Exception $e) {
                            return '-';
                        }
                    })
                    ->width('110px'),

                TextColumn::make('modified_at')
                    ->label('確定日時')
                    ->dateTime('m/d H:i')
                    ->sortable()
                    ->width('90px'),

                TextColumn::make('status')
                    ->label('状態')
                    ->badge()
                    ->formatStateUsing(fn (CandidateStatus $state): string => $state->label())
                    ->color(fn (CandidateStatus $state): string => $state->color())
                    ->sortable()
                    ->width('80px'),

                TextColumn::make('jx_generation_status')
                    ->label('JX生成')
                    ->state(fn (WmsOrderCandidate $record): string => $record->wms_order_jx_document_id
                        ? ($record->jxDocument?->status?->getLabel() ?? '生成済み')
                        : '未生成')
                    ->badge()
                    ->color(fn (WmsOrderCandidate $record): string => $record->wms_order_jx_document_id ? 'success' : 'warning')
                    ->width('80px'),

                TextColumn::make('order_data_file_generated')
                    ->label('FAX/メール')
                    ->state(fn (WmsOrderCandidate $record): string => (bool) $record->order_data_file_generated ? '生成済み' : '未生成')
                    ->badge()
                    ->color(fn (WmsOrderCandidate $record): string => (bool) $record->order_data_file_generated ? 'success' : 'warning')
                    ->tooltip('FAX / MAIL / CSV 用の発注データファイル生成状況')
                    ->width('90px'),

                TextColumn::make('warehouse.code')
                    ->label('倉庫CD')
                    ->searchable()
                    ->alignCenter()
                    ->width('50px'),

                TextColumn::make('warehouse.name')
                    ->label('倉庫名')
                    ->searchable()
                    ->width('120px'),

                TextColumn::make('item.code')
                    ->label('商品CD')
                    ->searchable()
                    ->sortable()
                    ->alignCenter()
                    ->width('70px'),

                TextColumn::make('item.name')
                    ->label('商品名')
                    ->searchable()
                    ->sortable()
                    ->grow(),

                TextColumn::make('item.packaging')
                    ->label('規格')
                    ->alignCenter()
                    ->toggleable()
                    ->width('100px'),

                TextColumn::make('item.capacity_case')
                    ->label('入数')
                    ->numeric()
                    ->alignCenter()
                    ->toggleable()
                    ->width('50px'),

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

                TextColumn::make('setting_safety_stock')
                    ->label('発注点')
                    ->state(fn (WmsOrderCandidate $record) => (int) ($record->ic_safety_stock ?? $record->safety_stock ?? 0))
                    ->numeric()
                    ->alignEnd()
                    ->toggleable()
                    ->width('60px'),

                TextColumn::make('setting_max_stock')
                    ->label('最大発注点')
                    ->state(fn (WmsOrderCandidate $record) => (int) ($record->ic_max_stock ?? 0))
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('75px'),

                TextColumn::make('setting_min_stock')
                    ->label('最低在庫数')
                    ->state(fn (WmsOrderCandidate $record) => (int) ($record->ic_min_stock ?? 0))
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('75px'),

                TextColumn::make('setting_auto_order_quantity')
                    ->label('自動発注数')
                    ->state(fn (WmsOrderCandidate $record) => (int) ($record->ic_auto_order_quantity ?? 0))
                    ->numeric()
                    ->alignEnd()
                    ->toggleable()
                    ->width('75px'),

                TextColumn::make('setting_is_auto_order')
                    ->label('自動発注')
                    ->state(fn (WmsOrderCandidate $record) => ((bool) ($record->ic_is_auto_order ?? false)) ? 'ON' : 'OFF')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'ON' ? 'success' : 'gray')
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('70px'),

                TextColumn::make('order_quantity')
                    ->label('発注数')
                    ->numeric()
                    ->alignEnd()
                    ->width('70px'),

                TextColumn::make('quantity_type')
                    ->label('発注単位')
                    ->state(fn (WmsOrderCandidate $record): string => $record->quantity_type?->name() ?? '-')
                    ->alignCenter()
                    ->badge()
                    ->color(fn (string $state): string => $state === 'ケース' ? 'info' : 'gray')
                    ->width('75px'),

                TextColumn::make('total_pieces')
                    ->label('総バラ数')
                    ->state(function (WmsOrderCandidate $record): int {
                        $capacityCase = max(1, (int) ($record->item?->capacity_case ?? 1));
                        $orderQuantity = (int) ($record->order_quantity ?? 0);

                        return $record->quantity_type === QuantityType::CASE
                            ? $orderQuantity * $capacityCase
                            : $orderQuantity;
                    })
                    ->numeric()
                    ->alignEnd()
                    ->weight('bold')
                    ->width('75px')
                    ->summarize(
                        Summarizer::make()
                            ->label('')
                            ->using(function (\Illuminate\Database\Query\Builder $query) {
                                return (int) $query->sum(
                                    \Illuminate\Support\Facades\DB::raw('CASE WHEN quantity_type = \'CASE\' THEN COALESCE(order_quantity, 0) * COALESCE((SELECT capacity_case FROM items WHERE items.id = wms_order_candidates.item_id), 1) ELSE COALESCE(order_quantity, 0) END')
                                );
                            })
                    ),

                TextColumn::make('expected_arrival_date')
                    ->label('入荷予定')
                    ->date('m/d')
                    ->sortable()
                    ->alignCenter()
                    ->width('70px'),

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

                TextColumn::make('is_manually_modified')
                    ->label('手動修正')
                    ->state(fn ($record) => $record->is_manually_modified ? '修正済' : '-')
                    ->toggleable(isToggledHiddenByDefault: true),

                static::modifierColumn(),

                TextColumn::make('created_at')
                    ->label('作成日時')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('ステータス')
                    ->options([
                        CandidateStatus::CONFIRMED->value => CandidateStatus::CONFIRMED->label(),
                        CandidateStatus::EXECUTED->value => CandidateStatus::EXECUTED->label(),
                    ])
                    ->default(CandidateStatus::CONFIRMED->value),

                TernaryFilter::make('zero_order_quantity')
                    ->label('発注数0')
                    ->placeholder('すべて')
                    ->trueLabel('0のみ')
                    ->falseLabel('0以外')
                    ->queries(
                        true: fn (Builder $query) => $query->where('order_quantity', '<=', 0),
                        false: fn (Builder $query) => $query->where('order_quantity', '>', 0),
                    ),

                static::warehouseFilter(),

                static::contractorFilter(),

                static::confirmedByFilter($forJx),

                static::confirmedDateFilter($forJx),

                static::jxFileGenerationFilter($forJx),

                static::faxFileGenerationFilter($forJx),

                Filter::make('executed_at_range')
                    ->label('実行時刻')
                    ->schema([
                        Grid::make(2)->schema([
                            DateTimePicker::make('executed_from')
                                ->label('開始'),
                            DateTimePicker::make('executed_until')
                                ->label('終了'),
                        ]),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['executed_from'] ?? null, fn (Builder $q, $date) => $q
                                ->where('batch_code', '>=', \Carbon\Carbon::parse($date)->format('YmdHis')))
                            ->when($data['executed_until'] ?? null, fn (Builder $q, $date) => $q
                                ->where('batch_code', '<=', \Carbon\Carbon::parse($date)->endOfMinute()->format('YmdHis').'999'));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['executed_from'] ?? null) {
                            $indicators[] = '実行時刻開始: '.\Carbon\Carbon::parse($data['executed_from'])->format('Y/m/d H:i');
                        }
                        if ($data['executed_until'] ?? null) {
                            $indicators[] = '実行時刻終了: '.\Carbon\Carbon::parse($data['executed_until'])->format('Y/m/d H:i');
                        }

                        return $indicators;
                    }),

                Filter::make('expected_arrival_date_range')
                    ->label('入荷予定')
                    ->schema([
                        Grid::make(2)->schema([
                            DatePicker::make('arrival_from')
                                ->label('開始日'),
                            DatePicker::make('arrival_until')
                                ->label('終了日'),
                        ]),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['arrival_from'] ?? null, fn (Builder $q, $date) => $q->where('expected_arrival_date', '>=', $date))
                            ->when($data['arrival_until'] ?? null, fn (Builder $q, $date) => $q->where('expected_arrival_date', '<=', $date));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['arrival_from'] ?? null) {
                            $indicators[] = '入荷予定開始: '.\Carbon\Carbon::parse($data['arrival_from'])->format('Y/m/d');
                        }
                        if ($data['arrival_until'] ?? null) {
                            $indicators[] = '入荷予定終了: '.\Carbon\Carbon::parse($data['arrival_until'])->format('Y/m/d');
                        }

                        return $indicators;
                    }),

                static::modifierFilter(),
            ])
            ->recordActionsColumnLabel('操作')
            ->recordActions([
                Action::make('viewDetail')
                    ->label('詳細')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading('発注確定詳細')
                    ->modalWidth('4xl')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('閉じる')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->infolist(function (?WmsOrderCandidate $record): array {
                        if (! $record) {
                            return [];
                        }

                        return [
                            Grid::make(2)
                                ->schema([
                                    View::make('filament.components.order-confirmed-detail-left')
                                        ->viewData(static::confirmedDetailLeftViewData($record))
                                        ->columnSpan(1),

                                    View::make('filament.components.order-confirmed-detail-right')
                                        ->viewData([
                                            'suggestedQuantity' => $record->suggested_quantity ?? 0,
                                            'orderQuantity' => $record->order_quantity ?? 0,
                                            'status' => $record->status->label(),
                                            'statusColor' => $record->status->color(),
                                            'transmittedAt' => $record->transmitted_at
                                                ? $record->transmitted_at->format('Y/m/d H:i')
                                                : null,
                                        ])
                                        ->columnSpan(1),
                                ]),
                        ];
                    }),

                Action::make('cancelConfirmation')
                    ->label('確定取消')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->visible(fn (?WmsOrderCandidate $record): bool => in_array($record?->status, [CandidateStatus::CONFIRMED, CandidateStatus::EXECUTED]))
                    ->modalHeading('発注確定を取消')
                    ->modalDescription(fn ($record) => "[{$record->item?->code}]{$record->item?->name} の発注確定を取消し、承認済みに戻します。関連する入庫予定も削除されます。")
                    ->extraModalWindowAttributes(['class' => 'incoming-cancel-modal'])
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('確定を取消')->color('danger'))
                    ->modalCancelActionLabel('取消せず閉じる')
                    ->schema([
                        Textarea::make('reason')
                            ->label('取消理由')
                            ->required(),
                    ])
                    ->action(function ($record, array $data) {
                        $service = app(OrderCancellationService::class);

                        try {
                            $deletedSchedules = $service->cancelConfirmation(
                                $record,
                                auth()->id(),
                                $data['reason']
                            );

                            Notification::make()
                                ->title('発注確定を取消しました')
                                ->body("入庫予定 {$deletedSchedules}件を削除しました。ステータスを承認済みに戻しました。")
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
                    BulkAction::make('bulkUpdateArrivalDate')
                        ->label('入荷予定日変更')
                        ->icon('heroicon-o-pencil-square')
                        ->color('warning')
                        ->modalHeading('入荷予定日を一括変更')
                        ->modalDescription(fn (Collection $records) => "選択した {$records->count()} 件のうち、未入荷の確定済み発注のみ変更します。生成済みファイルがある場合は、変更後に再生成してください。")
                        ->modalFooterActionsAlignment(Alignment::End)
                        ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('変更を適用')->color('danger'))
                        ->modalCancelActionLabel('変更せず閉じる')
                        ->schema([
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

                            $arrivalDate = $data['expected_arrival_date'] instanceof \Carbon\Carbon
                                ? $data['expected_arrival_date']->format('Y-m-d')
                                : \Carbon\Carbon::parse($data['expected_arrival_date'])->format('Y-m-d');

                            $updatedCandidates = 0;
                            $updatedSchedules = 0;
                            $skipped = 0;
                            $errors = [];

                            foreach ($records as $candidate) {
                                if (! $candidate instanceof WmsOrderCandidate) {
                                    $skipped++;

                                    continue;
                                }

                                if (
                                    $candidate->status !== CandidateStatus::CONFIRMED
                                    || static::hasReceivedIncomingSchedule($candidate)
                                ) {
                                    $skipped++;

                                    continue;
                                }

                                try {
                                    DB::connection('sakemaru')->transaction(function () use ($candidate, $arrivalDate, &$updatedCandidates, &$updatedSchedules) {
                                        $expirationDate = static::calculateExpirationDate($candidate, $arrivalDate);
                                        $jxDocumentId = $candidate->wms_order_jx_document_id;

                                        $candidate->update([
                                            'expected_arrival_date' => $arrivalDate,
                                            'wms_order_jx_document_id' => null,
                                            'is_manually_modified' => true,
                                            'modified_by' => auth()->id(),
                                            'modified_at' => now(),
                                            'updated_at' => now(),
                                        ]);

                                        $scheduleUpdate = [
                                            'expected_arrival_date' => $arrivalDate,
                                            'updated_at' => now(),
                                        ];

                                        if ($expirationDate !== null) {
                                            $scheduleUpdate['expiration_date'] = $expirationDate;
                                        }

                                        $scheduleCount = WmsOrderIncomingSchedule::where('order_candidate_id', $candidate->id)
                                            ->where('status', IncomingScheduleStatus::PENDING->value)
                                            ->update($scheduleUpdate);

                                        $updatedCandidates++;
                                        $updatedSchedules += $scheduleCount;

                                        if ($jxDocumentId !== null) {
                                            static::cancelPendingJxDocument((int) $jxDocumentId);
                                        }
                                    });
                                } catch (\Throwable $e) {
                                    $errors[] = "[{$candidate->item?->code}] {$e->getMessage()}";
                                }
                            }

                            if ($updatedCandidates > 0) {
                                Notification::make()
                                    ->title("{$updatedCandidates}件の入荷予定日を更新しました")
                                    ->body("関連する入荷予定 {$updatedSchedules}件も更新しました。".($skipped > 0 ? " スキップ {$skipped}件。" : ''))
                                    ->success()
                                    ->send();
                            } elseif ($skipped > 0 && empty($errors)) {
                                Notification::make()
                                    ->title('変更対象がありません')
                                    ->body('未入荷の確定済み発注を選択してください。')
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

                    BulkAction::make('bulkGenerateOrderDataFiles')
                        ->label('FAX / MAIL / CSV データ生成')
                        ->icon('heroicon-o-document-plus')
                        ->color('primary')
                        ->requiresConfirmation()
                        ->modalHeading('発注データを生成')
                        ->modalDescription(fn (Collection $records) => "選択した {$records->count()} 件から、FAX / MAIL / CSV 用の発注データを生成します。確定済み以外の候補は除外されます。同じ候補で生成済みの未使用ファイル（未ダウンロード・未送信）は新しいファイルに置き換えられます。1000件を超える場合は条件を絞ってください。")
                        ->schema([
                            Textarea::make('communication_notes')
                                ->label('連絡事項')
                                ->rows(4)
                                ->maxLength(500)
                                ->helperText('生成するFAX PDFの通信欄に表示します。空欄の場合は空の通信欄になります。'),
                        ])
                        ->modalSubmitActionLabel('データ生成')
                        ->modalCancelActionLabel('生成せず閉じる')
                        ->action(function (Collection $records, array $data) {
                            if ($records->count() > 1000) {
                                Notification::make()
                                    ->title('選択件数が多すぎます')
                                    ->body('1000件以内になるように、倉庫・仕入先・実行CDなどで絞り込んでから再実行してください。')
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $candidateIds = $records->pluck('id')->map(fn ($id) => (int) $id)->all();
                            $result = app(OrderDataFileService::class)
                                ->generateCsvFilesForCandidates(
                                    $candidateIds,
                                    communicationNotes: $data['communication_notes'] ?? null,
                                );

                            $fileCount = $result['total_files'] ?? count($result['files'] ?? []);
                            $totalOrders = collect($result['files'] ?? [])->sum('order_count');

                            if ($fileCount > 0) {
                                Notification::make()
                                    ->title('発注データを生成しました')
                                    ->body("生成ファイル {$fileCount}件 / 発注 {$totalOrders}件")
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('生成対象がありません')
                                    ->body($result['message'] ?? '選択した候補に確定済みの発注候補がありません。')
                                    ->warning()
                                    ->send();
                            }

                            if (! empty($result['errors'])) {
                                $errorMessages = collect($result['errors'])
                                    ->map(fn ($e) => is_array($e) ? ($e['error'] ?? json_encode($e)) : $e)
                                    ->take(5)
                                    ->all();
                                Notification::make()
                                    ->title(count($result['errors']).'件のエラーが発生しました')
                                    ->body(implode("\n", $errorMessages))
                                    ->danger()
                                    ->send();
                            }

                            $faxErrors = collect($result['files'] ?? [])
                                ->filter(fn (array $file): bool => filled($file['fax_error'] ?? null))
                                ->map(fn (array $file): string => ($file['contractor_name'] ?? '発注先不明').': '.$file['fax_error'])
                                ->values()
                                ->all();

                            if (! empty($faxErrors)) {
                                Notification::make()
                                    ->title(count($faxErrors).'件のFAX生成エラーが発生しました')
                                    ->body(implode("\n", array_slice($faxErrors, 0, 5)))
                                    ->danger()
                                    ->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('bulkGenerateJxFiles')
                        ->label('JXファイル生成')
                        ->icon('heroicon-o-document-arrow-up')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('JXファイル生成（送信しない）')
                        ->modalDescription(fn (Collection $records) => "選択した {$records->count()} 件のうち、JX未生成の確定済み候補だけを対象にJXファイルを生成します。送信はされません。生成後「発注データファイル」画面から送信してください。")
                        ->modalSubmitActionLabel('JXファイル生成')
                        ->modalCancelActionLabel('生成せず閉じる')
                        ->action(function (Collection $records) {
                            $candidateIds = $records->pluck('id')->map(fn ($id) => (int) $id)->all();
                            $result = app(OrderTransmissionService::class)
                                ->generateJxFilesForCandidateIds($candidateIds);

                            $fileCount = count($result['files'] ?? []);
                            $totalOrders = $result['total_orders'] ?? 0;
                            $selectedCount = $result['selected_count'] ?? count($candidateIds);
                            $eligibleCount = $result['eligible_count'] ?? $totalOrders;
                            $jxTargetCount = $result['jx_target_count'] ?? $eligibleCount;
                            $excludedAlreadyGenerated = $result['excluded_already_generated'] ?? 0;
                            $excludedNotConfirmed = $result['excluded_not_confirmed'] ?? 0;
                            $excludedMissing = $result['excluded_missing'] ?? 0;
                            $excludedFaxChannel = $result['excluded_fax_channel'] ?? 0;
                            $excludedNotJxTarget = $result['excluded_not_jx_target'] ?? 0;
                            $excludedMissingOrderingCode = $result['excluded_missing_ordering_code'] ?? 0;
                            $skippedCount = $result['skipped_count'] ?? max(0, $eligibleCount - $totalOrders);

                            if ($fileCount > 0) {
                                $documentIds = collect($result['files'])->pluck('document_id')->filter()->implode(', ');
                                $bodyLines = [
                                    "選択: {$selectedCount}件 / JX対象: {$jxTargetCount}件 / 生成対象: {$eligibleCount}件 / 発注数: {$totalOrders}件",
                                    "伝票ID: {$documentIds}",
                                    '「発注データファイル」画面の送信前タブから送信してください。',
                                ];

                                if ($excludedAlreadyGenerated > 0 || $excludedNotConfirmed > 0 || $excludedMissing > 0 || $excludedFaxChannel > 0 || $excludedNotJxTarget > 0 || $excludedMissingOrderingCode > 0) {
                                    $bodyLines[] = "除外: 生成済み {$excludedAlreadyGenerated}件 / 確定済み以外 {$excludedNotConfirmed}件 / FAX発注 {$excludedFaxChannel}件 / JX対象外 {$excludedNotJxTarget}件 / JX発注CD未設定 {$excludedMissingOrderingCode}件 / 不明 {$excludedMissing}件";
                                }

                                if ($skippedCount > 0) {
                                    $bodyLines[] = "未出力: {$skippedCount}件（JX発注コード未設定など）";
                                }

                                Notification::make()
                                    ->title("JXファイルを生成しました（{$fileCount}件）")
                                    ->body(implode("\n", $bodyLines))
                                    ->success()
                                    ->send();
                            } else {
                                $bodyLines = [
                                    $result['errors'][0] ?? '確定済みの発注候補がありません',
                                    "選択: {$selectedCount}件 / JX対象: {$jxTargetCount}件 / 生成対象: {$eligibleCount}件",
                                ];

                                if ($excludedAlreadyGenerated > 0 || $excludedNotConfirmed > 0 || $excludedMissing > 0 || $excludedFaxChannel > 0 || $excludedNotJxTarget > 0 || $excludedMissingOrderingCode > 0) {
                                    $bodyLines[] = "除外: 生成済み {$excludedAlreadyGenerated}件 / 確定済み以外 {$excludedNotConfirmed}件 / FAX発注 {$excludedFaxChannel}件 / JX対象外 {$excludedNotJxTarget}件 / JX発注CD未設定 {$excludedMissingOrderingCode}件 / 不明 {$excludedMissing}件";
                                }

                                if ($skippedCount > 0) {
                                    $bodyLines[] = "未出力: {$skippedCount}件（JX発注CD未設定など）";
                                }

                                Notification::make()
                                    ->title('生成対象がありません')
                                    ->body(implode("\n", $bodyLines))
                                    ->warning()
                                    ->send();
                            }

                            if (! empty($result['errors'])) {
                                Notification::make()
                                    ->title(count($result['errors']).'件のエラー')
                                    ->body(implode("\n", array_slice($result['errors'], 0, 5)))
                                    ->danger()
                                    ->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('bulkCancelConfirmation')
                        ->label('確定を一括取消')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('danger')
                        ->modalHeading('発注確定を一括取消')
                        ->modalDescription(fn (Collection $records) => "選択した {$records->count()} 件の発注確定を取消し、承認済みに戻します。関連する入庫予定も削除されます。")
                        ->extraModalWindowAttributes(['class' => 'incoming-cancel-modal'])
                        ->modalFooterActionsAlignment(Alignment::End)
                        ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('一括取消')->color('danger'))
                        ->modalCancelActionLabel('取消せず閉じる')
                        ->schema([
                            Textarea::make('reason')
                                ->label('取消理由')
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $service = app(OrderCancellationService::class);
                            $confirmed = $records->filter(fn ($r) => in_array($r->status, [CandidateStatus::CONFIRMED, CandidateStatus::EXECUTED]));

                            if ($confirmed->isEmpty()) {
                                Notification::make()
                                    ->title('取消可能な候補がありません')
                                    ->body('選択した候補は取消できません。')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $successCount = 0;
                            $totalDeletedSchedules = 0;
                            $errors = [];

                            foreach ($confirmed as $candidate) {
                                try {
                                    $deleted = $service->cancelConfirmation(
                                        $candidate,
                                        auth()->id(),
                                        $data['reason']
                                    );
                                    $successCount++;
                                    $totalDeletedSchedules += $deleted;
                                } catch (\Exception $e) {
                                    $errors[] = "[{$candidate->item?->code}] {$e->getMessage()}";
                                }
                            }

                            if ($successCount > 0) {
                                Notification::make()
                                    ->title("{$successCount}件の発注確定を取消しました")
                                    ->body("入庫予定 {$totalDeletedSchedules}件を削除しました。")
                                    ->warning()
                                    ->send();
                            }

                            if (! empty($errors)) {
                                Notification::make()
                                    ->title(count($errors).'件でエラーが発生')
                                    ->body(implode("\n", array_slice($errors, 0, 5)))
                                    ->danger()
                                    ->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort((new WmsOrderCandidate)->getTable().'.modified_at', 'desc');
    }

    private static function hasReceivedIncomingSchedule(WmsOrderCandidate $candidate): bool
    {
        return WmsOrderIncomingSchedule::query()
            ->where('order_candidate_id', $candidate->id)
            ->where('status', '!=', IncomingScheduleStatus::PENDING->value)
            ->exists();
    }

    private static function cancelPendingJxDocument(int $documentId): void
    {
        app(OrderTransmissionService::class)->cancelPendingJxDocumentAndRestoreCandidates($documentId);
    }

    private static function calculateExpirationDate(WmsOrderCandidate $candidate, string $arrivalDate): ?string
    {
        $days = (int) ($candidate->item?->default_expiration_days ?? 0);

        if ($days <= 0) {
            return null;
        }

        return \Carbon\Carbon::parse($arrivalDate)->addDays($days)->format('Y-m-d');
    }

    private static function confirmedDetailLeftViewData(WmsOrderCandidate $record): array
    {
        $item = $record->item;
        $orderSettings = WmsOrderConfirmationWaitingTable::resolveItemContractorOrderSettings($record);

        return [
            'batchCodeFormatted' => \Carbon\Carbon::createFromFormat('YmdHis', substr($record->batch_code, 0, 14))->format('Y/m/d H:i'),
            'warehouseName' => $record->warehouse ? "[{$record->warehouse->code}]{$record->warehouse->name}" : '-',
            'contractorName' => $record->contractor ? "[{$record->contractor->code}]{$record->contractor->name}" : '-',
            'itemCode' => $item?->code ?? '-',
            'itemName' => $item?->name ?? '-',
            'expectedArrivalDate' => $record->expected_arrival_date
                ? \Carbon\Carbon::parse($record->expected_arrival_date)->format('Y/m/d')
                : '-',
            'safetyStock' => $orderSettings['safety_stock'] ?? 0,
            'maxStock' => $orderSettings['max_stock'] ?? 0,
            'minStock' => $orderSettings['min_stock'] ?? 0,
            'autoOrderQuantity' => $orderSettings['auto_order_quantity'] ?? 0,
            'isAutoOrder' => $orderSettings['is_auto_order'] ?? false,
        ];
    }

    private static function confirmedByFilter(bool $forJx = false): SelectFilter
    {
        return SelectFilter::make('confirmed_by')
            ->label('確定者')
            ->searchable()
            ->default(fn () => $forJx ? null : self::defaultConfirmedByFilterValue())
            ->options(fn () => self::buildConfirmedByOptions())
            ->getSearchResultsUsing(fn (string $search) => self::buildConfirmedByOptions($search))
            ->query(function (Builder $query, array $data) {
                if (blank($data['value'])) {
                    return;
                }

                $query->where((new WmsOrderCandidate)->getTable().'.modified_by', $data['value']);
            });
    }

    private static function defaultConfirmedByFilterValue(): ?int
    {
        return auth()->id();
    }

    private static function jxFileGenerationFilter(bool $forJx = false): SelectFilter
    {
        return SelectFilter::make('jx_file_generation_status')
            ->label('JX生成')
            ->options([
                'not_generated' => '未生成',
                'generated' => '生成済み',
                'all' => 'すべて',
            ])
            ->default($forJx ? 'not_generated' : null)
            ->query(function (Builder $query, array $data): Builder {
                $value = $data['value'] ?? null;
                $table = (new WmsOrderCandidate)->getTable();

                return match ($value) {
                    'not_generated' => $query->whereNull("{$table}.wms_order_jx_document_id"),
                    'generated' => $query->whereNotNull("{$table}.wms_order_jx_document_id"),
                    default => $query,
                };
            });
    }

    private static function faxFileGenerationFilter(bool $forJx = false): SelectFilter
    {
        return SelectFilter::make('fax_file_generation_status')
            ->label('FAX生成')
            ->options([
                'not_generated' => '未生成',
                'generated' => '生成済み',
                'all' => 'すべて',
            ])
            ->default($forJx ? 'not_generated' : null)
            ->query(function (Builder $query, array $data): Builder {
                $value = $data['value'] ?? null;
                $table = (new WmsOrderCandidate)->getTable();

                $dataFileExists = fn ($q) => $q
                    ->select(\Illuminate\Support\Facades\DB::raw(1))
                    ->from('wms_order_data_files')
                    ->whereColumn('wms_order_data_files.batch_code', "{$table}.batch_code")
                    ->whereColumn('wms_order_data_files.warehouse_id', "{$table}.warehouse_id")
                    ->whereColumn('wms_order_data_files.contractor_id', "{$table}.contractor_id")
                    ->whereColumn('wms_order_data_files.expected_arrival_date', "{$table}.expected_arrival_date")
                    ->where(function ($query) use ($table): void {
                        $query
                            ->whereNull("{$table}.order_channel")
                            ->orWhere("{$table}.order_channel", OrderChannel::FAX->value);
                    })
                    ->where(function ($query): void {
                        $query
                            ->whereNull('wms_order_data_files.order_channel')
                            ->orWhere('wms_order_data_files.order_channel', OrderDataFileChannel::FAX->value);
                    })
                    ->where(function ($query) use ($table) {
                        $query
                            ->whereRaw("JSON_CONTAINS(wms_order_data_files.candidate_ids, JSON_ARRAY({$table}.id))")
                            ->orWhereNull('wms_order_data_files.candidate_ids');
                    });

                return match ($value) {
                    'not_generated' => $query->whereNotExists($dataFileExists),
                    'generated' => $query->whereExists($dataFileExists),
                    default => $query,
                };
            });
    }

    private static function confirmedDateFilter(bool $forJx = false): Filter
    {
        return Filter::make('confirmed_date')
            ->label('確定日')
            ->schema([
                Grid::make(2)->schema([
                    DatePicker::make('confirmed_from')
                        ->label('開始日')
                        // JX発注作成は「確定日開始=前日」を初期値とする
                        ->default($forJx ? today()->subDay() : today()),
                    DatePicker::make('confirmed_until')
                        ->label('終了日')
                        ->default(today()),
                ]),
            ])
            ->query(function (Builder $query, array $data): Builder {
                if (blank($data['confirmed_from'] ?? null) && blank($data['confirmed_until'] ?? null)) {
                    return $query;
                }

                $table = (new WmsOrderCandidate)->getTable();

                return $query
                    ->when($data['confirmed_from'] ?? null, fn (Builder $q, $date) => $q->where("{$table}.modified_at", '>=', \Carbon\Carbon::parse($date)->startOfDay()))
                    ->when($data['confirmed_until'] ?? null, fn (Builder $q, $date) => $q->where("{$table}.modified_at", '<', \Carbon\Carbon::parse($date)->addDay()->startOfDay()));
            })
            ->indicateUsing(function (array $data): array {
                $indicators = [];
                if ($data['confirmed_from'] ?? null) {
                    $indicators[] = '確定日開始: '.\Carbon\Carbon::parse($data['confirmed_from'])->format('Y/m/d');
                }
                if ($data['confirmed_until'] ?? null) {
                    $indicators[] = '確定日終了: '.\Carbon\Carbon::parse($data['confirmed_until'])->format('Y/m/d');
                }

                return $indicators;
            });
    }

    private static function buildConfirmedByOptions(?string $search = null): array
    {
        $query = \App\Models\Sakemaru\User::query()
            ->whereIn('id', fn ($q) => $q
                ->select('modified_by')
                ->from((new WmsOrderCandidate)->getTable())
                ->whereIn('status', [
                    CandidateStatus::CONFIRMED->value,
                    CandidateStatus::EXECUTED->value,
                ])
                ->whereNotNull('modified_by')
                ->distinct());

        if ($search) {
            $search = mb_convert_kana($search, 'as');
            $query->where(fn ($q) => $q
                ->where('code', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%"));
        }

        $results = $query
            ->limit(50)
            ->get()
            ->mapWithKeys(fn ($u) => [$u->id => "[{$u->code}]{$u->name}"])
            ->toArray();

        $currentUser = auth()->user();
        if ($currentUser && (! $search || str_contains((string) $currentUser->code, $search) || str_contains($currentUser->name, $search))) {
            $results = [$currentUser->id => "[{$currentUser->code}]{$currentUser->name}"] + $results;
        }

        return $results;
    }
}
