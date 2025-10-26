<?php

namespace App\Filament\Resources\WmsPickers\Pages;

use App\Filament\Resources\WmsPickers\WmsPickerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWmsPicker extends CreateRecord
{
    protected static string $resource = WmsPickerResource::class;

    protected static ?string $title = 'ピッカー新規作成';

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'ピッカーを作成しました';
    }
}
