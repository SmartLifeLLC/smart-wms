<?php

namespace App\Filament\Resources\WmsShipmentInspections\Pages;

use App\Filament\Resources\WmsShipmentInspections\WmsShipmentInspectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWmsShipmentInspections extends ListRecords
{
    protected static string $resource = WmsShipmentInspectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
