<?php

namespace App\Filament\Resources\Waves\Tables;

use App\Enums\PaginationOptions;
use App\Filament\Concerns\HasExportAction;
use App\Models\QuantityUpdateQueue;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Warehouse;
use App\Models\Wave;
use App\Services\PickingList\PickingListPdfService;
use App\Services\PickingList\PickingListService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

class WavesTable
{
    use HasExportAction;

    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->columns([
                TextColumn::make('wave_no')
                    ->label('波動番号')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('waveGroup.group_no')
                    ->label('生成グループ')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('waveSetting.deliveryCourse.warehouse.code')
                    ->label('倉庫コード')
                    ->sortable(),

                TextColumn::make('waveSetting.deliveryCourse.warehouse.name')
                    ->label('倉庫名')
                    ->sortable(),

                TextColumn::make('waveSetting.deliveryCourse.code')
                    ->label('配送コースコード')
                    ->sortable(),

                TextColumn::make('waveSetting.deliveryCourse.name')
                    ->label('配送コース名')
                    ->sortable(),

                TextColumn::make('shipping_date')
                    ->label('出荷日')
                    ->date('Y年m月d日')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('出荷状況')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'PENDING' => '未出荷',
                        'PICKING' => 'ピッキング中',
                        'SHORTAGE' => '欠品あり',
                        'COMPLETED' => '出荷完了',
                        'CLOSED' => 'クローズ',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'PENDING' => 'gray',
                        'PICKING' => 'info',
                        'SHORTAGE' => 'warning',
                        'COMPLETED' => 'success',
                        'CLOSED' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('波動生成時刻')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label('更新日時')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('warehouse_id')
                    ->label('倉庫')
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
                    ->getOptionLabelUsing(fn ($value) => Warehouse::find($value)?->name)
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['value'],
                            fn (Builder $query, $warehouseId): Builder => $query->whereHas(
                                'waveSetting.deliveryCourse',
                                fn (Builder $q) => $q->where('warehouse_id', $warehouseId)
                            )
                        );
                    }),

                Filter::make('shipping_date')
                    ->label('出荷日')
                    ->form([
                        DatePicker::make('shipping_date')
                            ->label('出荷日')
                            ->default(ClientSetting::systemDateYMD()),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['shipping_date'], fn (Builder $q, $date) => $q->where('shipping_date', $date))
                    )
                    ->indicateUsing(function (array $data): ?string {
                        if (! $data['shipping_date']) {
                            return null;
                        }

                        return '出荷日: '.\Carbon\Carbon::parse($data['shipping_date'])->format('Y年m月d日');
                    }),

                SelectFilter::make('status')
                    ->label('出荷状況')
                    ->multiple()
                    ->options([
                        'PENDING' => '未出荷',
                        'PICKING' => 'ピッキング中',
                        'SHORTAGE' => '欠品あり',
                        'COMPLETED' => '出荷完了',
                        'CLOSED' => 'クローズ',
                    ]),
            ])
            ->recordActions([
                Action::make('downloadSavedPickingList')
                    ->label('保存リスト')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('success')
                    ->visible(fn (Wave $record): bool => ! empty($record->waveGroup?->picking_lists))
                    ->modalHeading(fn (Wave $record): string => "保存済みピッキングリスト: {$record->waveGroup?->group_no}")
                    ->modalWidth('2xl')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->modalSubmitAction(fn (Action $action) => $action->label('ダウンロード')->color('danger'))
                    ->modalCancelActionLabel('出力せず閉じる')
                    ->schema(fn (Wave $record): array => [
                        Select::make('list_type')
                            ->label('リスト種別')
                            ->options(static::savedPickingListOptions($record))
                            ->required()
                            ->default(array_key_first($record->waveGroup?->picking_lists ?? [])),
                    ])
                    ->action(fn (Wave $record, array $data) => static::downloadSavedPickingList($record, $data)),

                Action::make('cancelWave')
                    ->label('取消')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Wave $record): bool => $record->status !== 'CLOSED')
                    ->modalHeading(fn (Wave $record): string => "波動取消: {$record->wave_no}")
                    ->modalDescription('誤って生成した波動を取り消します。対象伝票はピッキング前に戻ります。')
                    ->modalWidth('2xl')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->modalSubmitAction(fn (Action $action) => $action->label('波動を取消')->color('danger'))
                    ->modalCancelActionLabel('取消せず閉じる')
                    ->modalContent(fn (Wave $record): HtmlString => static::cancelWaveModalContent($record))
                    ->action(fn (Wave $record) => static::cancelWave($record)),

                Action::make('forceCloseWave')
                    ->label('強制終了')
                    ->icon('heroicon-o-lock-closed')
                    ->color('warning')
                    ->visible(fn (Wave $record): bool => $record->status !== 'CLOSED')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Wave $record): string => "波動強制終了: {$record->wave_no}")
                    ->modalDescription('波動ステータスだけをクローズに変更します。ピッキングタスク、明細、伝票、外部連携キューは変更しません。')
                    ->modalWidth('2xl')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->modalSubmitAction(fn (Action $action) => $action->label('波動を強制終了')->color('danger'))
                    ->modalCancelActionLabel('終了せず閉じる')
                    ->action(fn (Wave $record) => static::forceCloseWave($record)),
            ], position: RecordActionsPosition::AfterColumns)
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('bulkForceCloseWaves')
                        ->label('波動強制終了')
                        ->icon('heroicon-o-lock-closed')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('選択波動の強制終了')
                        ->modalDescription('選択した波動のステータスだけをクローズに変更します。ピッキングタスク、明細、伝票、外部連携キューは変更しません。')
                        ->modalWidth('2xl')
                        ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                        ->modalFooterActionsAlignment(Alignment::End)
                        ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('選択波動を強制終了')->color('danger'))
                        ->modalCancelActionLabel('終了せず閉じる')
                        ->action(fn ($records) => static::forceCloseWaves($records)),

                    BulkAction::make('bulkCancelWaves')
                        ->label('波動取消')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->modalHeading('選択波動の取消')
                        ->modalDescription('誤って生成した波動をまとめて取り消します。対象伝票はピッキング前に戻ります。')
                        ->modalWidth('3xl')
                        ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                        ->modalFooterActionsAlignment(Alignment::End)
                        ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('選択波動を取消')->color('danger'))
                        ->modalCancelActionLabel('取消せず閉じる')
                        ->modalContent(fn ($records): HtmlString => static::bulkCancelWaveModalContent($records))
                        ->action(fn ($records) => static::cancelWaves($records)),

                    BulkAction::make('bulkPrintPrimaryList')
                        ->label('1次リスト一括出力')
                        ->icon('heroicon-o-document-text')
                        ->color('info')
                        ->modalHeading('1次リスト一括出力')
                        ->modalDescription('選択した波動の1次ピッキングリストを1つのPDFにまとめて出力します。')
                        ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                        ->modalFooterActionsAlignment(Alignment::End)
                        ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('1次リスト出力')->color('danger'))
                        ->modalCancelActionLabel('出力せず閉じる')
                        ->schema([
                            Toggle::make('separate_floors')
                                ->label('1/2Fを分離')
                                ->default(true),
                        ])
                        ->action(function ($records, array $data) {
                            try {
                                $service = new PickingListService;
                                $pdfService = new PickingListPdfService;
                                $waveIds = $records
                                    ->sortBy('wave_no')
                                    ->pluck('id')
                                    ->values()
                                    ->all();

                                $resultPages = collect($service->generatePrimaryCourseListPages($waveIds, $data['separate_floors'] ?? true))
                                    ->filter(fn ($result) => ! empty($result['items']))
                                    ->values()
                                    ->all();

                                if (empty($resultPages)) {
                                    Notification::make()->title('ピッキング明細がありません')->warning()->send();

                                    return;
                                }

                                $pdf = $pdfService->renderBatchPrimaryPdf($resultPages);
                                $dateStr = now()->format('YmdHis');

                                return response()->streamDownload(
                                    fn () => print ($pdf),
                                    "picking-list-1st-bulk-{$dateStr}.pdf",
                                    ['Content-Type' => 'application/pdf']
                                );
                            } catch (\Exception $e) {
                                Notification::make()->title('PDF生成に失敗しました')->body($e->getMessage())->danger()->send();
                            }
                        }),

                    BulkAction::make('bulkPrintPrimaryTotalList')
                        ->label('1次リスト(一括)出力')
                        ->icon('heroicon-o-calculator')
                        ->color('info')
                        ->modalHeading('1次リスト(一括)出力')
                        ->modalDescription('選択した波動をまたいで全商品の合計値を1つのPDFにまとめて出力します。')
                        ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                        ->modalFooterActionsAlignment(Alignment::End)
                        ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('1次リスト(一括)出力')->color('danger'))
                        ->modalCancelActionLabel('出力せず閉じる')
                        ->schema([
                            Toggle::make('separate_floors')
                                ->label('1/2Fを分離')
                                ->default(true),
                        ])
                        ->action(function ($records, array $data) {
                            try {
                                $service = new PickingListService;
                                $pdfService = new PickingListPdfService;
                                $waveIds = $records
                                    ->sortBy('wave_no')
                                    ->pluck('id')
                                    ->values()
                                    ->all();

                                $resultPages = collect($service->generatePrimaryTotalListPages($waveIds, $data['separate_floors'] ?? true))
                                    ->filter(fn ($result) => ! empty($result['items']))
                                    ->values()
                                    ->all();

                                if (empty($resultPages)) {
                                    Notification::make()->title('ピッキング明細がありません')->warning()->send();

                                    return;
                                }

                                $pdf = $pdfService->renderBatchPrimaryPdf($resultPages);
                                $dateStr = now()->format('YmdHis');

                                return response()->streamDownload(
                                    fn () => print ($pdf),
                                    "picking-list-1st-total-bulk-{$dateStr}.pdf",
                                    ['Content-Type' => 'application/pdf']
                                );
                            } catch (\Exception $e) {
                                Notification::make()->title('PDF生成に失敗しました')->body($e->getMessage())->danger()->send();
                            }
                        }),

                    BulkAction::make('bulkPrintShortageList')
                        ->label('欠品リスト一括出力')
                        ->icon('heroicon-o-exclamation-triangle')
                        ->color('danger')
                        ->modalHeading('欠品リスト一括出力')
                        ->modalDescription('選択した波動の欠品リストを1つのPDFにまとめて出力します。')
                        ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                        ->modalFooterActionsAlignment(Alignment::End)
                        ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('欠品リスト出力')->color('danger'))
                        ->modalCancelActionLabel('出力せず閉じる')
                        ->action(function ($records) {
                            try {
                                $service = new PickingListService;
                                $pdfService = new PickingListPdfService;
                                $waveIds = $records
                                    ->sortBy('wave_no')
                                    ->pluck('id')
                                    ->values()
                                    ->all();
                                $dataList = $service->generateShortageCourseLists($waveIds);

                                $pdf = $pdfService->renderBatchShortagePdf($dataList);
                                $dateStr = now()->format('YmdHis');

                                return response()->streamDownload(
                                    fn () => print ($pdf),
                                    "shortage-list-1st-bulk-{$dateStr}.pdf",
                                    ['Content-Type' => 'application/pdf']
                                );
                            } catch (\Exception $e) {
                                Notification::make()->title('PDF生成に失敗しました')->body($e->getMessage())->danger()->send();
                            }
                        }),
                ]),
                static::getExportAction(),
            ])
            ->defaultSort('id', 'desc');
    }

    protected static function cancelWaveModalContent(Wave $wave): HtmlString
    {
        $summary = static::getCancelWaveSummary($wave);
        $shippingDate = $wave->shipping_date?->format('Y-m-d') ?? '';
        $createdAt = $wave->created_at?->format('Y-m-d H:i:s') ?? '';
        $courseCode = e((string) ($wave->waveSetting?->deliveryCourse?->code ?? ''));
        $courseName = e((string) ($wave->waveSetting?->deliveryCourse?->name ?? ''));
        $warehouseName = e((string) ($wave->waveSetting?->deliveryCourse?->warehouse?->name ?? ''));

        $blockers = static::formatCancelBlockers(static::getCancelWaveBlockers($wave));

        $html = <<<HTML
<div class="space-y-4">
    <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">
        この波動だけを取消対象にします。外部連携キュー作成済み、出荷済み、取消済みの場合は実行できません。ピッキング実績・欠品作業は破棄されます。
    </div>
    <dl class="grid grid-cols-2 gap-3 text-sm">
        <div>
            <dt class="text-xs text-slate-500 dark:text-gray-400">波動番号</dt>
            <dd class="font-mono font-semibold text-slate-800 dark:text-gray-100">{$wave->wave_no}</dd>
        </div>
        <div>
            <dt class="text-xs text-slate-500 dark:text-gray-400">出荷日</dt>
            <dd class="font-semibold text-slate-800 dark:text-gray-100">{$shippingDate}</dd>
        </div>
        <div>
            <dt class="text-xs text-slate-500 dark:text-gray-400">倉庫</dt>
            <dd class="font-semibold text-slate-800 dark:text-gray-100">{$warehouseName}</dd>
        </div>
        <div>
            <dt class="text-xs text-slate-500 dark:text-gray-400">配送コース</dt>
            <dd class="font-semibold text-slate-800 dark:text-gray-100">[{$courseCode}] {$courseName}</dd>
        </div>
        <div>
            <dt class="text-xs text-slate-500 dark:text-gray-400">波動生成時刻</dt>
            <dd class="font-semibold text-slate-800 dark:text-gray-100">{$createdAt}</dd>
        </div>
        <div>
            <dt class="text-xs text-slate-500 dark:text-gray-400">現在ステータス</dt>
            <dd class="font-semibold text-slate-800 dark:text-gray-100">{$wave->status}</dd>
        </div>
    </dl>
    <div class="grid grid-cols-3 gap-2 text-sm">
        <div class="rounded-lg border border-slate-200 p-3 dark:border-gray-700">
            <div class="text-xs text-slate-500 dark:text-gray-400">タスク</div>
            <div class="text-lg font-bold text-slate-800 dark:text-gray-100">{$summary['task_count']}</div>
        </div>
        <div class="rounded-lg border border-slate-200 p-3 dark:border-gray-700">
            <div class="text-xs text-slate-500 dark:text-gray-400">売上伝票</div>
            <div class="text-lg font-bold text-slate-800 dark:text-gray-100">{$summary['earning_count']}</div>
        </div>
        <div class="rounded-lg border border-slate-200 p-3 dark:border-gray-700">
            <div class="text-xs text-slate-500 dark:text-gray-400">移動伝票</div>
            <div class="text-lg font-bold text-slate-800 dark:text-gray-100">{$summary['stock_transfer_count']}</div>
        </div>
    </div>
    {$blockers}
</div>
HTML;

        return new HtmlString($html);
    }

    public static function bulkCancelWaveModalContent($waves): HtmlString
    {
        $rows = $waves
            ->sortByDesc('id')
            ->take(12)
            ->map(function (Wave $wave): string {
                $blockers = static::getCancelWaveBlockers($wave);
                $shippingDate = $wave->shipping_date?->format('Y-m-d') ?? '';
                $courseCode = e((string) ($wave->waveSetting?->deliveryCourse?->code ?? ''));
                $courseName = e((string) ($wave->waveSetting?->deliveryCourse?->name ?? ''));
                $status = e((string) $wave->status);
                $cancelStatus = empty($blockers)
                    ? '<span class="text-green-700 dark:text-green-300">取消可</span>'
                    : '<span class="text-red-700 dark:text-red-300">取消不可</span>';

                return <<<HTML
<tr class="border-b border-slate-100 last:border-0 dark:border-gray-700">
    <td class="px-2 py-2 font-mono text-xs text-slate-700 dark:text-gray-200">{$wave->wave_no}</td>
    <td class="px-2 py-2 text-xs text-slate-700 dark:text-gray-200">{$shippingDate}</td>
    <td class="px-2 py-2 text-xs text-slate-700 dark:text-gray-200">[{$courseCode}] {$courseName}</td>
    <td class="px-2 py-2 text-xs font-semibold text-slate-700 dark:text-gray-200">{$status}</td>
    <td class="px-2 py-2 text-xs font-semibold">{$cancelStatus}</td>
</tr>
HTML;
            })
            ->implode('');

        $selectedCount = $waves->count();
        $hiddenCount = max(0, $selectedCount - 12);
        $hiddenNote = $hiddenCount > 0
            ? "<div class=\"pt-2 text-xs text-slate-500 dark:text-gray-400\">ほか {$hiddenCount} 件</div>"
            : '';
        $blockingRows = $waves
            ->sortByDesc('id')
            ->map(function (Wave $wave): ?string {
                $blockers = static::getCancelWaveBlockers($wave);
                if (empty($blockers)) {
                    return null;
                }

                $waveNo = e((string) $wave->wave_no);
                $reason = e(implode(' / ', $blockers));

                return "<li><span class=\"font-mono\">{$waveNo}</span>: {$reason}</li>";
            })
            ->filter()
            ->implode('');
        $blockingNotice = $blockingRows !== ''
            ? <<<HTML
    <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">
        <div class="font-semibold">取消できない波動が含まれています。この選択のままでは取消できません。</div>
        <ul class="mt-2 list-disc space-y-1 pl-5">{$blockingRows}</ul>
    </div>
HTML
            : '';

        $html = <<<HTML
<div class="space-y-4">
    <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">
        選択した {$selectedCount} 件の波動を取消対象にします。外部連携キュー作成済み、出荷済み、取消済みの波動が含まれる場合は実行できません。ピッキング実績・欠品作業は破棄されます。
    </div>
    {$blockingNotice}
    <div class="overflow-hidden rounded-lg border border-slate-200 dark:border-gray-700">
        <table class="w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs text-slate-500 dark:bg-gray-800 dark:text-gray-400">
                <tr>
                    <th class="px-2 py-2">波動番号</th>
                    <th class="px-2 py-2">出荷日</th>
                    <th class="px-2 py-2">配送コース</th>
                    <th class="px-2 py-2">状況</th>
                    <th class="px-2 py-2">取消可否</th>
                </tr>
            </thead>
            <tbody>{$rows}</tbody>
        </table>
    </div>
    {$hiddenNote}
</div>
HTML;

        return new HtmlString($html);
    }

    protected static function getCancelWaveSummary(Wave $wave): array
    {
        $taskIds = DB::connection('sakemaru')
            ->table('wms_picking_tasks')
            ->where('wave_id', $wave->id)
            ->pluck('id');

        if ($taskIds->isEmpty()) {
            return ['task_count' => 0, 'earning_count' => 0, 'stock_transfer_count' => 0];
        }

        $results = DB::connection('sakemaru')
            ->table('wms_picking_item_results')
            ->whereIn('picking_task_id', $taskIds);

        return [
            'task_count' => $taskIds->count(),
            'earning_count' => (clone $results)->whereNotNull('earning_id')->distinct()->count('earning_id'),
            'stock_transfer_count' => (clone $results)->whereNotNull('stock_transfer_id')->distinct()->count('stock_transfer_id'),
        ];
    }

    protected static function cancelWave(Wave $wave): void
    {
        try {
            DB::connection('sakemaru')->transaction(function () use ($wave) {
                $wave = Wave::query()->lockForUpdate()->findOrFail($wave->id);
                static::cancelWaveInTransaction($wave);
            });

            Notification::make()
                ->title('波動を取消しました')
                ->body("波動 {$wave->wave_no} をクローズし、対象伝票をピッキング前に戻しました。")
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('波動取消に失敗しました')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    protected static function cancelWaves($waves): void
    {
        try {
            $waveIds = $waves->pluck('id')->values()->all();
            $cancelledWaveNos = [];

            DB::connection('sakemaru')->transaction(function () use ($waveIds, &$cancelledWaveNos) {
                $lockedWaves = Wave::query()
                    ->whereIn('id', $waveIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($lockedWaves as $wave) {
                    static::cancelWaveInTransaction($wave);
                    $cancelledWaveNos[] = $wave->wave_no;
                }
            });

            Notification::make()
                ->title('選択波動を取消しました')
                ->body(count($cancelledWaveNos).'件の波動をクローズし、対象伝票をピッキング前に戻しました。')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('選択波動の取消に失敗しました')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    protected static function forceCloseWave(Wave $wave): void
    {
        try {
            DB::connection('sakemaru')->transaction(function () use ($wave) {
                $lockedWave = Wave::query()
                    ->whereKey($wave->id)
                    ->where('status', '!=', 'CLOSED')
                    ->lockForUpdate()
                    ->first();

                $lockedWave?->update([
                    'status' => 'CLOSED',
                    'updated_at' => now(),
                ]);
            });

            Notification::make()
                ->title('波動を強制終了しました')
                ->body("波動 {$wave->wave_no} をクローズしました。")
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('波動の強制終了に失敗しました')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    protected static function forceCloseWaves($waves): void
    {
        try {
            $waveIds = $waves->pluck('id')->values()->all();
            $closedCount = 0;

            DB::connection('sakemaru')->transaction(function () use ($waveIds, &$closedCount) {
                $lockedWaves = Wave::query()
                    ->whereIn('id', $waveIds)
                    ->where('status', '!=', 'CLOSED')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($lockedWaves as $wave) {
                    $wave->update([
                        'status' => 'CLOSED',
                        'updated_at' => now(),
                    ]);
                    $closedCount++;
                }
            });

            Notification::make()
                ->title('選択波動を強制終了しました')
                ->body($closedCount.'件の波動をクローズしました。')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('選択波動の強制終了に失敗しました')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public static function cancelWaveInTransaction(Wave $wave): void
    {
        $blockers = static::getCancelWaveBlockers($wave, true);
        if (! empty($blockers)) {
            throw new \RuntimeException("取消できない波動です。波動番号: {$wave->wave_no} / 理由: ".implode(' / ', $blockers));
        }

        $tasks = DB::connection('sakemaru')
            ->table('wms_picking_tasks')
            ->where('wave_id', $wave->id)
            ->lockForUpdate()
            ->get(['id', 'status']);

        $taskIds = $tasks->pluck('id')->all();
        $earningIds = [];
        $stockTransferIds = [];
        $reservations = DB::connection('sakemaru')
            ->table('wms_reservations')
            ->where('wave_id', $wave->id)
            ->lockForUpdate()
            ->get(['id', 'source_type', 'source_id']);

        $reservationIds = $reservations->pluck('id')->all();
        $earningIds = $reservations
            ->where('source_type', 'EARNING')
            ->pluck('source_id')
            ->filter()
            ->all();
        $stockTransferIds = $reservations
            ->where('source_type', 'STOCK_TRANSFER')
            ->pluck('source_id')
            ->filter()
            ->all();
        $shortageIds = DB::connection('sakemaru')
            ->table('wms_shortages')
            ->where('wave_id', $wave->id)
            ->pluck('id')
            ->all();

        if (! empty($taskIds)) {
            $earningIds = collect($earningIds)
                ->merge(DB::connection('sakemaru')
                    ->table('wms_picking_item_results')
                    ->whereIn('picking_task_id', $taskIds)
                    ->whereNotNull('earning_id')
                    ->distinct()
                    ->pluck('earning_id'))
                ->filter()
                ->unique()
                ->values()
                ->all();

            $stockTransferIds = collect($stockTransferIds)
                ->merge(DB::connection('sakemaru')
                    ->table('wms_picking_item_results')
                    ->whereIn('picking_task_id', $taskIds)
                    ->whereNotNull('stock_transfer_id')
                    ->distinct()
                    ->pluck('stock_transfer_id'))
                ->filter()
                ->unique()
                ->values()
                ->all();

            DB::connection('sakemaru')
                ->table('wms_picking_item_results')
                ->whereIn('picking_task_id', $taskIds)
                ->delete();
        }

        if (! empty($shortageIds)) {
            DB::connection('sakemaru')
                ->table('wms_shortage_allocations')
                ->whereIn('shortage_id', $shortageIds)
                ->delete();

            DB::connection('sakemaru')
                ->table('wms_shortages')
                ->whereIn('id', $shortageIds)
                ->delete();
        }

        if (! empty($reservationIds)) {
            DB::connection('sakemaru')
                ->table('wms_reservations')
                ->whereIn('id', $reservationIds)
                ->delete();
        }

        if (! empty($taskIds)) {
            DB::connection('sakemaru')
                ->table('wms_picking_tasks')
                ->whereIn('id', $taskIds)
                ->delete();
        }

        if (! empty($earningIds)) {
            DB::connection('sakemaru')
                ->table('earnings')
                ->whereIn('id', $earningIds)
                ->whereIn('picking_status', ['BEFORE_PICKING', 'PICKING_READY', 'PICKING', 'COMPLETED', 'SHORTAGE'])
                ->update([
                    'picking_status' => 'BEFORE',
                    'updated_at' => now(),
                ]);
        }

        if (! empty($stockTransferIds)) {
            DB::connection('sakemaru')
                ->table('stock_transfers')
                ->whereIn('id', $stockTransferIds)
                ->whereIn('picking_status', ['BEFORE_PICKING', 'PICKING_READY', 'PICKING', 'COMPLETED', 'SHORTAGE'])
                ->update([
                    'picking_status' => 'BEFORE',
                    'updated_at' => now(),
                ]);
        }

        $wave->update(['status' => 'CLOSED']);
    }

    protected static function getCancelWaveBlockers(Wave $wave, bool $lockRows = false): array
    {
        $blockers = [];

        if ($wave->status === 'CLOSED') {
            $blockers[] = '取消済み';
        }

        $taskQuery = DB::connection('sakemaru')
            ->table('wms_picking_tasks')
            ->where('wave_id', $wave->id);

        if ($lockRows) {
            $taskQuery->lockForUpdate();
        }

        $tasks = $taskQuery->get(['id', 'status']);
        $shippedTask = $tasks->first(fn ($task) => $task->status === 'SHIPPED');
        if ($shippedTask) {
            $blockers[] = "出荷済みタスクあり(ID: {$shippedTask->id})";
        }

        $queueBlockers = static::getExternalQueueBlockers($wave, $tasks->pluck('id')->all());

        return array_values(array_unique([...$blockers, ...$queueBlockers]));
    }

    protected static function getExternalQueueBlockers(Wave $wave, array $taskIds): array
    {
        $connection = DB::connection('sakemaru');
        $results = collect();

        if (! empty($taskIds)) {
            $results = $connection
                ->table('wms_picking_item_results')
                ->whereIn('picking_task_id', $taskIds)
                ->get(['earning_id', 'trade_id', 'trade_item_id', 'stock_transfer_id']);
        }

        $shortages = $connection
            ->table('wms_shortages')
            ->where('wave_id', $wave->id)
            ->get(['id', 'earning_id', 'trade_id', 'trade_item_id', 'source_pick_result_id']);

        $shortageIds = $shortages->pluck('id')->filter()->map(fn ($id) => (int) $id)->values()->all();
        $allocationIds = empty($shortageIds)
            ? []
            : $connection
                ->table('wms_shortage_allocations')
                ->whereIn('shortage_id', $shortageIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

        $earningIds = $results->pluck('earning_id')
            ->merge($shortages->pluck('earning_id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        $tradeIds = $results->pluck('trade_id')
            ->merge($shortages->pluck('trade_id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        $tradeItemIds = $results->pluck('trade_item_id')
            ->merge($shortages->pluck('trade_item_id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        $stockTransferIds = $results->pluck('stock_transfer_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        $sourcePickResultIds = $shortages->pluck('source_pick_result_id')
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values()
            ->all();

        $blockers = [];

        if (! empty($earningIds)) {
            $hasEarningDeliveryQueue = $connection
                ->table('earning_delivery_queue')
                ->where(function ($query) use ($earningIds) {
                    foreach ($earningIds as $earningId) {
                        $query->orWhereJsonContains('earning_ids', $earningId);
                    }
                })
                ->exists();

            if ($hasEarningDeliveryQueue) {
                $blockers[] = 'earning_delivery_queue作成済み';
            }
        }

        $stockTransferRequestIds = collect($earningIds)
            ->map(fn (int $earningId): string => "wh-mismatch-{$earningId}")
            ->merge(collect($allocationIds)->map(fn (int $allocationId): string => "proxy-shipment-{$allocationId}"))
            ->values()
            ->all();

        if (! empty($stockTransferIds) || ! empty($stockTransferRequestIds)) {
            $hasStockTransferQueue = $connection
                ->table('stock_transfer_queue')
                ->where(function ($query) use ($stockTransferIds, $stockTransferRequestIds) {
                    if (! empty($stockTransferIds)) {
                        $query->where(function ($stockTransferQuery) use ($stockTransferIds) {
                            $stockTransferQuery
                                ->whereIn('stock_transfer_id', $stockTransferIds)
                                ->where('action_type', '!=', 'CREATE');
                        });
                    }

                    if (! empty($stockTransferRequestIds)) {
                        $method = empty($stockTransferIds) ? 'whereIn' : 'orWhereIn';
                        $query->{$method}('request_id', $stockTransferRequestIds);
                    }
                })
                ->exists();

            if ($hasStockTransferQueue) {
                $blockers[] = 'stock_transfer_queue作成済み';
            }
        }

        $quantityUpdateRequestIds = $sourcePickResultIds;
        if (! empty($tradeIds) || ! empty($tradeItemIds) || ! empty($quantityUpdateRequestIds)) {
            $hasUnfinishedQuantityUpdateQueue = $connection
                ->table('quantity_update_queue')
                ->where(function ($query) use ($tradeIds, $tradeItemIds, $quantityUpdateRequestIds) {
                    if (! empty($tradeIds)) {
                        $query->whereIn('trade_id', $tradeIds);
                    }

                    if (! empty($tradeItemIds)) {
                        $method = empty($tradeIds) ? 'whereIn' : 'orWhereIn';
                        $query->{$method}('trade_item_id', $tradeItemIds);
                    }

                    if (! empty($quantityUpdateRequestIds)) {
                        $method = empty($tradeIds) && empty($tradeItemIds) ? 'whereIn' : 'orWhereIn';
                        $query->{$method}('request_id', $quantityUpdateRequestIds);
                    }
                })
                ->where(function ($query) {
                    $query->whereNull('status')
                        ->orWhere('status', '!=', QuantityUpdateQueue::STATUS_FINISHED)
                        ->orWhereNull('is_success')
                        ->orWhere('is_success', '!=', true);
                })
                ->exists();

            if ($hasUnfinishedQuantityUpdateQueue) {
                $blockers[] = 'quantity_update_queue未完了または失敗あり';
            }
        }

        return $blockers;
    }

    protected static function formatCancelBlockers(array $blockers): string
    {
        if (empty($blockers)) {
            return '';
        }

        $items = collect($blockers)
            ->map(fn (string $blocker): string => '<li>'.e($blocker).'</li>')
            ->implode('');

        return <<<HTML
    <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">
        <div class="font-semibold">この波動は取消できません。</div>
        <ul class="mt-2 list-disc space-y-1 pl-5">{$items}</ul>
    </div>
HTML;
    }

    protected static function savedPickingListOptions(Wave $record): array
    {
        $labels = [
            'primary' => '1次ピッキングリスト',
            'primary_total' => '1次ピッキングリスト(一括)',
            'shortage' => '欠品リスト',
            'secondary' => '2次ピッキングリスト',
            'secondary_v2' => '2次ピッキングリスト(V2)',
            'tertiary' => '3次ピッキングリスト',
        ];

        return collect($record->waveGroup?->picking_lists ?? [])
            ->keys()
            ->mapWithKeys(fn (string $type): array => [$type => $labels[$type] ?? $type])
            ->toArray();
    }

    protected static function downloadSavedPickingList(Wave $record, array $data)
    {
        $listType = $data['list_type'] ?? null;
        $entry = $record->waveGroup?->picking_lists[$listType] ?? null;

        if (! $entry || empty($entry['disk']) || empty($entry['path'])) {
            Notification::make()
                ->title('保存済みリストが見つかりません')
                ->warning()
                ->send();

            return null;
        }

        $disk = (string) $entry['disk'];
        $path = (string) $entry['path'];

        if (! Storage::disk($disk)->exists($path)) {
            Notification::make()
                ->title('S3上のファイルが見つかりません')
                ->body($path)
                ->danger()
                ->send();

            return null;
        }

        $filename = (string) ($entry['filename'] ?? basename($path));
        $mimeType = (string) ($entry['mime_type'] ?? 'application/pdf');

        return response()->streamDownload(
            fn () => print (Storage::disk($disk)->get($path)),
            $filename,
            ['Content-Type' => $mimeType]
        );
    }
}
