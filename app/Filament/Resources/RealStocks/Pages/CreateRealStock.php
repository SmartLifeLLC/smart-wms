<?php

namespace App\Filament\Resources\RealStocks\Pages;

use App\Filament\Resources\RealStocks\RealStockResource;
use App\Models\Sakemaru\Client;
use Filament\Resources\Pages\CreateRecord;

class CreateRealStock extends CreateRecord
{
    protected static string $resource = RealStockResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // client_id はNOT NULL・デフォルト値なし。クライアントは1件のみのため先頭を設定
        $data['client_id'] ??= Client::first()?->id;

        return $data;
    }
}
