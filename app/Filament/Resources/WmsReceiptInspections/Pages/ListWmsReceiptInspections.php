<?php

namespace App\Filament\Resources\WmsReceiptInspections\Pages;

use App\Filament\Resources\WmsReceiptInspections\WmsReceiptInspectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWmsReceiptInspections extends ListRecords
{
    protected static string $resource = WmsReceiptInspectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
