<?php

namespace App\Filament\Resources\RealStocks\Pages;

use App\Filament\Resources\RealStocks\RealStockResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRealStock extends EditRecord
{
    protected static string $resource = RealStockResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
