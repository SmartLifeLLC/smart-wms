<?php

namespace App\Filament\Resources\WaveSettings;

use App\Filament\Resources\WaveSettings\Pages\CreateWaveSetting;
use App\Filament\Resources\WaveSettings\Pages\EditWaveSetting;
use App\Filament\Resources\WaveSettings\Pages\ListWaveSettings;
use App\Filament\Resources\WaveSettings\Schemas\WaveSettingForm;
use App\Filament\Resources\WaveSettings\Tables\WaveSettingsTable;
use App\Models\WaveSetting;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WaveSettingResource extends Resource
{
    protected static ?string $model = WaveSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return WaveSettingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WaveSettingsTable::configure($table);
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
            'index' => ListWaveSettings::route('/'),
            'create' => CreateWaveSetting::route('/create'),
            'edit' => EditWaveSetting::route('/{record}/edit'),
        ];
    }
}
