<?php

namespace App\Filament\Resources\WmsPickingTasks\Pages;

use App\Filament\Resources\WmsPickingTasks\WmsPickingTaskResource;
use App\Models\WmsPickingTask;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\DB;

class ExecuteWmsPickingTask extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $resource = WmsPickingTaskResource::class;

    protected string $view = 'filament.resources.wms-picking-tasks.pages.execute-wms-picking-task';

    public WmsPickingTask $record;

    public array $items = [];

    public function mount(WmsPickingTask $record): void
    {
        $record->load([
            'pickingItemResults' => function ($query) {
                $query->with(['item', 'location'])
                    ->orderBy('walking_order', 'asc')
                    ->orderBy('item_id', 'asc');
            },
            'wave',
            'earning',
            'trade',
            'pickingArea',
            'warehouse',
            'picker'
        ]);
        $this->record = $record;
        // 商品データを配列に変換
        $this->items = $this->record->pickingItemResults->map(function ($item) {
            return [
                'id' => $item->id,
                'item_name' => $item->item_name_with_code ?? "商品{$item->item_id}",
                'location' => $item->location_display ?? '-',
                'ordered_qty' => (int) $item->ordered_qty,
                'ordered_qty_type_display' => $item->ordered_qty_type_display,
                'planned_qty' => (int) $item->planned_qty,
                'planned_qty_type_display' => $item->planned_qty_type_display,
                'picked_qty' => (int) $item->picked_qty,
                'picked_qty_type_display' => $item->picked_qty_type_display,
                'shortage_qty' => (int) $item->shortage_qty,
                'status' => $item->status,
            ];
        })->toArray();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('force_ship')
                ->label('強制出荷（管理者）')
                ->color('warning')
                ->icon('heroicon-o-truck')
                ->requiresConfirmation()
                ->modalHeading('強制出荷確認')
                ->modalDescription('すべての商品のピッキング数を予定数に自動設定し、出荷可能状態にします。テスト用・緊急用の機能です。')
                ->action(function () {
                    $this->forceShipTask();
                })
                ->visible(fn () => $this->record->status !== 'COMPLETED'),

            Action::make('complete')
                ->label('ピッキング完了')
                ->color('success')
                ->icon('heroicon-o-check-circle')
                ->requiresConfirmation()
                ->modalHeading('ピッキング完了確認')
                ->modalDescription('このタスクのピッキングを完了しますか？')
                ->action(function () {
                    $this->completeTask();
                })
                ->visible(fn () => $this->record->status !== 'COMPLETED'),

            Action::make('back')
                ->label('一覧に戻る')
                ->url(WmsPickingTaskResource::getUrl('index'))
                ->color('gray'),
        ];
    }

    public function updateItem($itemId, $field, $value): void
    {
        DB::connection('sakemaru')->transaction(function () use ($itemId, $field, $value) {
            $item = $this->record->pickingItemResults()->find($itemId);

            if (!$item) {
                Notification::make()
                    ->title('エラー')
                    ->body('商品が見つかりません')
                    ->danger()
                    ->send();
                return;
            }

            // ピック数量更新（picked_qtyのみ受け付ける）
            if ($field === 'picked_qty') {
                // 整数に変換
                $pickedQty = (int) $value;
                $originalValue = $pickedQty;

                // 予定数を超える場合は予定数に補正
                if ($pickedQty > $item->planned_qty) {
                    $pickedQty = $item->planned_qty;
                    Notification::make()
                        ->title('数量を補正しました')
                        ->body("入力値（{$originalValue}）が予定数を超えているため、予定数（{$item->planned_qty}）に補正しました")
                        ->warning()
                        ->send();
                }

                // 0未満の場合は0に補正
                if ($pickedQty < 0) {
                    $pickedQty = 0;
                    Notification::make()
                        ->title('数量を補正しました')
                        ->body('負の値は入力できません。0に補正しました')
                        ->warning()
                        ->send();
                }

                $shortageQty = max(0, $item->planned_qty - $pickedQty);

                $item->update([
                    'picked_qty' => $pickedQty,
                    'shortage_qty' => $shortageQty,
                    'status' => $shortageQty > 0 ? 'SHORTAGE' : 'COMPLETED',
                    'picked_at' => now(),
                ]);

                // 補正がない場合のみ成功メッセージを表示
                if ($originalValue === $pickedQty) {
                    Notification::make()
                        ->title('更新しました')
                        ->body("{$item->item_name_with_code} を更新しました")
                        ->success()
                        ->send();
                }
            }

            // データを再読み込み
            $this->record->refresh();
            $this->record->load([
                'pickingItemResults' => function ($query) {
                    $query->with(['item', 'location'])
                        ->orderBy('walking_order', 'asc')
                        ->orderBy('item_id', 'asc');
                }
            ]);

            $this->items = $this->record->pickingItemResults->map(function ($item) {
                return [
                    'id' => $item->id,
                    'item_name' => $item->item_name_with_code ?? "商品{$item->item_id}",
                    'location' => $item->location_display ?? '-',
                    'ordered_qty' => (int) $item->ordered_qty,
                    'ordered_qty_type_display' => $item->ordered_qty_type_display,
                    'planned_qty' => (int) $item->planned_qty,
                    'planned_qty_type_display' => $item->planned_qty_type_display,
                    'picked_qty' => (int) $item->picked_qty,
                    'picked_qty_type_display' => $item->picked_qty_type_display,
                    'shortage_qty' => (int) $item->shortage_qty,
                    'status' => $item->status,
                ];
            })->toArray();
        });
    }

    public function forceShipTask(): void
    {
        DB::connection('sakemaru')->transaction(function () {
            // すべての商品のピッキング数を予定数に自動設定
            $items = $this->record->pickingItemResults;
            $updatedCount = 0;

            foreach ($items as $item) {
                $item->update([
                    'picked_qty' => $item->planned_qty,
                    'shortage_qty' => 0,
                    'status' => 'COMPLETED',
                    'picked_at' => now(),
                ]);
                $updatedCount++;
            }

            // タスクを完了
            $this->record->update([
                'status' => 'COMPLETED',
                'completed_at' => now(),
            ]);

            // 伝票のピッキングステータスを更新
            if ($this->record->earning) {
                DB::connection('sakemaru')
                    ->table('earnings')
                    ->where('id', $this->record->earning_id)
                    ->update([
                        'picking_status' => 'COMPLETED',
                        'updated_at' => now(),
                    ]);
            }

            Notification::make()
                ->title('強制出荷しました')
                ->body("{$updatedCount}件の商品を自動完了し、出荷可能状態にしました")
                ->success()
                ->send();

            $this->redirect(WmsPickingTaskResource::getUrl('index'));
        });
    }

    public function completeTask(): void
    {
        DB::connection('sakemaru')->transaction(function () {
            // すべての商品がCOMPLETEDまたはSHORTAGEか確認
            $pendingItems = $this->record->pickingItemResults()
                ->whereIn('status', ['PICKING'])
                ->count();

            if ($pendingItems > 0) {
                Notification::make()
                    ->title('完了できません')
                    ->body("未完了の商品が{$pendingItems}件あります")
                    ->warning()
                    ->send();
                return;
            }

            // タスクを完了
            $this->record->update([
                'status' => 'COMPLETED',
                'completed_at' => now(),
            ]);

            // 伝票のピッキングステータスを更新
            if ($this->record->earning) {
                DB::connection('sakemaru')
                    ->table('earnings')
                    ->where('id', $this->record->earning_id)
                    ->update([
                        'picking_status' => 'COMPLETED',
                        'updated_at' => now(),
                    ]);
            }

            Notification::make()
                ->title('ピッキング完了')
                ->body('タスクが完了しました')
                ->success()
                ->send();

            $this->redirect(WmsPickingTaskResource::getUrl('index'));
        });
    }

    public function getTitle(): string
    {
        $waveCode = $this->record->wave->wave_code ?? 'Wave';
        $serialId = $this->record->trade->serial_id ?? 'N/A';
        return "ピッキング実行: {$waveCode} - 伝票 {$serialId}";
    }

    public static function canAccess(array $parameters = []): bool
    {
        return true; // Allow all authenticated users to access
    }
}
