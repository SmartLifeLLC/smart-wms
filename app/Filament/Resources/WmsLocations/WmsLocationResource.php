<?php

namespace App\Filament\Resources\WmsLocations;

use App\Filament\Resources\WmsLocations\Pages\CreateWmsLocation;
use App\Filament\Resources\WmsLocations\Pages\EditWmsLocation;
use App\Filament\Resources\WmsLocations\Pages\ListWmsLocations;
use App\Filament\Resources\WmsLocations\Schemas\WmsLocationForm;
use App\Filament\Resources\WmsLocations\Tables\WmsLocationsTable;
use App\Models\WmsLocation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WmsLocationResource extends Resource
{
    protected static ?string $model = WmsLocation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    // Navigation settings
    public static function getNavigationGroup(): ?string
    {
        return 'WMS管理';
    }

    public static function getNavigationLabel(): string
    {
        return 'ロケーション属性';
    }

    public static function getModelLabel(): string
    {
        return 'WMSロケーション';
    }

    public static function getPluralModelLabel(): string
    {
        return 'WMSロケーション';
    }

    public static function getNavigationSort(): ?int
    {
        return 10;
    }

    public static function form(Schema $schema): Schema
    {
        return WmsLocationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WmsLocationsTable::configure($table);
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
            'index' => ListWmsLocations::route('/'),
            'create' => CreateWmsLocation::route('/create'),
            'edit' => EditWmsLocation::route('/{record}/edit'),
        ];
    }
}
