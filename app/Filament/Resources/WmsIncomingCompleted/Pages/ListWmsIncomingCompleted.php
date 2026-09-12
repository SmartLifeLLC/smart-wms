<?php

namespace App\Filament\Resources\WmsIncomingCompleted\Pages;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Filament\Concerns\HasStockSubqueries;
use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsIncomingCompleted\WmsIncomingCompletedResource;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsOrderIncomingSchedule;
use App\Services\AutoOrder\IncomingTransmissionService;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListWmsIncomingCompleted extends ListRecords
{
    use AdvancedTables;
    use HasStockSubqueries;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsIncomingCompletedResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('transmitPurchase')
                ->label(fn (): string => $this->getPurchaseTransmissionActionLabel())
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->modalHeading(fn (): string => $this->getPurchaseTransmissionActionLabel())
                ->modalDescription(fn (): string => $this->getPurchaseTransmissionTargetLabel().'の未送信入荷完了データをすべて基幹システムの仕入キューに登録します。同一の倉庫・仕入先・伝票番号・入荷日ごとに1伝票としてまとめられます。登録後はデータの修正ができなくなります。')
                ->requiresConfirmation()
                ->modalSubmitActionLabel('全送信')
                ->action(function () {
                    $this->transmitIncomingSchedules();
                }),
        ];
    }

    public function transmitIncomingSchedules(?array $scheduleIds = null): void
    {
        $warehouseId = $scheduleIds === null ? $this->getPurchaseTransmissionWarehouseId() : null;

        if ($scheduleIds === null && $warehouseId === null) {
            Notification::make()
                ->title('倉庫を選択してください')
                ->body('仕入データ全送信は倉庫別に実行します。倉庫タブを選択してから再実行してください。')
                ->warning()
                ->send();

            return;
        }

        try {
            $result = app(IncomingTransmissionService::class)
                ->transmitConfirmedIncomings($warehouseId, $scheduleIds);

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
        } catch (\Throwable $e) {
            Notification::make()
                ->title('登録エラー')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['warehouse', 'item', 'contractor.wmsSetting', 'location', 'orderCandidate', 'confirmedByUser', 'confirmedByPicker'])
                ->addSelect([
                    'computed_current_stock' => static::currentStockSubquery('wms_order_incoming_schedules'),
                    'computed_available_stock' => static::availableStockSubquery('wms_order_incoming_schedules'),
                    'computed_default_location' => static::defaultLocationSubquery('wms_order_incoming_schedules'),
                ])
            );
    }

    private function getPurchaseTransmissionWarehouseId(): ?int
    {
        $activeView = $this->activePresetView ?? null;

        if (is_string($activeView)) {
            if ($activeView === 'all') {
                return null;
            }

            if (preg_match('/^(?:wh|default)_(\d+)$/', $activeView, $matches)) {
                return (int) $matches[1];
            }
        }

        $warehouseId = auth()->user()?->getSelectedWarehouseId();

        if (! $warehouseId) {
            return null;
        }

        $warehouseData = $this->getWarehouseDataForPresetViews();
        $warehouseIds = collect($warehouseData['ids'])
            ->map(fn ($id): int => (int) $id)
            ->all();

        return in_array((int) $warehouseId, $warehouseIds, true) ? (int) $warehouseId : null;
    }

    private function getPurchaseTransmissionActionLabel(): string
    {
        return '仕入データ全送信';
    }

    private function getPurchaseTransmissionTargetLabel(): string
    {
        $warehouseId = $this->getPurchaseTransmissionWarehouseId();

        if ($warehouseId === null) {
            return '倉庫未選択';
        }

        $warehouseData = $this->getWarehouseDataForPresetViews();
        $warehouse = $warehouseData['warehouses']->firstWhere('id', $warehouseId)
            ?? Warehouse::find($warehouseId);

        return $warehouse?->name ?? "倉庫ID {$warehouseId}";
    }

    protected ?array $presetViewWarehouseData = null;

    protected function getWarehouseDataForPresetViews(): array
    {
        if ($this->presetViewWarehouseData !== null) {
            return $this->presetViewWarehouseData;
        }

        $cacheKey = 'incoming_completed_warehouses_'.auth()->id();
        $this->presetViewWarehouseData = cache()->remember($cacheKey, 30, function () {
            $warehouseIds = WmsOrderIncomingSchedule::query()
                ->where('status', IncomingScheduleStatus::CONFIRMED)
                ->withoutTransferSource()
                ->distinct()
                ->pluck('warehouse_id')
                ->map(fn ($id): int => (int) $id)
                ->toArray();

            $warehouse91Id = Warehouse::query()
                ->where('code', '91')
                ->value('id');

            if ($warehouse91Id) {
                $warehouseIds[] = (int) $warehouse91Id;
            }

            $warehouseIds = collect($warehouseIds)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all();

            $warehouses = Warehouse::whereIn('id', $warehouseIds)
                ->orderBy('code')
                ->get(['id', 'code', 'name']);

            return [
                'ids' => $warehouseIds,
                'warehouses' => $warehouses,
            ];
        });

        return $this->presetViewWarehouseData;
    }

    public function getPresetViews(): array
    {
        $userDefaultWarehouseId = auth()->user()?->getSelectedWarehouseId();

        $warehouseData = $this->getWarehouseDataForPresetViews();
        $warehouseIds = $warehouseData['ids'];
        $warehouses = $warehouseData['warehouses'];

        $hasDefaultWarehouse = $userDefaultWarehouseId && in_array($userDefaultWarehouseId, $warehouseIds);
        $defaultWarehouse = $hasDefaultWarehouse ? $warehouses->firstWhere('id', $userDefaultWarehouseId) : null;

        if ($defaultWarehouse) {
            $views = [
                'default' => PresetView::make()
                    ->modifyQueryUsing(fn (Builder $query) => $query->where('warehouse_id', $userDefaultWarehouseId))
                    ->favorite()
                    ->label($defaultWarehouse->name)
                    ->default(),
                'all' => PresetView::make()
                    ->favorite()
                    ->label('全て'),
            ];
        } else {
            $views = [
                'default' => PresetView::make()
                    ->favorite()
                    ->label('全て')
                    ->default(),
            ];
        }

        foreach ($warehouses as $warehouse) {
            if ($defaultWarehouse && $warehouse->id === $userDefaultWarehouseId) {
                continue;
            }
            $views["wh_{$warehouse->id}"] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('warehouse_id', $warehouse->id))
                ->favorite()
                ->label($warehouse->name);
        }

        return $views;
    }
}
