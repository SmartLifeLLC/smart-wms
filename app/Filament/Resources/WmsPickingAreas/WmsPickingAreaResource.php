<?php

namespace App\Filament\Resources\WmsPickingAreas;

use App\Filament\Resources\WmsPickingAreas\Pages\CreateWmsPickingArea;
use App\Filament\Resources\WmsPickingAreas\Pages\EditWmsPickingArea;
use App\Filament\Resources\WmsPickingAreas\Pages\ListWmsPickingAreas;
use App\Filament\Resources\WmsPickingAreas\Schemas\WmsPickingAreaForm;
use App\Filament\Resources\WmsPickingAreas\Tables\WmsPickingAreasTable;
use App\Models\WmsPickingArea;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class WmsPickingAreaResource extends Resource
{
    protected static ?string $model = WmsPickingArea::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'ピッキングエリア';

    protected static ?string $modelLabel = 'ピッキングエリア';

    protected static ?string $pluralModelLabel = 'ピッキングエリア';

    protected static UnitEnum|string|null $navigationGroup = 'WMS設定';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return WmsPickingAreaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WmsPickingAreasTable::configure($table);
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
            'index' => ListWmsPickingAreas::route('/'),
            'create' => CreateWmsPickingArea::route('/create'),
            'edit' => EditWmsPickingArea::route('/{record}/edit'),
        ];
    }
}
