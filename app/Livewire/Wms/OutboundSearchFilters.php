<?php

namespace App\Livewire\Wms;

use Filament\Forms\Components\Select;

class OutboundSearchFilters extends BaseWmsSearchFilters
{
    protected function getDateLabel(): string
    {
        return '出荷予定日';
    }

    protected function getAdditionalFilters(): array
    {
        return [
            Select::make('status')
                ->hiddenLabel()
                ->placeholder('出荷ステータスを選択')
                ->multiple()
                ->options([
                    'pending' => '出荷前',
                    'reserved' => '引き当て済み',
                    'picking' => 'ピッキング中',
                    'completed' => '出荷完了',
                ])
                ->live(),
        ];
    }

    public function render()
    {
        return view('livewire.wms.wms-search-filters');
    }
}
