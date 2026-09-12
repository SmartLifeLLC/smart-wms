<?php

namespace App\Filament\Resources\WmsIncomingCompleted;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\EMenu;
use App\Filament\Resources\WmsIncomingCompleted\Pages\ListWmsIncomingCompleted;
use App\Filament\Resources\WmsIncomingCompleted\Tables\WmsIncomingCompletedTable;
use App\Filament\Support\AdminResource;
use App\Models\WmsOrderIncomingSchedule;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WmsIncomingCompletedResource extends AdminResource
{
    protected static ?string $model = WmsOrderIncomingSchedule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    protected static ?string $slug = 'wms-incoming-completed';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_INCOMING_COMPLETED->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_INCOMING_COMPLETED->label();
    }

    public static function getModelLabel(): string
    {
        return '入荷完了';
    }

    public static function getPluralModelLabel(): string
    {
        return '入荷完了';
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_INCOMING_COMPLETED->sort();
    }

    public static function getEloquentQuery(): Builder
    {
        // 入庫完了（CONFIRMED）のみ表示
        return parent::getEloquentQuery()
            ->where('status', IncomingScheduleStatus::CONFIRMED)
            ->withoutTransferSource()
            ->with([
                'warehouse',
                'item',
                'contractor.wmsSetting',
                'supplier',
            ]);
    }

    public static function table(Table $table): Table
    {
        return WmsIncomingCompletedTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsIncomingCompleted::route('/'),
        ];
    }
}
