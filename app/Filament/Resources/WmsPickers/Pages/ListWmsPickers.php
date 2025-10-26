<?php

namespace App\Filament\Resources\WmsPickers\Pages;

use App\Filament\Resources\WmsPickers\WmsPickerResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListWmsPickers extends ListRecords
{
    protected static string $resource = WmsPickerResource::class;

    protected static ?string $title = 'ピッカー一覧';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('新規作成'),
        ];
    }
}
