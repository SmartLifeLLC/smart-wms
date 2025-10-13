<?php

namespace App\Filament\Resources\WmsShipmentInspections;

use App\Filament\Resources\WmsShipmentInspections\Pages\CreateWmsShipmentInspection;
use App\Filament\Resources\WmsShipmentInspections\Pages\EditWmsShipmentInspection;
use App\Filament\Resources\WmsShipmentInspections\Pages\ListWmsShipmentInspections;
use App\Filament\Resources\WmsShipmentInspections\Schemas\WmsShipmentInspectionForm;
use App\Filament\Resources\WmsShipmentInspections\Tables\WmsShipmentInspectionsTable;
use App\Models\WmsShipmentInspection;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WmsShipmentInspectionResource extends Resource
{
    protected static ?string $model = WmsShipmentInspection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    public static function getNavigationGroup(): ?string
    {
        return '出荷';
    }

    public static function getNavigationLabel(): string
    {
        return '検品';
    }

    public static function getModelLabel(): string
    {
        return '出荷検品';
    }

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return WmsShipmentInspectionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WmsShipmentInspectionsTable::configure($table);
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
            'index' => ListWmsShipmentInspections::route('/'),
            'create' => CreateWmsShipmentInspection::route('/create'),
            'edit' => EditWmsShipmentInspection::route('/{record}/edit'),
        ];
    }
}
