<?php

namespace App\Filament\Resources\WmsUsers\Pages;

use App\Filament\Resources\WmsUsers\WmsUserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWmsUsers extends ListRecords
{
    protected static string $resource = WmsUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
