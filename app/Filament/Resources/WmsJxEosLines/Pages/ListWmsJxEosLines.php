<?php

namespace App\Filament\Resources\WmsJxEosLines\Pages;

use App\Filament\Resources\WmsJxEosLines\WmsJxEosLineResource;
use Filament\Resources\Pages\ListRecords;

class ListWmsJxEosLines extends ListRecords
{
    protected static string $resource = WmsJxEosLineResource::class;

    public function getTitle(): string
    {
        $batchId = request()->integer('batch_id');

        return $batchId ? "EOS受信明細 バッチ#{$batchId}" : 'EOS受信明細';
    }
}
