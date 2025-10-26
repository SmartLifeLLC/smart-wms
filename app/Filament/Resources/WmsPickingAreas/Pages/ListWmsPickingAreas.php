<?php

namespace App\Filament\Resources\WmsPickingAreas\Pages;

use App\Filament\Resources\WmsPickingAreas\WmsPickingAreaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWmsPickingAreas extends ListRecords
{
    protected static string $resource = WmsPickingAreaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
