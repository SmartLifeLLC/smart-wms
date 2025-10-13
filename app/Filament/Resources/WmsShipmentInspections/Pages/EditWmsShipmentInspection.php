<?php

namespace App\Filament\Resources\WmsShipmentInspections\Pages;

use App\Filament\Resources\WmsShipmentInspections\WmsShipmentInspectionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWmsShipmentInspection extends EditRecord
{
    protected static string $resource = WmsShipmentInspectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
