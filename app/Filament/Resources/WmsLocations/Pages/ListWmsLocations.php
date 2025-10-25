<?php

namespace App\Filament\Resources\WmsLocations\Pages;

use App\Filament\Resources\WmsLocations\WmsLocationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWmsLocations extends ListRecords
{
    protected static string $resource = WmsLocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
