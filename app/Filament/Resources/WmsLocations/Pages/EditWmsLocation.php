<?php

namespace App\Filament\Resources\WmsLocations\Pages;

use App\Filament\Resources\WmsLocations\WmsLocationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWmsLocation extends EditRecord
{
    protected static string $resource = WmsLocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
