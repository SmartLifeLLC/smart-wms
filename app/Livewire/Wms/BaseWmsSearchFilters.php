<?php

namespace App\Livewire\Wms;

use App\Models\Sakemaru\Warehouse;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Livewire\Component;

abstract class BaseWmsSearchFilters extends Component implements HasForms
{
    use InteractsWithForms;

    public ?array $data = [];

    abstract protected function getDateLabel(): string;

    abstract protected function getAdditionalFilters(): array;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(['sm' => 1, 'lg' => 4])
                    ->schema(array_merge([
                        TextInput::make('item_name')
                            ->hiddenLabel()
                            ->placeholder('商品名で検索')
                            ->live(onBlur: true),

                        TextInput::make('item_code')
                            ->hiddenLabel()
                            ->placeholder('商品コード')
                            ->live(onBlur: true),

                        TextInput::make('jancode')
                            ->hiddenLabel()
                            ->placeholder('JANコード')
                            ->live(onBlur: true),

                        Select::make('warehouse_id')
                            ->hiddenLabel()
                            ->placeholder('倉庫を選択')
                            ->options($this->getWarehouseOptions())
                            ->searchable()
                            ->live(),

                        Grid::make(2)->schema([
                            DatePicker::make('date_from')
                                ->hiddenLabel()
                                ->placeholder($this->getDateLabel() . '（開始）')
                                ->live(),

                            DatePicker::make('date_to')
                                ->hiddenLabel()
                                ->placeholder($this->getDateLabel() . '（終了）')
                                ->live(),
                        ])->columnSpan(2),
                    ], $this->getAdditionalFilters(), [
                        Actions::make([
                            Action::make('search')
                                ->label('検索 (ENTER)')
                                ->action('performSearch')
                                ->color('primary')
                                ->keyBindings(['enter']),

                            Action::make('clear')
                                ->label('クリア (ESC)')
                                ->action('clearFilters')
                                ->color('gray')
                                ->keyBindings(['escape']),
                        ])
                            ->alignEnd()
                            ->columnSpan(4),
                    ])),
            ])
            ->statePath('data');
    }

    public function performSearch(): void
    {
        $this->dispatch('search-updated', filters: $this->data);
    }

    public function clearFilters(): void
    {
        $this->data = [];
        $this->form->fill();
        $this->dispatch('search-updated', filters: []);
    }

    public function updatedData(): void
    {
        $this->performSearch();
    }

    protected function getWarehouseOptions(): array
    {
        return Warehouse::where('is_active', true)
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(function ($warehouse) {
                return [
                    $warehouse->id => "({$warehouse->code}) {$warehouse->name}"
                ];
            })
            ->toArray();
    }
}
