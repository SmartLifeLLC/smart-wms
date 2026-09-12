<?php

namespace App\Filament\Resources\WmsShortageAllocations\Pages;

use App\Filament\Resources\WmsShortageAllocations\Tables\WmsShortageAllocationsFinishedTable;
use App\Filament\Resources\WmsShortageAllocations\WmsShortageAllocationResource;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsShortageAllocation;
use App\Services\QuantityUpdate\AllocationSyncService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class ListHistoryWmsShortageAllocations extends ListRecords
{
    protected static string $resource = WmsShortageAllocationResource::class;

    protected static ?string $title = '横持ち出荷履歴';

    protected function getHeaderActions(): array
    {
        return [
            // No actions needed
        ];
    }

    public function table(Table $table): Table
    {
        // Use the finished table configuration
        $table = WmsShortageAllocationsFinishedTable::configure($table);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->where('is_finished', true)
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
                $this->getSyncAllocationsAction(),
            ]);
    }

    protected function getSyncAllocationsAction(): Action
    {
        return Action::make('syncAllocations')
            ->label('手動同期')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
            ->modalFooterActionsAlignment(Alignment::End)
            ->modalHeading('欠品データ手動同期')
            ->schema(fn (): array => [
                Placeholder::make('sync_info')
                    ->hiddenLabel()
                    ->content(new HtmlString($this->buildSyncInfoHtml())),
            ])
            ->modalSubmitAction(function ($action) {
                if ($this->getUnsyncedAllocationCount() === 0) {
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
            ->action(function (): void {
                $warehouseIds = $this->getSyncWarehouseIds();
                if (empty($warehouseIds)) {
                    Notification::make()
                        ->title('同期対象のデータがありません')
                        ->info()
                        ->send();

                    return;
                }

                $syncService = app(AllocationSyncService::class);
                $syncedCount = 0;
                $queueCreated = 0;
                $skipped = 0;
                $errors = [];

                foreach ($warehouseIds as $warehouseId) {
                    $result = $syncService->syncByWarehouse((int) $warehouseId);
                    $syncedCount += $result['syncedCount'];
                    $queueCreated += $result['queueCreated'];
                    $skipped += $result['skipped'];
                    $errors = array_merge($errors, $result['errors']);
                }

                $message = "同期件数: {$syncedCount}件\nキュー作成: {$queueCreated}件";
                if ($skipped > 0) {
                    $message .= "\nスキップ: {$skipped}件";
                }

                if (! empty($errors)) {
                    Notification::make()
                        ->title('データ同期が完了しました（一部エラーあり）')
                        ->body($message."\nエラー: ".count($errors).'件')
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title($syncedCount > 0 ? 'データ同期が完了しました' : '同期対象のデータがありません')
                    ->body($message)
                    ->success()
                    ->send();
            });
    }

    protected function buildSyncInfoHtml(): string
    {
        $count = $this->getUnsyncedAllocationCount();

        if ($count === 0) {
            return '<div class="py-4 text-center text-sm text-gray-400">同期が必要な欠品データはありません。</div>';
        }

        $warehouses = Warehouse::query()
            ->whereIn('id', $this->getSyncWarehouseIds())
            ->orderBy('code')
            ->get(['code', 'name'])
            ->map(fn (Warehouse $warehouse): string => '['.$warehouse->code.'] '.$warehouse->name)
            ->implode(' / ');

        return '<div class="py-4 text-center">'
            .'<div class="text-base font-semibold text-gray-700 dark:text-gray-200">'.e($warehouses ?: '対象倉庫').'</div>'
            .'<div class="mt-3 text-lg font-bold text-orange-600 dark:text-orange-400">未同期の欠品データが <span class="text-2xl">'.$count.'</span> 件あります。</div>'
            .'<div class="mt-2 text-sm text-gray-500 dark:text-gray-400">履歴画面の手動同期です。出荷日がシステム日付以外のデータも対象になります。</div>'
            .'<div class="mt-1 text-sm text-gray-500 dark:text-gray-400">ai-coreへ数量修正データを同期しますか？</div>'
            .'</div>';
    }

    protected function getUnsyncedAllocationCount(): int
    {
        return WmsShortageAllocation::needingSync()
            ->whereIn('target_warehouse_id', $this->getSyncWarehouseIds())
            ->count();
    }

    protected function getSyncWarehouseIds(): array
    {
        return WmsShortageAllocation::needingSync()
            ->distinct()
            ->pluck('target_warehouse_id')
            ->filter()
            ->values()
            ->all();
    }
}
