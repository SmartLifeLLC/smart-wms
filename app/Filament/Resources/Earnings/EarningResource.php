<?php

namespace App\Filament\Resources\Earnings;

use App\Filament\Resources\Earnings\Pages\CreateEarning;
use App\Filament\Resources\Earnings\Pages\EditEarning;
use App\Filament\Resources\Earnings\Pages\ListEarnings;
use App\Filament\Resources\Earnings\Schemas\EarningForm;
use App\Filament\Resources\Earnings\Tables\EarningsTable;
use App\Models\Sakemaru\Earning;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class EarningResource extends Resource
{
    protected static ?string $model = Earning::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    public static function getNavigationGroup(): ?string
    {
        return '出荷';
    }

    public static function getNavigationLabel(): string
    {
        return '予定';
    }

    public static function getModelLabel(): string
    {
        return '出荷予定';
    }

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return EarningForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EarningsTable::configure($table);
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
            'index' => ListEarnings::route('/'),
            'create' => CreateEarning::route('/create'),
            'edit' => EditEarning::route('/{record}/edit'),
        ];
    }
}
