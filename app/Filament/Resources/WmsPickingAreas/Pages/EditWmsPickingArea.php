<?php

namespace App\Filament\Resources\WmsPickingAreas\Pages;

use App\Filament\Resources\WmsPickingAreas\WmsPickingAreaResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWmsPickingArea extends EditRecord
{
    protected static string $resource = WmsPickingAreaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
