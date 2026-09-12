<?php

namespace App\Filament\Resources\WmsIncomingCompletedSummary;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\EMenu;
use App\Filament\Resources\WmsIncomingCompletedSummary\Pages\ListWmsIncomingCompletedSummary;
use App\Filament\Resources\WmsIncomingCompletedSummary\Tables\WmsIncomingCompletedSummaryTable;
use App\Filament\Support\AdminResource;
use App\Models\WmsOrderIncomingSchedule;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WmsIncomingCompletedSummaryResource extends AdminResource
{
    protected static ?string $model = WmsOrderIncomingSchedule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?string $slug = 'wms-incoming-completed-summary';

    protected static string $permissionResource = 'wms-incoming-completed';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_INCOMING_COMPLETED_SUMMARY->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_INCOMING_COMPLETED_SUMMARY->label();
    }

    public static function getModelLabel(): string
    {
        return '入荷完了サマリー';
    }

    public static function getPluralModelLabel(): string
    {
        return '入荷完了サマリー';
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_INCOMING_COMPLETED_SUMMARY->sort();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('status', IncomingScheduleStatus::CONFIRMED)
            ->withoutTransferSource()
            ->with([
                'warehouse',
                'contractor',
            ]);
    }

    public static function table(Table $table): Table
    {
        return WmsIncomingCompletedSummaryTable::configure($table);
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
            'index' => ListWmsIncomingCompletedSummary::route('/'),
        ];
    }
}
