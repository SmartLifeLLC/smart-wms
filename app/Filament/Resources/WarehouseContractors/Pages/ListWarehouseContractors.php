<?php

namespace App\Filament\Resources\WarehouseContractors\Pages;

use App\Filament\Resources\WarehouseContractors\WarehouseContractorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWarehouseContractors extends ListRecords
{
    protected static string $resource = WarehouseContractorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
