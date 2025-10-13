<?php

namespace App\Filament\Resources\WmsReceiptInspections\Pages;

use App\Filament\Resources\WmsReceiptInspections\WmsReceiptInspectionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWmsReceiptInspection extends EditRecord
{
    protected static string $resource = WmsReceiptInspectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
