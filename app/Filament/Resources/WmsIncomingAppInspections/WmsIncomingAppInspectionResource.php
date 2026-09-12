<?php

namespace App\Filament\Resources\WmsIncomingAppInspections;

use App\Enums\EMenu;
use App\Filament\Resources\WmsIncomingAppInspections\Pages\ListWmsIncomingAppInspections;
use App\Filament\Resources\WmsIncomingAppInspections\Tables\WmsIncomingAppInspectionsTable;
use App\Filament\Support\AdminResource;
use App\Models\WmsIncomingAppInspectionDetail;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WmsIncomingAppInspectionResource extends AdminResource
{
    protected static ?string $model = WmsIncomingAppInspectionDetail::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $slug = 'wms-incoming-app-inspections';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_INCOMING_APP_INSPECTIONS->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_INCOMING_APP_INSPECTIONS->label();
    }

    public static function getModelLabel(): string
    {
        return 'アプリ入荷検品履歴';
    }

    public static function getPluralModelLabel(): string
    {
        return 'アプリ入荷検品履歴';
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_INCOMING_APP_INSPECTIONS->sort();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'batch',
                'warehouse',
                'item',
                'contractor',
                'incomingSchedule',
                'linkedConfirmedSchedule',
                'createdSchedule',
                'location',
            ]);
    }

    public static function table(Table $table): Table
    {
        return WmsIncomingAppInspectionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsIncomingAppInspections::route('/'),
        ];
    }
}
