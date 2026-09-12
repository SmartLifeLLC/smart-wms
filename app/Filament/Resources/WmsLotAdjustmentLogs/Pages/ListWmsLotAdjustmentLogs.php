<?php

namespace App\Filament\Resources\WmsLotAdjustmentLogs\Pages;

use App\Filament\Resources\WmsLotAdjustmentLogs\WmsLotAdjustmentLogResource;
use Filament\Resources\Pages\ListRecords;

class ListWmsLotAdjustmentLogs extends ListRecords
{
    protected static string $resource = WmsLotAdjustmentLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
