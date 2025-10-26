<?php

namespace App\Filament\Resources\WarehouseContractors\Pages;

use App\Filament\Resources\WarehouseContractors\WarehouseContractorResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWarehouseContractor extends EditRecord
{
    protected static string $resource = WarehouseContractorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
