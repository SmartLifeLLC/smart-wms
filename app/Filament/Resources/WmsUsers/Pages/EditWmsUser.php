<?php

namespace App\Filament\Resources\WmsUsers\Pages;

use App\Filament\Resources\WmsUsers\WmsUserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWmsUser extends EditRecord
{
    protected static string $resource = WmsUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
