<?php

namespace App\Filament\Resources\WmsJxEosLines\Pages;

use App\Filament\Resources\WmsJxEosLines\WmsJxEosLineResource;
use Filament\Resources\Pages\ViewRecord;

class ViewWmsJxEosLine extends ViewRecord
{
    protected static string $resource = WmsJxEosLineResource::class;

    public function getTitle(): string
    {
        return 'EOS受信明細詳細';
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->record->load([
            'importBatch.detectedContractor',
            'importBatch.detectedJxSetting',
            'importBatch.transmissionLog',
            'document',
            'slip',
        ]);
    }
}
