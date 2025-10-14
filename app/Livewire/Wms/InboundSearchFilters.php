<?php

namespace App\Livewire\Wms;

use Filament\Forms\Components\Select;

class InboundSearchFilters extends BaseWmsSearchFilters
{
    protected function getDateLabel(): string
    {
        return '入荷予定日';
    }

    protected function getAdditionalFilters(): array
    {
        return [
            Select::make('inspection_status')
                ->hiddenLabel()
                ->placeholder('検品ステータスを選択')
                ->multiple()
                ->options([
                    'pending' => '未検品',
                    'in_progress' => '検品中',
                    'completed' => '検品完了',
                ])
                ->live(),
        ];
    }

    public function render()
    {
        return view('livewire.wms.wms-search-filters');
    }
}
