<?php

namespace App\Filament\Resources\Waves\Pages;

use App\Filament\Resources\Waves\WaveResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWave extends EditRecord
{
    protected static string $resource = WaveResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
