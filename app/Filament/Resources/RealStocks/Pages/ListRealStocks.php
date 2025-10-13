<?php

namespace App\Filament\Resources\RealStocks\Pages;

use App\Filament\Resources\RealStocks\RealStockResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRealStocks extends ListRecords
{
    protected static string $resource = RealStockResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
