<?php

namespace App\Filament\Resources\WmsPickers\Pages;

use App\Filament\Resources\WmsPickers\WmsPickerResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditWmsPicker extends EditRecord
{
    protected static string $resource = WmsPickerResource::class;

    protected static ?string $title = 'ピッカー編集';

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('削除'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'ピッカーを更新しました';
    }
}
