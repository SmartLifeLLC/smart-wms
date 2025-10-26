<?php

namespace App\Filament\Resources\WaveSettings\Pages;

use App\Filament\Resources\WaveSettings\WaveSettingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWaveSettings extends ListRecords
{
    protected static string $resource = WaveSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
