<?php

namespace App\Filament\Resources\WarehouseContractors;

use App\Filament\Resources\WarehouseContractors\Pages\CreateWarehouseContractor;
use App\Filament\Resources\WarehouseContractors\Pages\EditWarehouseContractor;
use App\Filament\Resources\WarehouseContractors\Pages\ListWarehouseContractors;
use App\Filament\Resources\WarehouseContractors\Schemas\WarehouseContractorForm;
use App\Filament\Resources\WarehouseContractors\Tables\WarehouseContractorsTable;
use App\Models\Sakemaru\WarehouseContractor;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WarehouseContractorResource extends Resource
{
    protected static ?string $model = WarehouseContractor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    // Navigation settings
    public static function getNavigationGroup(): ?string
    {
        return 'マスタ管理';
    }

    public static function getNavigationLabel(): string
    {
        return '倉庫・仕入先';
    }

    public static function getModelLabel(): string
    {
        return '倉庫仕入先';
    }

    public static function getPluralModelLabel(): string
    {
        return '倉庫仕入先';
    }

    public static function getNavigationSort(): ?int
    {
        return 25;
    }

    public static function form(Schema $schema): Schema
    {
        return WarehouseContractorForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WarehouseContractorsTable::configure($table);
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
            'index' => ListWarehouseContractors::route('/'),
            'create' => CreateWarehouseContractor::route('/create'),
            'edit' => EditWarehouseContractor::route('/{record}/edit'),
        ];
    }
}
